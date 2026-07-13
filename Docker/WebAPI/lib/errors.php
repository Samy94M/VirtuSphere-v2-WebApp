<?php

declare(strict_types=1);

function virtusphere_install_error_handlers(): void
{
    static $installed = false;
    if ($installed) {
        return;
    }

    $installed = true;
    error_reporting(E_ALL);

    set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
        if (error_reporting() === 0 || (error_reporting() & $severity) === 0) {
            return false;
        }

        throw new ErrorException($message, 0, $severity, $file, $line);
    });

    set_exception_handler(static function (Throwable $exception): void {
        virtusphere_handle_uncaught_error($exception);
    });

    register_shutdown_function(static function (): void {
        $error = error_get_last();
        if ($error === null || !in_array((int) $error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
            return;
        }

        virtusphere_handle_uncaught_error(new ErrorException(
            (string) $error['message'],
            0,
            (int) $error['type'],
            (string) $error['file'],
            (int) $error['line']
        ), true);
    });
}

/**
 * Which shape an uncaught error takes on the wire. The portal renders HTML; the
 * machine API scripts declare 'json' (in lib/machine_api.php) because MECM,
 * PowerShell and Ansible parse the body. Without this, an error thrown before a
 * script's own try/catch (a dead database, for instance, which the IP allowlist
 * lookup hits first) reached the client as an HTML page, so the integration saw
 * a JSON parse failure instead of a server error it could report.
 *
 * Call with no argument to read the current mode.
 */
function virtusphere_error_response_mode(?string $mode = null): string
{
    static $current = 'html';

    if ($mode !== null) {
        $current = $mode === 'json' ? 'json' : 'html';
    }

    return $current;
}

function virtusphere_log_dir(): string
{
    return dirname(__DIR__) . DIRECTORY_SEPARATOR . 'logs';
}

function virtusphere_error_log_path(): string
{
    return virtusphere_log_dir() . DIRECTORY_SEPARATOR . 'error.log';
}

function virtusphere_php_engine_error_log_path(): ?string
{
    $configured = trim((string) ini_get('error_log'));
    if ($configured === '' || strtolower($configured) === 'syslog') {
        return null;
    }

    if (!str_starts_with($configured, DIRECTORY_SEPARATOR)) {
        $configured = getcwd() . DIRECTORY_SEPARATOR . $configured;
    }

    return str_starts_with($configured, virtusphere_log_dir() . DIRECTORY_SEPARATOR) ? $configured : null;
}

function virtusphere_assert_log_dir_writable(): void
{
    $dir = virtusphere_log_dir();
    if (!is_dir($dir) && !mkdir($dir, 0777, true) && !is_dir($dir)) {
        throw new RuntimeException('Log directory cannot be created: ' . $dir);
    }

    virtusphere_try_chmod($dir, 0777, 0007);
    if (!is_writable($dir)) {
        throw new RuntimeException('Log directory is not writable: ' . $dir);
    }

    virtusphere_prepare_shared_log_file(virtusphere_error_log_path());
    $phpErrorLog = virtusphere_php_engine_error_log_path();
    if ($phpErrorLog !== null) {
        virtusphere_prepare_shared_log_file($phpErrorLog);
    }
}

function virtusphere_prepare_shared_log_file(string $path): void
{
    if (!is_file($path)) {
        $created = touch($path);
        if (!$created) {
            throw new RuntimeException('Log file cannot be created: ' . $path);
        }
    }

    virtusphere_try_chmod($path, 0666, 0006);
    if (!is_writable($path)) {
        throw new RuntimeException('Log file is not writable: ' . $path);
    }
}

function virtusphere_try_chmod(string $path, int $mode, int $requiredBits): void
{
    $perms = fileperms($path);
    if ($perms !== false && ($perms & $requiredBits) === $requiredBits) {
        return;
    }

    try {
        chmod($path, $mode);
    } catch (Throwable) {
        // Permission changes are best effort. Writability is checked by the caller.
    }
}

function virtusphere_error_reference(): string
{
    try {
        return bin2hex(random_bytes(4));
    } catch (Throwable) {
        return substr(str_replace('.', '', uniqid('', true)), -8);
    }
}

function virtusphere_handle_uncaught_error(Throwable $exception, bool $fromShutdown = false): void
{
    static $handling = false;

    if ($handling) {
        virtusphere_fallback_error_log('Recursive error handler failure: ' . $exception->getMessage());
        virtusphere_exit_after_recursive_error();
    }

    $handling = true;
    $refId = virtusphere_error_reference();
    $context = virtusphere_error_context($exception, $refId, $fromShutdown);

    try {
        virtusphere_write_error_log($context);
    } catch (Throwable $logException) {
        virtusphere_fallback_error_log('Error log write failed: ' . $logException->getMessage());
        virtusphere_fallback_error_log($context);
    }

    virtusphere_audit_uncaught_error($exception, $refId);

    if (PHP_SAPI === 'cli') {
        fwrite(STDERR, virtusphere_cli_error_text($exception, $refId));
        exit(1);
    }

    if (virtusphere_error_response_mode() === 'json') {
        virtusphere_render_error_json($exception, $refId);
        exit;
    }

    virtusphere_render_error_page($exception, $refId);
    exit;
}

/**
 * The machine-API counterpart of virtusphere_render_error_page(): same generic
 * message and reference, same debug gating, but in the envelope the integration
 * clients already parse ({"error": ...}).
 */
function virtusphere_render_error_json(Throwable $exception, string $refId): void
{
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
    }

    $payload = [
        'error' => 'Internal server error',
        'reference' => $refId,
    ];
    if (virtusphere_debug_enabled()) {
        $payload['class'] = $exception::class;
        $payload['message'] = $exception->getMessage();
        $payload['file'] = $exception->getFile() . ':' . $exception->getLine();
    }

    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}

function virtusphere_error_context(Throwable $exception, string $refId, bool $fromShutdown): string
{
    $request = PHP_SAPI === 'cli'
        ? 'cli ' . implode(' ', array_map('strval', $_SERVER['argv'] ?? []))
        : trim((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET') . ' ' . (string) ($_SERVER['REQUEST_URI'] ?? ''));
    $userId = session_status() === PHP_SESSION_ACTIVE ? (string) ($_SESSION['user_id'] ?? 'none') : 'none';

    return sprintf(
        "[%s] ref=%s source=%s class=%s message=%s file=%s:%d request=%s remote_addr=%s user_id=%s\n%s\n",
        date(DATE_ATOM),
        $refId,
        $fromShutdown ? 'shutdown' : (PHP_SAPI === 'cli' ? 'cli' : 'web'),
        $exception::class,
        $exception->getMessage(),
        $exception->getFile(),
        $exception->getLine(),
        $request,
        (string) ($_SERVER['REMOTE_ADDR'] ?? 'cli'),
        $userId,
        $exception->getTraceAsString()
    );
}

function virtusphere_write_error_log(string $context): void
{
    virtusphere_assert_log_dir_writable();
    virtusphere_prepare_shared_log_file(virtusphere_error_log_path());
    $written = file_put_contents(virtusphere_error_log_path(), $context, FILE_APPEND | LOCK_EX);
    if ($written === false) {
        throw new RuntimeException('Could not write error log: ' . virtusphere_error_log_path());
    }
}

function virtusphere_audit_uncaught_error(Throwable $exception, string $refId): void
{
    try {
        require_once __DIR__ . '/db.php';
        require_once __DIR__ . '/repo/log.php';

        $db = db();
        $userId = session_status() === PHP_SESSION_ACTIVE && isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null;
        audit(
            $db,
            VIRTUSPHERE_LOG_CATEGORY_SYSTEM,
            sprintf('error [%s] %s: %s', $refId, $exception::class, $exception->getMessage()),
            $userId,
            (string) ($_SERVER['REMOTE_ADDR'] ?? 'cli')
        );
    } catch (Throwable $auditException) {
        virtusphere_fallback_error_log('Audit write failed for error ref ' . $refId . ': ' . $auditException->getMessage());
    }
}

function virtusphere_cli_error_text(Throwable $exception, string $refId): string
{
    return sprintf(
        "VirtuSphere error [%s]\n%s: %s\n%s:%d\n%s\n",
        $refId,
        $exception::class,
        $exception->getMessage(),
        $exception->getFile(),
        $exception->getLine(),
        $exception->getTraceAsString()
    );
}

function virtusphere_render_error_page(Throwable $exception, string $refId): void
{
    $nonce = virtusphere_error_nonce();
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: text/html; charset=utf-8');
        header("Content-Security-Policy: default-src 'none'; style-src 'nonce-{$nonce}'; base-uri 'none'; frame-ancestors 'none'");
        header('X-Content-Type-Options: nosniff');
        header('Referrer-Policy: same-origin');
    }

    $details = ['Reference' => $refId];
    $debug = virtusphere_debug_enabled();
    if ($debug) {
        $details += [
            'Class' => $exception::class,
            'Message' => $exception->getMessage(),
            'File' => $exception->getFile() . ':' . $exception->getLine(),
            'Request' => PHP_SAPI === 'cli' ? 'cli' : trim((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET') . ' ' . (string) ($_SERVER['REQUEST_URI'] ?? '')),
        ];
    }

    echo "<!doctype html>\n<html lang=\"de\">\n<head>\n<meta charset=\"utf-8\">\n<title>VirtuSphere Fehler</title>\n";
    echo '<style nonce="' . htmlspecialchars($nonce, ENT_QUOTES, 'UTF-8') . '">';
    echo 'body{font-family:system-ui,-apple-system,Segoe UI,sans-serif;margin:0;background:#1f2937;color:#f9fafb;}';
    echo 'main{max-width:900px;margin:0 auto;padding:32px;}';
    echo 'h1{font-size:28px;margin:0 0 16px;}';
    echo 'p{color:#e5e7eb;line-height:1.5;}';
    echo 'dl{display:grid;grid-template-columns:minmax(140px,220px) 1fr;gap:8px 16px;margin:24px 0;}';
    echo 'dt{color:#cbd5e1;font-weight:700;}dd{margin:0;overflow-wrap:anywhere;}';
    echo 'pre{white-space:pre-wrap;overflow:auto;background:#111827;border:1px solid #475569;padding:16px;}';
    echo '</style></head><body><main>';
    echo '<h1>VirtuSphere Fehler</h1>';
    echo '<p>Ein unerwarteter Fehler ist aufgetreten. Details wurden intern protokolliert.</p><dl>';
    foreach ($details as $name => $value) {
        echo '<dt>' . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . '</dt><dd>' . htmlspecialchars($value, ENT_QUOTES, 'UTF-8') . '</dd>';
    }
    echo '</dl>';
    if ($debug) {
        echo '<h2>Stacktrace</h2><pre>';
        echo htmlspecialchars($exception->getTraceAsString(), ENT_QUOTES, 'UTF-8');
        echo '</pre>';
    }
    echo "</main></body></html>\n";
}

function virtusphere_debug_enabled(): bool
{
    if (function_exists('envboot_optional')) {
        $value = envboot_optional('VIRTUSPHERE_DEBUG', '0');
    } else {
        $raw = getenv('VIRTUSPHERE_DEBUG');
        $value = $raw === false ? '0' : (string) $raw;
    }

    return in_array(strtolower(trim($value)), ['1', 'true', 'yes', 'on'], true);
}

function virtusphere_error_nonce(): string
{
    if (function_exists('virtusphere_csp_nonce')) {
        return virtusphere_csp_nonce();
    }

    try {
        return base64_encode(random_bytes(16));
    } catch (Throwable) {
        return base64_encode(uniqid('', true));
    }
}

function virtusphere_fallback_error_log(string $message): void
{
    error_log('[virtusphere:error-handler] ' . $message);
    if (PHP_SAPI === 'cli') {
        fwrite(STDERR, '[virtusphere:error-handler] ' . $message . PHP_EOL);
    }
}

function virtusphere_exit_after_recursive_error(): void
{
    if (PHP_SAPI === 'cli') {
        exit(1);
    }

    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: text/plain; charset=utf-8');
    }
    echo "VirtuSphere error handler failed.\n";
    exit;
}

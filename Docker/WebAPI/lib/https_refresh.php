<?php

declare(strict_types=1);

// SEC3: run by the PHP container's start script before php-fpm, so HTTP and the
// generated HTTPS block carry the same rules after every update. Exit 0 also for
// "nothing to do"; a failure leaves the previous block in place and only logs.
require_once __DIR__ . '/https_config.php';
require_once __DIR__ . '/log_redaction.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

try {
    $outcome = https_refresh_generated_conf();
} catch (Throwable $exception) {
    fwrite(STDERR, '[https-refresh] ' . virtusphere_redact_log_text($exception->getMessage()) ." The previous HTTPS block stays in place.\n");
    exit(1);
}
if ($outcome === 'refreshed') {
    fwrite(STDERR, "[https-refresh] generated HTTPS block rewritten for this release\n");
}
exit(0);

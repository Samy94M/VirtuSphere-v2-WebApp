<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/lib/layout.php';

/**
 * The live VM status (VB2) rests on three source-level contracts that no
 * rendered page can show:
 *
 * 1. R35: the poll decides identity, permission and locale, releases the
 *    session and only then reads; nothing it renders afterwards reads the
 *    session again.
 * 2. One cell, one renderer: what follows without a reload is rendered by the
 *    function the page rendered it with. A second call site of a cell or block
 *    renderer is how the page and the poll start to disagree.
 * 3. The script holds no wording and is loaded exactly where its line is rendered.
 *
 * Every file set is derived from the sources (who defines, who calls), never
 * listed: a list goes stale the day the next table or block appears.
 */
final class VmStatusLiveContractTest extends TestCase
{
    private const SCANNED_DIRECTORIES = ['lib', 'portal'];

    /** Cell and block renderers and the one function allowed to call each of them. */
    private const SINGLE_OWNER = [
        'vm_phase_cell' => 'vm_live_cells',
        'vm_findings_cell' => 'vm_live_cells',
        'vm_mecm_cell' => 'vm_live_cells',
        'vm_edit_render_status_panel' => 'vm_edit_live_regions',
        'vm_edit_render_progress_panel' => 'vm_edit_live_regions',
        'vm_overview_render_tile' => 'vm_overview_live_tiles',
        'vm_overview_render_attention_tile' => 'vm_overview_live_tiles',
    ];

    public function testThePollDecidesAccessThenReleasesTheSessionBeforeItReads(): void
    {
        $endpoint = (string) file_get_contents($this->root() . '/portal/vm_status_live.php');

        $currentUser = $this->position($endpoint, 'current_user($connection)');
        $mustChange = $this->position($endpoint, '$mustChangePassword =');
        $canWrite = $this->position($endpoint, "can('vms.write', \$user)");
        $canConfig = $this->position($endpoint, "can('system.config', \$user)");
        $close = $this->position($endpoint, 'session_write_close();');
        $request = $this->position($endpoint, 'vm_live_request($_GET)');
        $read = $this->position($endpoint, 'vm_live_payload($connection, $request, $access)');

        self::assertTrue($currentUser < $mustChange);
        self::assertTrue($mustChange < $canWrite);
        self::assertTrue($canWrite < $canConfig);
        self::assertTrue($canConfig < $close, 'every permission is decided before the session is released');
        self::assertTrue($close < $request);
        self::assertTrue($request < $read, 'the first status read follows the release');

        $afterClose = $this->code(substr($endpoint, $close + strlen('session_write_close();')));
        foreach (['$_SESSION', 'current_user(', 'csrf_', 'session_start', 'flash_'] as $needle) {
            self::assertStringNotContainsString($needle, $afterClose, 'the poll touches the session after releasing it: ' . $needle);
        }
        self::assertSame(0, preg_match('/\bcan\s*\(/', $afterClose), 'a permission is decided after the session was released');
        self::assertStringContainsString(
            'Lang::load(__locale_resolve());',
            (string) file_get_contents($this->root() . '/lib/bootstrap.php'),
            'locale persistence must finish before the endpoint code runs'
        );
    }

    public function testNothingThePollRendersReadsTheSession(): void
    {
        $files = $this->scannedFiles();
        // The reader, the protocol and the owners of what the poll renders.
        $pollSide = array_values(array_unique(array_merge(
            glob($this->root() . '/lib/vm_status_live*.php') ?: [],
            $this->filesDefining($files, 'vm_live_cells'),
            $this->filesDefining($files, 'vm_edit_live_regions'),
            $this->filesDefining($files, 'vm_overview_live_tiles'),
        )));
        self::assertGreaterThanOrEqual(5, count($pollSide), 'the poll side was not found, so the scan would prove nothing');

        foreach ($pollSide as $file) {
            $code = $this->code((string) file_get_contents($file));
            foreach (['$_SESSION', 'current_user(', 'csrf_field(', 'csrf_token(', 'require_login(', 'flash_set('] as $needle) {
                self::assertStringNotContainsString($needle, $code, $this->relative($file) . ' reads the session: ' . $needle);
            }
            self::assertSame(0, preg_match('/\bcan\s*\(/', $code), $this->relative($file) . ' decides a permission; the endpoint passes decided values in');
        }
    }

    public function testEveryLiveCellAndBlockHasOneRenderCallSite(): void
    {
        $files = $this->scannedFiles();
        self::assertNotSame([], $files, 'the scan found no PHP files, so it would prove nothing');

        foreach (self::SINGLE_OWNER as $renderer => $owner) {
            $ownerFiles = $this->filesDefining($files, $owner);
            self::assertCount(1, $ownerFiles, $owner . '() has exactly one definition');
            self::assertCount(1, $this->filesDefining($files, $renderer), $renderer . '() has exactly one definition');
            $callers = $this->filesCalling($files, $renderer);
            self::assertSame(
                array_map($this->relative(...), $ownerFiles),
                array_map($this->relative(...), $callers),
                $renderer . '() is called outside ' . $owner . '(): the page and the poll must share that one call'
            );
            self::assertStringContainsString(
                $renderer . '(',
                $this->functionBody($ownerFiles[0], $owner),
                $owner . '() no longer calls ' . $renderer . '()'
            );
        }
    }

    public function testThePagesAndThePollCallTheSameOwners(): void
    {
        $files = $this->scannedFiles();
        $pollReader = $this->filesDefining($files, 'vm_live_payload');
        self::assertCount(1, $pollReader);

        foreach (['vm_live_cells' => 3, 'vm_edit_live_regions' => 2, 'vm_overview_live_tiles' => 2] as $owner => $minimum) {
            $callers = $this->filesCalling($files, $owner);
            self::assertGreaterThanOrEqual($minimum, count($callers), $owner . '() needs its pages and the poll as callers');
            self::assertContains($pollReader[0], $callers, 'the poll does not render through ' . $owner . '()');
        }

        // A table that marks live cells takes them from the shared owner, and a
        // file that renders VM rows builds no status cell of its own.
        $cellPages = $this->filesCalling($files, 'vm_live_td');
        self::assertGreaterThanOrEqual(2, count($cellPages), 'the VM list and the VM overview both mark live cells');
        foreach ($cellPages as $file) {
            self::assertContains($file, $this->filesCalling($files, 'vm_live_cells'), $this->relative($file) . ' marks a live cell it did not take from vm_live_cells()');
        }
        foreach ($files as $file) {
            $code = $this->code((string) file_get_contents($file));
            if (!str_contains($code, '<tr id="vm-')) {
                continue;
            }
            self::assertSame(0, preg_match('/\bmecm_sync_badge\s*\(/', $code), $this->relative($file) . ' renders a VM row with its own MECM cell');
        }
    }

    public function testTheScriptHoldsNoWordingAndFollowsThePollRules(): void
    {
        $script = (string) file_get_contents($this->root() . '/portal/assets/vm_status_live.js');
        $code = (string) preg_replace('~^\s*//.*$~m', '', $script);

        // Every sentence comes from the page or the answer (R3). The catalogs of
        // both locales are walked, so a sentence copied into the script fails.
        foreach (['de', 'en'] as $locale) {
            $catalog = require $this->root() . '/lang/' . $locale . '/vm_live.php';
            self::assertNotSame([], $catalog);
            foreach ($catalog as $key => $value) {
                $words = trim((string) preg_replace('/:[a-z_]+/', '', (string) $value));
                self::assertStringNotContainsString($words, $code, "the script repeats {$locale}/vm_live.{$key}");
            }
        }
        self::assertSame(0, preg_match('/[äöüÄÖÜß]/u', $code), 'the script carries German text');

        // R35 to R37 and R11 as the script must keep them.
        foreach ([
            'data-vm-live-interval' => 'the interval is the server constant, read from the page',
            'document.hidden' => 'a background tab does not poll',
            'visibilitychange' => 'the tab comes back with one catch-up',
            "'Content-Type'" => 'the JSON content type is checked',
            'response.status === 401 || response.status === 403' => 'a refusal ends the polling',
            'mine !== sequence' => 'an overtaken answer is dropped',
            'document.activeElement' => 'the focused element is never replaced',
        ] as $needle => $why) {
            self::assertStringContainsString($needle, $code, $why);
        }
        self::assertStringNotContainsString('setInterval', $code, 'a fixed interval timer would poll in a background tab');

        $line = $this->functionBody($this->root() . '/lib/vm_status_live.php', 'vm_live_line_html');
        self::assertStringContainsString('VIRTUSPHERE_VM_STATUS_LIVE_INTERVAL_SECONDS', $line, 'the interval reaches the script as the constant');
        self::assertStringContainsString('role="status"', $line);
        self::assertStringContainsString(' hidden', $line, 'without JavaScript the line stays hidden');
    }

    public function testTheScriptIsLoadedExactlyWhereItsLineIsRendered(): void
    {
        // A page renders the line itself or through its lib/<page>_*.php modules (R17).
        $rendering = [];
        foreach (glob($this->root() . '/portal/*.php') ?: [] as $page) {
            $stem = basename($page, '.php');
            $sources = array_merge([$page], glob($this->root() . '/lib/' . $stem . '_*.php') ?: []);
            foreach ($sources as $source) {
                if (preg_match('/\bvm_live_line_html\s*\(/', $this->code((string) file_get_contents($source))) === 1) {
                    $rendering[] = basename($page);
                    break;
                }
            }
        }
        sort($rendering);
        self::assertNotSame([], $rendering, 'no page renders the live line, so the comparison would prove nothing');

        $registered = layout_script_registry()['assets/vm_status_live.js'] ?? [];
        sort($registered);
        self::assertSame($rendering, $registered, 'the live script and the pages that render its line differ');
    }

    private function root(): string
    {
        return dirname(__DIR__, 2);
    }

    private function relative(string $file): string
    {
        return str_replace('\\', '/', substr($file, strlen($this->root()) + 1));
    }

    private function position(string $source, string $needle): int
    {
        $position = strpos($source, $needle);
        self::assertNotFalse($position, 'missing contract fragment: ' . $needle);

        return (int) $position;
    }

    /** PHP source without comments, so a rule a comment explains is not read as a breach of it. */
    private function code(string $source): string
    {
        $code = '';
        $tokens = str_starts_with(ltrim($source), '<?php') ? token_get_all($source) : token_get_all('<?php ' . $source);
        foreach ($tokens as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            $code .= is_array($token) ? $token[1] : $token;
        }

        return $code;
    }

    /** @return list<string> */
    private function scannedFiles(): array
    {
        $files = [];
        foreach (self::SCANNED_DIRECTORIES as $directory) {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($this->root() . '/' . $directory, FilesystemIterator::SKIP_DOTS)
            );
            foreach ($iterator as $file) {
                if ($file->isFile() && $file->getExtension() === 'php') {
                    $files[] = str_replace('\\', '/', $file->getPathname());
                }
            }
        }
        sort($files);

        return $files;
    }

    /**
     * @param list<string> $files
     * @return list<string>
     */
    private function filesDefining(array $files, string $function): array
    {
        $definition = '/\bfunction\s+' . preg_quote($function, '/') . '\s*\(/';

        return array_values(array_filter(
            $files,
            fn (string $file): bool => preg_match($definition, $this->code((string) file_get_contents($file))) === 1
        ));
    }

    /**
     * Files that call the function; its definition line is not a call.
     *
     * @param list<string> $files
     * @return list<string>
     */
    private function filesCalling(array $files, string $function): array
    {
        $call = '/(?<!function\s)(?<![A-Za-z0-9_])' . preg_quote($function, '/') . '\s*\(/';

        return array_values(array_filter(
            $files,
            fn (string $file): bool => preg_match($call, $this->code((string) file_get_contents($file))) === 1
        ));
    }

    /** The source of one top-level function, from its signature to the next one. */
    private function functionBody(string $file, string $function): string
    {
        $code = $this->code((string) file_get_contents($file));
        self::assertSame(1, preg_match('/\bfunction\s+' . preg_quote($function, '/') . '\s*\(/', $code, $match, PREG_OFFSET_CAPTURE), $function . '() not found');
        $start = (int) $match[0][1] + strlen($match[0][0]);
        $next = preg_match('/^function\s+\w+\s*\(/m', $code, $after, PREG_OFFSET_CAPTURE, $start) === 1 ? (int) $after[0][1] : strlen($code);

        return substr($code, $start, $next - $start);
    }
}

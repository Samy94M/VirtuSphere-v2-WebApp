<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * The raw `vm_status` (the x/5 MECM value) is not a label an operator should
 * read as a phase. It stays rendered only where its role is the raw value
 * itself: the VM editor's status panel (lib/vm_edit_status.php, whose technical
 * block lists the stored values), the technical tooltip of the phase badge (the one
 * place the list keeps it, plan section 5) and the CSV exports, where the raw
 * value may be a column but never a badge. The machine API lives at the WebAPI
 * root (mecm-*.php) and is not scanned here.
 *
 * The allowed files are DERIVED from the source, not listed. The editor is the
 * file that defines vm_edit_render_status_panel(); the tooltip owner is the file
 * that defines vm_phase_cell(); the CSV exports are the files that call
 * portal_send_csv(). The badge renderer status_badge() needs no file
 * entry: its definition line is not a call, so it is never a render line. A
 * hand-written list of "the pages that show vm_status" goes stale the day the
 * next table appears, and the stale entry then hides the new leak. The same
 * reasoning as CliRequireClosureContractTest applies to its entrypoints.
 *
 * `\bstatus_badge(` carries a word boundary on purpose: deploy_job_status_badge()
 * and catalog_status_badge() render other vocabularies and are not this guard's
 * business.
 */
final class VmStatusRenderContractTest extends TestCase
{
    /** The directories whose PHP is the portal's renderer; the WebAPI root is the machine API. */
    private const SCANNED_DIRECTORIES = ['lib', 'portal'];

    public function testTheRawVmStatusIsRenderedOnlyWhereItsRoleAllowsIt(): void
    {
        $files = $this->scannedFiles();
        self::assertNotSame([], $files, 'the scan found no PHP files, so it would prove nothing');

        // A badge of the raw value is the editor's alone; the raw TEXT may also
        // sit in the phase tooltip and in a CSV column.
        $badgeAllowed = $this->filesDefining($files, 'vm_edit_render_status_panel');
        $rawAllowed = array_merge(
            $badgeAllowed,
            $this->filesDefining($files, 'vm_phase_cell'),
            $this->filesCalling($files, 'portal_send_csv')
        );

        $violations = [];
        foreach ($files as $file) {
            foreach ($this->lines($file) as $number => $line) {
                $badge = $this->callsFunction($line, 'status_badge');
                if (in_array($file, $badge ? $badgeAllowed : $rawAllowed, true)) {
                    continue;
                }
                if ($badge || $this->rendersRawStatus($line)) {
                    $violations[] = $this->relative($file) . ':' . ($number + 1);
                }
            }
        }

        self::assertSame(
            [],
            $violations,
            "raw vm_status is rendered outside its allowed roles (use vm_phase_cell()):\n" . implode("\n", $violations)
        );
    }

    public function testTheScanIsNotEmptyAndBothVmTablesUseTheSharedCell(): void
    {
        $files = $this->scannedFiles();
        self::assertNotSame([], $files, 'the scan found no PHP files, so it would prove nothing');
        self::assertNotSame(
            [],
            $this->filesDefining($files, 'vm_edit_render_status_panel'),
            'the editor status panel was not found, so its allowed role is unverified'
        );

        foreach (['portal/vms.php', 'lib/vm_overview_page.php'] as $relative) {
            $path = $this->root() . '/' . $relative;
            self::assertFileExists($path);
            self::assertStringContainsString(
                'vm_phase_cell(',
                (string) file_get_contents($path),
                $relative . ' must render its VM phase through the shared cell'
            );
        }
    }

    private function rendersRawStatus(string $line): bool
    {
        if ($this->callsFunction($line, 'status_badge')) {
            return true;
        }

        return str_contains($line, 'vm_status') && preg_match('/\becho\b|\bh\s*\(/', $line) === 1;
    }

    private function root(): string
    {
        return dirname(__DIR__, 2);
    }

    /** @return list<string> every PHP file of the scanned directories, recursively */
    private function scannedFiles(): array
    {
        $files = [];
        foreach (self::SCANNED_DIRECTORIES as $directory) {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($this->root() . '/' . $directory, FilesystemIterator::SKIP_DOTS)
            );
            foreach ($iterator as $file) {
                if ($file->isFile() && $file->getExtension() === 'php') {
                    $files[] = $file->getPathname();
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
        $definition = '/function\s+' . preg_quote($function, '/') . '\s*\(/';

        return array_values(array_filter($files, function (string $file) use ($definition): bool {
            foreach ($this->lines($file) as $line) {
                if (preg_match($definition, $line) === 1) {
                    return true;
                }
            }

            return false;
        }));
    }

    /**
     * @param list<string> $files
     * @return list<string>
     */
    private function filesCalling(array $files, string $function): array
    {
        return array_values(array_filter($files, function (string $file) use ($function): bool {
            foreach ($this->lines($file) as $line) {
                if ($this->callsFunction($line, $function)) {
                    return true;
                }
            }

            return false;
        }));
    }

    /** A call of the function on this line; its definition does not count. */
    private function callsFunction(string $line, string $function): bool
    {
        $name = preg_quote($function, '/');

        return preg_match('/\b' . $name . '\s*\(/', $line) === 1
            && preg_match('/function\s+' . $name . '\s*\(/', $line) !== 1;
    }

    /** @return list<string> */
    private function lines(string $file): array
    {
        return file($file, FILE_IGNORE_NEW_LINES) ?: [];
    }

    private function relative(string $file): string
    {
        return str_replace('\\', '/', substr($file, strlen($this->root()) + 1));
    }
}

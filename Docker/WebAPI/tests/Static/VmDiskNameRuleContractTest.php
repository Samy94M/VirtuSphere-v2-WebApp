<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * DN2: "an empty disk name becomes the default" had its own copy in the
 * validator, the form parser, the conflict comparison and the disk layout
 * (`$name !== '' ? $name : vm_disk_default_name(...)`). With the system disk
 * staying empty since DN2 every copy would have had to change in step, and one
 * forgotten copy fills the system disk with a name again. The rule lives in
 * vm_disk_stored_name() (lib/defaults.php); this test derives every PHP file
 * under lib/, portal/ and the entry scripts and refuses a fallback to
 * vm_disk_default_name() or to the legacy default outside that owner.
 */
final class VmDiskNameRuleContractTest extends TestCase
{
    private const OWNER = 'lib/defaults.php';

    /** A value that falls back to the default name: `?: f(`, `? $x : f(`, `= f($` into a row. */
    private const COPY_PATTERNS = [
        '/\?:\s*vm_disk_default_name\s*\(/',
        '/\?\s*\$[\w\[\]\'"]+\s*:\s*vm_disk_default_name\s*\(/',
        '/\[\s*[\'"]disk_name[\'"]\s*\]\s*=\s*vm_disk_default_name\s*\(/',
        '/repo_object_get\([^;]*[\'"]disk_name[\'"]\s*,\s*vm_disk_default_name\s*\(/',
        '/VIRTUSPHERE_VM_DEFAULTS\s*\[\s*[\'"]disk_name[\'"]\s*\]/',
    ];

    /** @return list<string> repo-relative paths below Docker/WebAPI */
    private static function phpFiles(): array
    {
        $root = dirname(__DIR__, 2);
        $files = [];
        foreach (['lib', 'portal'] as $dir) {
            $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/' . $dir, FilesystemIterator::SKIP_DOTS));
            foreach ($iterator as $file) {
                if ($file instanceof SplFileInfo && $file->getExtension() === 'php') {
                    $files[] = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
                }
            }
        }
        foreach (glob($root . '/*.php') ?: [] as $entry) {
            $files[] = basename($entry);
        }
        sort($files);

        return $files;
    }

    public function testNoFileCarriesItsOwnCopyOfTheDefaultNameRule(): void
    {
        $root = dirname(__DIR__, 2);
        $files = self::phpFiles();
        self::assertContains(self::OWNER, $files, 'zero-match: the owner must be among the scanned files');
        self::assertGreaterThan(100, count($files), 'zero-match: the scan found too few PHP files');

        $copies = [];
        foreach ($files as $path) {
            if ($path === self::OWNER) {
                continue;
            }
            $text = (string) file_get_contents($root . '/' . $path);
            foreach (self::COPY_PATTERNS as $pattern) {
                if (preg_match($pattern, $text, $match) === 1) {
                    $copies[] = $path . ': ' . $match[0];
                }
            }
        }

        self::assertSame([], $copies, 'Disk name defaults go through vm_disk_stored_name() (lib/defaults.php), never a local fallback.');
    }

    public function testEveryDiskNameReaderUsesTheNormalisation(): void
    {
        // The four places that turned an empty name into a default before DN2.
        $root = dirname(__DIR__, 2);
        foreach ([
            'lib/repo/vms_validation.php' => 'repo_validate_disks',
            'lib/vm_edit_values.php' => 'vm_parse_disks',
            'lib/vm_edit_conflict.php' => 'vm_edit_conflict_disk_rows',
            'lib/vm_disk_layout.php' => 'vm_disk_rows',
        ] as $path => $function) {
            $text = (string) file_get_contents($root . '/' . $path);
            self::assertSame(1, preg_match('/function ' . $function . '\b.*?\n}\n/s', $text, $body), $path . ' ' . $function);
            self::assertStringContainsString('vm_disk_stored_name(', $body[0], $path . ' ' . $function);
        }
    }

    public function testThePatternsCatchTheFormerCopies(): void
    {
        // The pre-DN2 lines, so a pattern that stops matching fails here.
        $former = [
            "'disk_name' => \$name !== '' ? \$name : vm_disk_default_name(count(\$disks) + 1),",
            "\$row['disk_name'] = vm_disk_default_name(\$index + 1);",
            "repo_object_get(\$disk, 'disk_name', vm_disk_default_name(\$index + 1)), validator_label('disk_name', 'Disk name'), 255),",
            "\$name = (string) repo_object_get(\$disk, 'disk_name', VIRTUSPHERE_VM_DEFAULTS['disk_name']);",
            "'name' => \$name ?: vm_disk_default_name(\$position + 1),",
        ];
        foreach ($former as $line) {
            $hit = false;
            foreach (self::COPY_PATTERNS as $pattern) {
                $hit = $hit || preg_match($pattern, $line) === 1;
            }
            self::assertTrue($hit, $line);
        }
    }
}

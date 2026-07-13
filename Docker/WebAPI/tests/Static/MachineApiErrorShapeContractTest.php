<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * MECM, the PowerShell scripts, the Ansible callback and the desktop client all
 * parse the response body as JSON. An uncaught error must therefore not reach
 * them as the portal's HTML error page, or the integration reports a parse
 * failure instead of the server error it could have logged and retried.
 *
 * The ordering is the whole point and is easy to lose: mysql.php opens the
 * connection while it is being *loaded*, so a dead database throws from inside
 * that require. Anything that declares the response shape after it never runs.
 * A new endpoint that puts the require first would look perfectly reasonable and
 * would silently answer HTML on exactly the days it matters.
 */
final class MachineApiErrorShapeContractTest extends TestCase
{
    /** Entry scripts that answer machine clients in JSON. */
    private const JSON_ENTRY_POINTS = [
        'mecm-api.php',
        'mecm_updateid.php',
        'mecm_packages.php',
        'db_importMAC.php',
        'mecm_report.php',
        'access.php',
        'api/login.php',
    ];

    /** @return array<string, array{0: string}> */
    public static function entryPointProvider(): array
    {
        $cases = [];
        foreach (self::JSON_ENTRY_POINTS as $script) {
            $cases[$script] = [$script];
        }

        return $cases;
    }

    #[PHPUnit\Framework\Attributes\DataProvider('entryPointProvider')]
    public function testDeclaresTheJsonErrorShapeBeforeItTouchesTheDatabase(string $script): void
    {
        $path = dirname(__DIR__, 2) . '/' . $script;
        self::assertFileExists($path, 'machine API entry point is missing: ' . $script);

        $source = (string) file_get_contents($path);

        $modeAt = strpos($source, "virtusphere_error_response_mode('json')");
        self::assertNotFalse($modeAt, $script . ' must declare the JSON error shape');

        // Both spellings reach the eager connect in mysql.php: directly, or via
        // function.php, which requires it.
        foreach (['mysql.php', 'function.php'] as $dbEntry) {
            $requireAt = strpos($source, "require_once __DIR__ . '/" . $dbEntry . "'");
            if ($requireAt === false) {
                $requireAt = strpos($source, "require_once '../" . $dbEntry . "'");
            }
            if ($requireAt === false) {
                continue;
            }

            self::assertLessThan(
                $requireAt,
                $modeAt,
                $script . ' declares the JSON error shape after requiring ' . $dbEntry
                    . ', which connects while it loads: a database outage would answer HTML'
            );
        }
    }

    /**
     * The portal must keep the HTML page. A library that flipped the mode
     * globally would turn every portal error into a JSON blob in the browser.
     */
    public function testTheModeIsNotSetInSharedLibraryCode(): void
    {
        $lib = dirname(__DIR__, 2) . '/lib';
        $offenders = [];
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($lib));
        foreach ($files as $file) {
            if (!$file instanceof SplFileInfo || $file->getExtension() !== 'php') {
                continue;
            }
            // errors.php defines the function; declaring the mode is the caller's job.
            if ($file->getFilename() === 'errors.php') {
                continue;
            }
            if (str_contains((string) file_get_contents($file->getPathname()), "virtusphere_error_response_mode('json')")) {
                $offenders[] = $file->getFilename();
            }
        }

        self::assertSame(
            [],
            $offenders,
            'the response shape is an entry-point decision; setting it in lib/ would affect the portal too: '
                . implode(', ', $offenders)
        );
    }
}

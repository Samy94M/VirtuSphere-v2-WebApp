<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Bans raw scalar casts on request input across first-party PHP.
 *
 * `(string) ($_GET['x'] ?? '')` and `(int) ($_POST['x'] ?? 0)` throw "Array to
 * string conversion" the moment the client writes `x[]=`, and the global error
 * handler turns that warning into a 500 plus a `system` audit row (lib/errors.php).
 * A stray bracket in a URL then takes a page down and grows deploy_logs one line
 * per request; on the machine API, ahead of the IP gate, unauthenticated.
 *
 * request_string()/request_int()/request_trimmed() (lib/request.php) read the
 * value only when it is a scalar and fall back otherwise, so the whole class is
 * closed at the boundary. This test keeps it closed: nothing else notices when a
 * new page reintroduces a raw cast, because the code runs fine until someone
 * sends the bracket.
 *
 * A parameter that is genuinely an array (bulk `vm_ids[]`) is read with an
 * is_array() guard at its call site, which this pattern does not match.
 */
final class RequestInputContractTest extends TestCase
{
    /**
     * Files that read request input directly. lib/request.php is the sanctioned
     * home of the casts and is excluded; everything else must route through it.
     */
    private const SCANNED_DIRS = ['portal', 'lib'];
    private const SCANNED_ROOT_FILES = [
        'mecm-api.php', 'mecm_report.php', 'mecm_client_ack.php', 'mecm_updateid.php', 'db_importMAC.php',
        'mecm_packages.php',
    ];

    public function testNoRawScalarCastOnRequestInput(): void
    {
        $offenders = [];
        // (string)/(int) directly around $_GET[ / $_POST[ , the shape that throws.
        $pattern = '/\((?:string|int)\)\s*\(?\s*\$_(?:GET|POST)\[/';

        foreach ($this->firstPartyPhpFiles() as $relative => $path) {
            $source = (string) file_get_contents($path);
            if (preg_match_all($pattern, $source, $matches) > 0) {
                $offenders[$relative] = count($matches[0]);
            }
        }

        self::assertSame(
            [],
            $offenders,
            "Raw scalar cast on request input found. Use request_string()/request_int()/"
            . "request_trimmed() (lib/request.php); a `key[]=` in the URL makes the cast throw "
            . "into a 500 and a system audit row.\nOffenders: " . json_encode($offenders)
        );
    }

    public function testRequestHelpersExist(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 2) . '/lib/request.php');
        foreach (['request_string', 'request_int', 'request_trimmed'] as $fn) {
            self::assertStringContainsString("function {$fn}(", $source);
        }
    }

    /**
     * @return iterable<string, string> relative path => absolute path
     */
    private function firstPartyPhpFiles(): iterable
    {
        $base = dirname(__DIR__, 2);

        foreach (self::SCANNED_ROOT_FILES as $file) {
            $path = $base . '/' . $file;
            if (is_file($path)) {
                yield $file => $path;
            }
        }

        foreach (self::SCANNED_DIRS as $dir) {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($base . '/' . $dir, FilesystemIterator::SKIP_DOTS)
            );
            foreach ($iterator as $file) {
                if ($file->getExtension() !== 'php') {
                    continue;
                }
                $path = $file->getPathname();
                $relative = ltrim(str_replace($base, '', $path), '/\\');
                $relative = str_replace('\\', '/', $relative);
                // lib/request.php is the one place the casts are allowed to live.
                if ($relative === 'lib/request.php') {
                    continue;
                }
                yield $relative => $path;
            }
        }
    }
}

<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * The write-ahead contract of the ESXi access file (K21, VT-04).
 *
 * accounts.yml reaches the Ansible host only after its remote_dir is recorded in
 * deploy_remote_secret_files, and only lib/ansible_command_modes.php builds its
 * removal. Every upload caller is DERIVED by scanning lib/ with the PHP tokenizer
 * and never listed here: a hand-kept list would stay green while a new upload
 * path appeared beside it, which is exactly the path this contract is for. The
 * scanner has its own test on an inline snippet, so a scan that matches nothing
 * fails instead of passing.
 */
final class RemoteSecretUploadContractTest extends TestCase
{
    private const UPLOAD = 'ssh_sftp_upload_directory';
    private const REMEMBER = 'deploy_remote_secret_remember';
    private const SWEEP = 'deploy_remote_secret_sweep';
    private const REMOTE_INVENTORY_UPLOAD = 'remote_inventory_upload';
    private const COMMAND_MODULE = 'lib/ansible_command_modes.php';
    private const CREATE_CONTROL_MODULE = 'lib/ansible_command_create.php';
    private const CREATE_CONTROL_FUNCTION = 'ansible_create_control_command';

    /**
     * The one upload caller without a row. It is a locked remote execution
     * contract that has no production caller (test below), so it cannot upload
     * an accounts.yml that a job could leave behind. The exemption expires as
     * soon as a caller appears.
     */
    private const EXEMPT_CALLER = 'lib/remote_inventory_consumer.php::remote_inventory_upload';

    public function testEveryLibFunctionThatUploadsWritesItsRememberedRowBeforeTheFirstUpload(): void
    {
        $analysis = self::libUploadAnalysis();

        self::assertNotSame(
            [],
            $analysis['callers'],
            'Zero match: no function under lib/ calls ssh_sftp_upload_directory(), so this contract would pass on nothing.'
        );
        self::assertSame(
            [],
            $analysis['strays'],
            'ssh_sftp_upload_directory() is called outside any named function, where no remember call can precede it.'
        );

        $unguarded = [];
        foreach ($analysis['callers'] as $caller => $rememberedFirst) {
            if ($caller !== self::EXEMPT_CALLER && $rememberedFirst !== true) {
                $unguarded[] = $caller;
            }
        }
        self::assertSame(
            [],
            $unguarded,
            'These functions upload accounts.yml without deploy_remote_secret_remember() textually before their first upload call: '
                . implode(', ', $unguarded)
        );
    }

    public function testTheRemoteInventoryUploadExemptionStillMatchesItsCallerAndStillHasNoProductionCaller(): void
    {
        $analysis = self::libUploadAnalysis();

        self::assertArrayHasKey(
            self::EXEMPT_CALLER,
            $analysis['callers'],
            'The exemption names an upload caller that no longer exists; remove the exemption instead of keeping it.'
        );
        self::assertSame(
            [],
            self::callersOf(self::REMOTE_INVENTORY_UPLOAD, self::libFiles()),
            'remote_inventory_upload() has a production caller in lib/ now. That caller must write deploy_remote_secret_remember() '
                . 'first, and the exemption must be removed in the same change.'
        );
    }

    public function testTheMissionAndInventoryWorkersCallTheRemoteSecretSweep(): void
    {
        $files = [];
        foreach (self::callersOf(self::SWEEP, self::libFiles()) as $caller) {
            $files[] = explode('::', $caller, 2)[0];
        }
        $files = array_values(array_unique($files));

        self::assertContains(
            'lib/deploy_worker_mission.php',
            $files,
            'The mission worker does not call deploy_remote_secret_sweep(); a leftover file would wait for a contact that never comes.'
        );
        self::assertContains(
            'lib/deploy_worker_inventory.php',
            $files,
            'The inventory worker does not call deploy_remote_secret_sweep(); a leftover file would wait for a contact that never comes.'
        );
    }

    public function testNoLibFileOutsideTheCommandModuleBuildsARemovalOfAccountsYml(): void
    {
        $files = self::libFiles();
        self::assertArrayHasKey(self::COMMAND_MODULE, $files, 'Zero match: the command module that owns the removal is missing.');

        $offenders = [];
        foreach ($files as $relative => $path) {
            if ($relative === self::COMMAND_MODULE) {
                continue;
            }
            foreach (file($path, FILE_IGNORE_NEW_LINES) ?: [] as $number => $line) {
                if (preg_match('~^\s*(\*|/\*|//|#)~', $line) === 1) {
                    continue;
                }
                if (str_contains($line, 'accounts.yml') && preg_match('/\brm\s/', $line) === 1) {
                    $offenders[] = $relative . ':' . ($number + 1);
                }
            }
        }

        self::assertSame(
            [],
            $offenders,
            'Only ansible_remote_secret_remove_command() may build a removal of accounts.yml. These lines build one elsewhere: '
                . implode(', ', $offenders)
        );
    }

    public function testTheStatusControlCommandCarriesNoCleanupTrap(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 2) . '/' . self::CREATE_CONTROL_MODULE);
        $code = self::functionCode($source, self::CREATE_CONTROL_FUNCTION);

        self::assertNotSame('', $code, 'Zero match: ' . self::CREATE_CONTROL_FUNCTION . '() was not found in ' . self::CREATE_CONTROL_MODULE . '.');
        self::assertDoesNotMatchRegularExpression(
            '/\btrap\b/i',
            $code,
            'A create control call must not install a cleanup trap: the async job it starts outlives the call, and its state lives in the directory a trap would delete.'
        );
    }

    public function testTheUploadScannerCountsCallsInsideFunctionsAndIgnoresCommentsAndDeclarations(): void
    {
        $source = <<<'PHP'
<?php
function guarded(): void
{
    deploy_remote_secret_remember($db, 1, null, $dir);
    ssh_sftp_upload_directory($credential, $secret, $local, $dir);
}
function unguarded(): void
{
    // ssh_sftp_upload_directory( in a comment is no call
    ssh_sftp_upload_directory($credential, $secret, $local, $dir);
    deploy_remote_secret_remember($db, 1, null, $dir);
}
function silent(): void
{
    /* ssh_sftp_upload_directory( */
    return;
}
function ssh_sftp_upload_directory(array $credential): void
{
}
PHP;

        $analysis = self::uploadAnalysis($source);

        self::assertSame(['guarded' => true, 'unguarded' => false], $analysis['callers']);
        self::assertSame(0, $analysis['strays']);
    }

    public function testTheUploadScannerReportsAnUploadThatIsOutsideAnyFunction(): void
    {
        $analysis = self::uploadAnalysis("<?php\nssh_sftp_upload_directory(\$credential, \$secret, \$local, \$dir);\n");

        self::assertSame([], $analysis['callers']);
        self::assertSame(1, $analysis['strays']);
    }

    /**
     * @return array{callers: array<string, bool>, strays: list<string>}
     *     callers: "lib/file.php::function" => the function remembers before its first upload
     *     strays: lib-relative files with an upload outside any named function
     */
    private static function libUploadAnalysis(): array
    {
        $callers = [];
        $strays = [];
        foreach (self::libFiles() as $relative => $path) {
            $analysis = self::uploadAnalysis((string) file_get_contents($path));
            foreach ($analysis['callers'] as $function => $rememberedFirst) {
                $callers[$relative . '::' . $function] = $rememberedFirst;
            }
            if ($analysis['strays'] > 0) {
                $strays[] = $relative;
            }
        }

        return ['callers' => $callers, 'strays' => $strays];
    }

    /**
     * @return array{callers: array<string, bool>, strays: int}
     *     callers: function name => a deploy_remote_secret_remember() call precedes its first upload call
     */
    private static function uploadAnalysis(string $source): array
    {
        // A source that never spells the name cannot call it. Skipping it before
        // tokenizing keeps the memory of a run to the files that matter, one at a time.
        if (!str_contains($source, self::UPLOAD)) {
            return ['callers' => [], 'strays' => 0];
        }
        $tokens = self::tokenize($source);
        $functions = self::functionRanges($tokens);
        $rememberSites = self::callSites($tokens, self::REMEMBER);

        $callers = [];
        $strays = 0;
        foreach (self::callSites($tokens, self::UPLOAD) as $upload) {
            $owner = self::enclosingFunction($functions, $upload);
            if ($owner === null) {
                $strays++;
                continue;
            }
            if (isset($callers[$owner['name']])) {
                continue;
            }
            $rememberedFirst = false;
            foreach ($rememberSites as $site) {
                if ($site >= $owner['start'] && $site < $upload) {
                    $rememberedFirst = true;
                }
            }
            $callers[$owner['name']] = $rememberedFirst;
        }

        return ['callers' => $callers, 'strays' => $strays];
    }

    /** @return list<string> "lib-relative file::function" for every call of $name outside its declaration */
    private static function callersOf(string $name, array $files): array
    {
        $callers = [];
        foreach ($files as $relative => $path) {
            foreach (self::callOwnersIn((string) file_get_contents($path), $name) as $owner) {
                $callers[] = $relative . '::' . $owner;
            }
        }

        return $callers;
    }

    /**
     * The function names that call $name in one source, '(file scope)' for a call
     * outside any function. Only the names leave this method, so the token array
     * of one file is released before the next file is read.
     *
     * @return list<string>
     */
    private static function callOwnersIn(string $source, string $name): array
    {
        if (!str_contains($source, $name)) {
            return [];
        }
        $tokens = self::tokenize($source);
        $functions = self::functionRanges($tokens);
        $owners = [];
        foreach (self::callSites($tokens, $name) as $site) {
            $owner = self::enclosingFunction($functions, $site);
            $owners[] = $owner['name'] ?? '(file scope)';
        }

        return $owners;
    }

    /** Concatenated code tokens of one named function without its comments; '' when it does not exist. */
    private static function functionCode(string $source, string $name): string
    {
        $tokens = self::tokenize($source);
        foreach (self::functionRanges($tokens) as $function) {
            if ($function['name'] !== $name) {
                continue;
            }
            $code = '';
            for ($i = $function['start']; $i <= $function['end']; $i++) {
                if (!in_array($tokens[$i][0], [T_COMMENT, T_DOC_COMMENT], true)) {
                    $code .= $tokens[$i][1];
                }
            }

            return $code;
        }

        return '';
    }

    /** @return array<string, string> lib-relative path (forward slashes) => absolute path of every PHP file under lib/ */
    private static function libFiles(): array
    {
        $root = str_replace('\\', '/', dirname(__DIR__, 2)) . '/';
        $files = [];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . 'lib', FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $path => $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[substr(str_replace('\\', '/', $path), strlen($root))] = $path;
            }
        }
        ksort($files);

        return $files;
    }

    /** @return list<array{0: int|null, 1: string}> token id (null for a single character) and its text */
    private static function tokenize(string $source): array
    {
        $tokens = [];
        foreach (token_get_all($source) as $token) {
            $tokens[] = is_array($token) ? [$token[0], $token[1]] : [null, $token];
        }

        return $tokens;
    }

    /**
     * Named functions with a body. Closures are not declarations: their tokens
     * belong to the named function that contains them.
     *
     * @return list<array{name: string, start: int, end: int}>
     */
    private static function functionRanges(array $tokens): array
    {
        $ranges = [];
        $count = count($tokens);
        for ($i = 0; $i < $count; $i++) {
            if ($tokens[$i][0] !== T_FUNCTION) {
                continue;
            }
            $name = self::nextSignificant($tokens, $i + 1);
            if ($name !== null && $tokens[$name][1] === '&') {
                $name = self::nextSignificant($tokens, $name + 1);
            }
            if ($name === null || $tokens[$name][0] !== T_STRING) {
                continue;
            }
            $open = $name + 1;
            while ($open < $count && $tokens[$open][1] !== '{' && $tokens[$open][1] !== ';') {
                $open++;
            }
            if ($open >= $count || $tokens[$open][1] !== '{') {
                // An abstract or interface declaration has no body to scan.
                continue;
            }
            $depth = 0;
            $end = $open;
            for (; $end < $count; $end++) {
                $text = $tokens[$end][1];
                if ($text === '{' || $tokens[$end][0] === T_DOLLAR_OPEN_CURLY_BRACES) {
                    $depth++;
                } elseif ($text === '}') {
                    $depth--;
                    if ($depth === 0) {
                        break;
                    }
                }
            }
            $ranges[] = ['name' => $tokens[$name][1], 'start' => $i, 'end' => min($end, $count - 1)];
        }

        return $ranges;
    }

    /**
     * Token indexes of calls to $name: the identifier followed by "(", and not
     * the identifier of a function declaration.
     *
     * @return list<int>
     */
    private static function callSites(array $tokens, string $name): array
    {
        $sites = [];
        foreach ($tokens as $index => $token) {
            if ($token[0] !== T_STRING || $token[1] !== $name) {
                continue;
            }
            $next = self::nextSignificant($tokens, $index + 1);
            if ($next === null || $tokens[$next][1] !== '(') {
                continue;
            }
            $previous = self::previousSignificant($tokens, $index - 1);
            if ($previous !== null && $tokens[$previous][0] === T_FUNCTION) {
                continue;
            }
            $sites[] = $index;
        }

        return $sites;
    }

    /** @param list<array{name: string, start: int, end: int}> $functions */
    private static function enclosingFunction(array $functions, int $index): ?array
    {
        foreach ($functions as $function) {
            if ($index >= $function['start'] && $index <= $function['end']) {
                return $function;
            }
        }

        return null;
    }

    private static function nextSignificant(array $tokens, int $from): ?int
    {
        for ($i = $from, $count = count($tokens); $i < $count; $i++) {
            if (!in_array($tokens[$i][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                return $i;
            }
        }

        return null;
    }

    private static function previousSignificant(array $tokens, int $from): ?int
    {
        for ($i = $from; $i >= 0; $i--) {
            if (!in_array($tokens[$i][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                return $i;
            }
        }

        return null;
    }
}

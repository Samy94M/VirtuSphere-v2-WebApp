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
    private const RELEASE = 'deploy_remote_secret_release';
    private const WORKER_CLEANUP = 'deploy_worker_cleanup_remote_dir';
    private const PREFLIGHT_COMMAND = 'ansible_preflight_command';
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

    /**
     * K21-A5, rule 1: a function that remembers an upload answers for it in its
     * own finally block, through the release or the worker cleanup that calls it.
     * Without the finally a thrown step would leave the file for the next contact
     * although this job could still have removed it.
     */
    public function testEveryLibFunctionThatRemembersAnUploadReleasesItInAFinallyBlock(): void
    {
        $analysis = self::libFinallyAnalysis();

        self::assertNotSame([], $analysis, 'Zero match: no function under lib/ calls deploy_remote_secret_remember(), so this rule would pass on nothing.');
        $unanswered = array_keys(array_filter($analysis, static fn (bool $answered): bool => !$answered));
        self::assertSame(
            [],
            $unanswered,
            'These functions call deploy_remote_secret_remember() without deploy_remote_secret_release() or '
                . 'deploy_worker_cleanup_remote_dir() inside a finally block of the same function: ' . implode(', ', $unanswered)
        );
    }

    /**
     * K21-A5, rule 2: the sweep runs after the host preflight (a host that failed
     * it is not asked to remove anything) and before the first remember of the
     * same function (the current job's own row is not a leftover). The preflight
     * anchors are derived: ansible_preflight_command() and every lib function
     * that calls it.
     */
    public function testEverySweepCallFollowsTheHostPreflightAndPrecedesTheFirstRemember(): void
    {
        $analysis = self::libSweepOrderAnalysis();

        self::assertNotSame([], $analysis, 'Zero match: no function under lib/ calls deploy_remote_secret_sweep(), so this rule would pass on nothing.');
        $misplaced = [];
        foreach ($analysis as $caller => $order) {
            if (!$order['after_preflight'] || !$order['before_remember']) {
                $misplaced[] = $caller . ($order['after_preflight'] ? '' : ' (before the host preflight)')
                    . ($order['before_remember'] ? '' : ' (after the first remember)');
            }
        }
        self::assertSame([], $misplaced, 'The remote secret sweep is misplaced in: ' . implode(', ', $misplaced));
    }

    public function testTheFinallyScannerAcceptsOnlyAReleaseInsideAFinallyOfTheSameFunction(): void
    {
        $source = <<<'PHP'
<?php
function answered(): void
{
    try {
        deploy_remote_secret_remember($db, 1, 2, $dir);
    } finally {
        deploy_remote_secret_release($connection, $credential, $secret, 1, $dir, false);
    }
}
function cleanedUp(): void
{
    try {
        deploy_remote_secret_remember($db, 1, 2, $dir);
    } finally {
        deploy_worker_cleanup_remote_dir($channel, $credential, $secret, $dir, false);
    }
}
function releasedOutsideFinally(): void
{
    deploy_remote_secret_remember($db, 1, 2, $dir);
    deploy_remote_secret_release($connection, $credential, $secret, 1, $dir, false);
}
function releasedInACommentOnly(): void
{
    try {
        deploy_remote_secret_remember($db, 1, 2, $dir);
    } finally {
        // deploy_remote_secret_release( is no call
        $x = 1;
    }
}
function neverRemembers(): void
{
    deploy_remote_secret_release($connection, $credential, $secret, 1, $dir, false);
}
PHP;

        self::assertSame(
            ['answered' => true, 'cleanedUp' => true, 'releasedOutsideFinally' => false, 'releasedInACommentOnly' => false],
            self::finallyAnalysis($source)
        );
    }

    /** Astra mutations 1 and 3: the real workers with the removal taken out of their finally block. */
    public function testTheFinallyScannerCatchesTheRealWorkersWithTheirRemovalTakenOut(): void
    {
        $inventory = self::libSource('lib/deploy_worker_inventory.php');
        $mission = self::libSource('lib/deploy_worker_mission.php');

        self::assertTrue(self::finallyAnalysis($inventory)['deploy_worker_process_inventory_job'] ?? false, 'Zero match: the real inventory worker is not recognised as answered.');
        self::assertTrue(self::finallyAnalysis($mission)['deploy_worker_process_job'] ?? false, 'Zero match: the real mission worker is not recognised as answered.');
        self::assertFalse(
            self::finallyAnalysis(self::withoutLinesCalling($inventory, self::RELEASE))['deploy_worker_process_inventory_job'] ?? true,
            'The inventory worker without its release in finally must be reported.'
        );
        self::assertFalse(
            self::finallyAnalysis(self::withoutLinesCalling($mission, self::WORKER_CLEANUP))['deploy_worker_process_job'] ?? true,
            'The mission worker without its cleanup in finally must be reported.'
        );
    }

    public function testTheSweepOrderScannerRejectsASweepBeforeThePreflightOrAfterTheFirstRemember(): void
    {
        $source = <<<'PHP'
<?php
function inOrder(): void
{
    deploy_worker_run_host_preflight($channel);
    deploy_remote_secret_sweep($connection, $credential, $secret, 1);
    deploy_remote_secret_remember($db, 1, 2, $dir);
}
function beforePreflight(): void
{
    deploy_remote_secret_sweep($connection, $credential, $secret, 1);
    deploy_worker_run_host_preflight($channel);
    deploy_remote_secret_remember($db, 1, 2, $dir);
}
function afterRemember(): void
{
    $command = ansible_preflight_command('');
    deploy_remote_secret_remember($db, 1, 2, $dir);
    deploy_remote_secret_sweep($connection, $credential, $secret, 1);
}
PHP;

        self::assertSame(
            [
                'inOrder' => ['after_preflight' => true, 'before_remember' => true],
                'beforePreflight' => ['after_preflight' => false, 'before_remember' => true],
                'afterRemember' => ['after_preflight' => true, 'before_remember' => false],
            ],
            self::sweepOrderAnalysis($source, ['ansible_preflight_command', 'deploy_worker_run_host_preflight'])
        );
    }

    /** Astra mutation 2: the real mission worker with its sweep moved before the preflight or behind the upload. */
    public function testTheSweepOrderScannerCatchesTheRealMissionWorkerWithItsSweepMoved(): void
    {
        $mission = self::libSource('lib/deploy_worker_mission.php');
        $anchors = self::preflightAnchors();
        self::assertContains('deploy_worker_run_host_preflight', $anchors, 'Zero match: the mission preflight is not derived as an anchor.');
        self::assertSame(['after_preflight' => true, 'before_remember' => true], self::sweepOrderAnalysis($mission, $anchors)['deploy_worker_process_job'] ?? null);

        $lines = explode("\n", $mission);
        $sweep = self::lineCalling($lines, self::SWEEP);
        $preflight = self::lineCalling($lines, 'deploy_worker_run_host_preflight');
        $upload = self::lineCalling($lines, self::UPLOAD);

        $early = $lines;
        unset($early[$sweep]);
        array_splice($early, $preflight, 0, [$lines[$sweep]]);
        self::assertFalse(self::sweepOrderAnalysis(implode("\n", $early), $anchors)['deploy_worker_process_job']['after_preflight']);

        $late = $lines;
        array_splice($late, $upload + 1, 0, [$lines[$sweep]]);
        unset($late[$sweep]);
        self::assertFalse(self::sweepOrderAnalysis(implode("\n", $late), $anchors)['deploy_worker_process_job']['before_remember']);
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

    /** @return array<string, bool> "lib/file.php::function" => it releases in a finally block, for every function that remembers */
    private static function libFinallyAnalysis(): array
    {
        $analysis = [];
        foreach (self::libFiles() as $relative => $path) {
            foreach (self::finallyAnalysis((string) file_get_contents($path)) as $function => $answered) {
                $analysis[$relative . '::' . $function] = $answered;
            }
        }

        return $analysis;
    }

    /**
     * @return array<string, bool> function name => a release or worker cleanup call
     *     stands inside a finally block of the same function, for every function
     *     that calls deploy_remote_secret_remember()
     */
    private static function finallyAnalysis(string $source): array
    {
        if (!str_contains($source, self::REMEMBER)) {
            return [];
        }
        $tokens = self::tokenize($source);
        $functions = self::functionRanges($tokens);
        $finallyBlocks = self::finallyBlocks($tokens);
        $releases = [...self::callSites($tokens, self::RELEASE), ...self::callSites($tokens, self::WORKER_CLEANUP)];

        $analysis = [];
        foreach (self::callSites($tokens, self::REMEMBER) as $remember) {
            $owner = self::enclosingFunction($functions, $remember);
            if ($owner === null || isset($analysis[$owner['name']])) {
                continue;
            }
            $answered = false;
            foreach ($releases as $release) {
                if ($release < $owner['start'] || $release > $owner['end']) {
                    continue;
                }
                foreach ($finallyBlocks as [$open, $close]) {
                    if ($release > $open && $release < $close) {
                        $answered = true;
                    }
                }
            }
            $analysis[$owner['name']] = $answered;
        }

        return $analysis;
    }

    /** @return list<array{0: int, 1: int}> token indexes of the braces of every finally block */
    private static function finallyBlocks(array $tokens): array
    {
        $blocks = [];
        foreach ($tokens as $index => $token) {
            if ($token[0] !== T_FINALLY) {
                continue;
            }
            $open = self::nextSignificant($tokens, $index + 1);
            if ($open === null || $tokens[$open][1] !== '{') {
                continue;
            }
            $depth = 0;
            for ($close = $open, $count = count($tokens); $close < $count; $close++) {
                if ($tokens[$close][1] === '{' || $tokens[$close][0] === T_CURLY_OPEN || $tokens[$close][0] === T_DOLLAR_OPEN_CURLY_BRACES) {
                    $depth++;
                } elseif ($tokens[$close][1] === '}') {
                    $depth--;
                    if ($depth === 0) {
                        break;
                    }
                }
            }
            $blocks[] = [$open, $close];
        }

        return $blocks;
    }

    /** @return array<string, array{after_preflight: bool, before_remember: bool}> "lib/file.php::function" for every sweep caller */
    private static function libSweepOrderAnalysis(): array
    {
        $anchors = self::preflightAnchors();
        $analysis = [];
        foreach (self::libFiles() as $relative => $path) {
            foreach (self::sweepOrderAnalysis((string) file_get_contents($path), $anchors) as $function => $order) {
                $analysis[$relative . '::' . $function] = $order;
            }
        }

        return $analysis;
    }

    /**
     * The calls that mean "the host preflight ran", derived instead of listed:
     * ansible_preflight_command() and every named lib function that calls one of
     * them, transitively, except a function that sweeps itself. Such a function
     * (the inventory job, the full test) is a subject of the rule, not a preflight:
     * the mission worker dispatches to the inventory job long before its own preflight.
     *
     * The transitive step is what makes the synchronous credential test an anchor:
     * credential_test_connection() reaches the preflight through credential_test_ansible().
     * testTheSynchronousTestEntryReachesThePreflightThroughItsOwnChain pins that chain.
     *
     * @return list<string>
     */
    private static function preflightAnchors(): array
    {
        $files = self::libFiles();
        $sweepers = array_map(static fn (string $caller): string => explode('::', $caller, 2)[1], self::callersOf(self::SWEEP, $files));
        $anchors = [self::PREFLIGHT_COMMAND];
        $pending = [self::PREFLIGHT_COMMAND];
        while ($pending !== []) {
            $name = array_shift($pending);
            foreach (self::callersOf($name, $files) as $caller) {
                $function = explode('::', $caller, 2)[1];
                if ($function !== '(file scope)' && !in_array($function, $sweepers, true) && !in_array($function, $anchors, true)) {
                    $anchors[] = $function;
                    $pending[] = $function;
                }
            }
        }

        return array_values(array_unique($anchors));
    }

    /**
     * K21-F: the synchronous full test is an anchor only because it really reaches
     * the host preflight. This pins that chain in the source, so the derived anchor
     * cannot outlive it without a red test.
     */
    public function testTheSynchronousTestEntryReachesThePreflightThroughItsOwnChain(): void
    {
        self::assertContains(
            'credential_test_connection',
            self::preflightAnchors(),
            'credential_test_connection() is no longer derived as a preflight anchor, so the sweep in ansible_full_test_execute() has no preflight before it.'
        );
        $ssh = self::libSource('lib/ssh.php');
        self::assertStringContainsString(
            'credential_test_ansible(',
            self::functionCode($ssh, 'credential_test_connection'),
            'credential_test_connection() no longer runs credential_test_ansible(), which holds the host preflight.'
        );
        self::assertStringContainsString(
            self::PREFLIGHT_COMMAND . '(',
            self::functionCode($ssh, 'credential_test_ansible'),
            'credential_test_ansible() no longer builds the host preflight command.'
        );
    }

    /**
     * K21-F: both ok results of the credential test carry the timer state for the
     * page's hint (plain ok and allowlist warning), read from the same preflight output.
     */
    public function testTheCredentialTestReportsTheTimerStateOnItsOkResults(): void
    {
        $code = self::functionCode(self::libSource('lib/ssh.php'), 'credential_test_ansible');
        self::assertStringContainsString('ansible_secret_cleanup_timer_state(', $code);
        self::assertSame(
            2,
            substr_count($code, "'secret_cleanup' =>"),
            'Both ok results of credential_test_ansible() must carry the timer state in their context.'
        );
    }

    /**
     * @param list<string> $anchors
     * @return array<string, array{after_preflight: bool, before_remember: bool}> function name => order of its first sweep call
     */
    private static function sweepOrderAnalysis(string $source, array $anchors): array
    {
        if (!str_contains($source, self::SWEEP)) {
            return [];
        }
        $tokens = self::tokenize($source);
        $functions = self::functionRanges($tokens);
        $preflights = [];
        foreach ($anchors as $anchor) {
            $preflights = [...$preflights, ...self::callSites($tokens, $anchor)];
        }
        $remembers = self::callSites($tokens, self::REMEMBER);

        $analysis = [];
        foreach (self::callSites($tokens, self::SWEEP) as $sweep) {
            $owner = self::enclosingFunction($functions, $sweep);
            if ($owner === null || isset($analysis[$owner['name']])) {
                continue;
            }
            $inOwner = static fn (int $site): bool => $site >= $owner['start'] && $site <= $owner['end'];
            $analysis[$owner['name']] = [
                'after_preflight' => array_filter($preflights, static fn (int $site): bool => $inOwner($site) && $site < $sweep) !== [],
                'before_remember' => array_filter($remembers, static fn (int $site): bool => $inOwner($site) && $site < $sweep) === [],
            ];
        }

        return $analysis;
    }

    private static function libSource(string $relative): string
    {
        $source = (string) file_get_contents(dirname(__DIR__, 2) . '/' . $relative);
        self::assertNotSame('', $source, 'Zero match: ' . $relative . ' is missing or empty.');

        return $source;
    }

    /** The source without every line that calls $name. */
    private static function withoutLinesCalling(string $source, string $name): string
    {
        $kept = array_filter(explode("\n", $source), static fn (string $line): bool => !str_contains($line, $name . '('));
        self::assertNotSame(substr_count($source, "\n") + 1, count($kept), 'Zero match: no line calls ' . $name . '().');

        return implode("\n", $kept);
    }

    /** @param list<string> $lines @return int index of the first line that calls $name */
    private static function lineCalling(array $lines, string $name): int
    {
        foreach ($lines as $index => $line) {
            if (str_contains($line, $name . '(') && preg_match('~^\s*(\*|/\*|//|#)~', $line) !== 1) {
                return $index;
            }
        }
        self::fail('Zero match: no line calls ' . $name . '().');
    }
}

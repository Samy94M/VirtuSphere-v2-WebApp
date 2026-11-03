<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * The fence of the ESXi access file on its Ansible credential (K21-F).
 *
 * While a row of deploy_remote_secret_files exists for an Ansible credential, the
 * file may still be on the host the credential points at, and the contact that
 * removes it reaches that host through this credential. So the credential cannot
 * be deleted, and its target (type, host, effective SSH port, pinned host key)
 * cannot move, while such a row exists. Every writer of those columns is DERIVED
 * by scanning lib/ with the PHP tokenizer and must call
 * repo_remote_secret_fence_credential_change() in its own function body. Only the
 * exemptions below are named, each with its reason. The scanner has its own tests
 * on inline fixtures, so a scan that matches nothing fails instead of passing.
 */
final class RemoteSecretFenceContractTest extends TestCase
{
    private const FENCE = 'repo_remote_secret_fence_credential_change';
    private const STATUS_QUERY = 'deploy_create_status_query_outside_job';
    private const STATUS_QUERY_FILE = 'lib/deploy_create_status_outside_job.php';
    private const TABLE = 'deploy_credentials';
    private const TARGET_COLUMNS = 'type|host|port|ansible_host_fingerprint';

    /**
     * Writers that change the target without the fence, keyed "lib-relative file::function".
     * An exemption expires as soon as its writer calls the fence or disappears.
     */
    private const EXEMPT_WRITERS = [
        'lib/repo/credential_host_identity.php::repo_verify_ansible_host_identity' =>
            'Automatic first pin (NULL to a key), reached only while ansible_host_accept_new = 1. That flag is 1 only on credentials '
            . 'that were never pinned: the first pin, the reset of a host change and the confirmation all set it to 0. A row is written '
            . 'only after a pin, because the mission and inventory workers run the SSH host guard before their write-ahead row. The one '
            . 'writer of a row before any pin is the status query outside the job, which has no caller (see '
            . 'testTheFirstPinExemptionRestsOnTheStatusQueryHavingNoCaller).',
    ];

    public function testEveryLibFunctionThatChangesTheTargetOfACredentialOrDeletesItCallsTheFence(): void
    {
        $analysis = self::libWriterAnalysis();

        self::assertNotSame(
            [],
            $analysis['writers'],
            'Zero match: no function under lib/ updates the target columns of deploy_credentials or deletes from it, so this contract would pass on nothing.'
        );
        self::assertSame(
            [],
            $analysis['strays'],
            'A write of deploy_credentials sits outside any named function, where no fence call can share its body: ' . implode(', ', $analysis['strays'])
        );

        $unfenced = [];
        foreach ($analysis['writers'] as $writer => $fenced) {
            if (!$fenced && !array_key_exists($writer, self::EXEMPT_WRITERS)) {
                $unfenced[] = $writer;
            }
        }
        self::assertSame(
            [],
            $unfenced,
            'These functions change the target of a credential or delete it without ' . self::FENCE . '() in their own body: '
                . implode(', ', $unfenced)
        );
    }

    public function testTheExemptWritersStillWriteTheTargetAndTheirReasonsStillHold(): void
    {
        $writers = self::libWriterAnalysis()['writers'];

        foreach (self::EXEMPT_WRITERS as $writer => $reason) {
            self::assertNotSame('', trim($reason), 'Exemption without a reason: ' . $writer);
            self::assertArrayHasKey(
                $writer,
                $writers,
                'The exemption names a writer that no longer changes the target of a credential; remove the exemption: ' . $writer
            );
            self::assertFalse(
                $writers[$writer],
                'The exempt writer calls the fence now; remove its exemption in the same change: ' . $writer
            );
        }
    }

    /**
     * The exemption of the automatic first pin holds only while the status query
     * outside the job, the one writer of a row before any pin, has no caller. A
     * caller would have to pin first, and this test then asks for a review.
     */
    public function testTheFirstPinExemptionRestsOnTheStatusQueryHavingNoCaller(): void
    {
        $statusFile = dirname(__DIR__, 2) . '/' . self::STATUS_QUERY_FILE;
        self::assertStringContainsString(
            'function ' . self::STATUS_QUERY . '(',
            (string) file_get_contents($statusFile),
            'Zero match: ' . self::STATUS_QUERY . '() is not defined in ' . self::STATUS_QUERY_FILE . ', so the first-pin exemption rests on nothing.'
        );

        $callers = [];
        foreach (self::libFiles() as $relative => $path) {
            foreach (self::callOwnersIn((string) file_get_contents($path), self::STATUS_QUERY) as $owner) {
                $callers[] = $relative . '::' . $owner;
            }
        }
        self::assertSame(
            [],
            $callers,
            'The status query outside the job has a caller now. It writes its row before any host pin, so the first-pin exemption '
                . 'of EXEMPT_WRITERS no longer holds for it; review that exemption before this caller is accepted: ' . implode(', ', $callers)
        );
    }

    public function testTheScannerFindsTheKnownCredentialWritersOfTheRepository(): void
    {
        $writers = self::libWriterAnalysis()['writers'];

        foreach ([
            'lib/repo/credentials.php::repo_update_credential',
            'lib/repo/credentials.php::repo_delete_credential',
            'lib/repo/credential_host_identity.php::repo_confirm_ansible_host_identity',
            'lib/repo/credential_host_identity.php::repo_verify_ansible_host_identity',
        ] as $writer) {
            self::assertArrayHasKey(
                $writer,
                $writers,
                'The scanner misses a known writer, so the contract above would pass without it: ' . $writer
            );
        }
    }

    /**
     * The write-ahead row reads the credential FOR UPDATE in the same transaction as its
     * insert, so a concurrent target change waits for it (credential, then row).
     */
    public function testTheWriteAheadRowLocksTheCredentialInItsOwnTransaction(): void
    {
        $code = self::functionCode(
            (string) file_get_contents(dirname(__DIR__, 2) . '/lib/deploy_remote_secret.php'),
            'deploy_remote_secret_remember'
        );

        self::assertNotSame('', $code, 'Zero match: deploy_remote_secret_remember() was not found in lib/deploy_remote_secret.php.');
        self::assertStringContainsString('repo_transaction(', $code, 'The target read and the row insert must share one transaction.');
        self::assertStringContainsString(
            'deploy_remote_secret_target($db,$ansibleCredentialId,true)',
            str_replace(' ', '', $code),
            'The target must be read FOR UPDATE, so the credential is locked before the row is written.'
        );
    }

    public function testTheWriterScannerTellsAnUnfencedUpdateAndDeleteFromAFencedOne(): void
    {
        $source = <<<'PHP'
<?php
function unfencedHostWrite(mysqli $db): void
{
    $db->query("UPDATE deploy_credentials SET host = 'x' WHERE id = 1");
}
function fencedHostWrite(mysqli $db): void
{
    repo_remote_secret_fence_credential_change($db, 1, null);
    $db->query("UPDATE deploy_credentials SET host = 'x' WHERE id = 1");
}
function unfencedDelete(mysqli $db): void
{
    $db->query('DELETE FROM deploy_credentials WHERE id = 1');
}
PHP;

        $analysis = self::writerAnalysis($source);

        self::assertSame(
            ['unfencedHostWrite' => false, 'fencedHostWrite' => true, 'unfencedDelete' => false],
            $analysis['writers']
        );
        self::assertSame(0, $analysis['strays']);
    }

    public function testTheWriterScannerIgnoresWhereClausesCommentsAndColumnsThatAreNoTarget(): void
    {
        $source = <<<'PHP'
<?php
function whereOnly(mysqli $db): void
{
    $db->query("UPDATE deploy_credentials SET esxi_trust_mode = 'strict', updated_at = NOW() WHERE id = ? AND type = 'esxi'");
}
function observedOnly(mysqli $db): void
{
    $db->query('UPDATE deploy_credentials SET ansible_host_observed_fingerprint = ?, ansible_host_observed_type = ? WHERE id = ?');
}
function commentOnly(): void
{
    // UPDATE deploy_credentials SET host = 'x' WHERE id = 1
    /* DELETE FROM deploy_credentials WHERE id = 1 */
}
function otherTable(mysqli $db): void
{
    $db->query("UPDATE deploy_credential_notes SET host = 'x' WHERE id = 1");
}
PHP;

        $analysis = self::writerAnalysis($source);

        self::assertSame([], $analysis['writers']);
        self::assertSame(0, $analysis['strays']);
    }

    public function testTheWriterScannerReadsAStatementSplitAcrossLiteralsAndAHeredoc(): void
    {
        $source = <<<'PHP'
<?php
function splitWrite(mysqli $db): void
{
    $db->query('UPDATE deploy_credentials SET ' . 'port = ? WHERE id = ?');
}
function splitFenced(mysqli $db): void
{
    repo_remote_secret_fence_credential_change($db, 1, null);
    $db->query('UPDATE deploy_credentials SET '
        . 'ansible_host_fingerprint = NULL WHERE id = ?');
}
function heredocWrite(mysqli $db): void
{
    $db->query(<<<'SQL'
UPDATE deploy_credentials SET type = 'ansible' WHERE id = 1
SQL);
}
PHP;

        $analysis = self::writerAnalysis($source);

        self::assertSame(
            ['splitWrite' => false, 'splitFenced' => true, 'heredocWrite' => false],
            $analysis['writers']
        );
    }

    public function testTheWriterScannerReportsAWriteThatIsOutsideAnyFunction(): void
    {
        $analysis = self::writerAnalysis("<?php\n\$db->query(\"UPDATE deploy_credentials SET host = 'x' WHERE id = 1\");\n");

        self::assertSame([], $analysis['writers']);
        self::assertSame(1, $analysis['strays']);
    }

    /**
     * @return array{writers: array<string, bool>, strays: list<string>}
     *     writers: "lib-relative file::function" => the function calls the fence in its own body
     *     strays: lib-relative files with a write outside any named function
     */
    private static function libWriterAnalysis(): array
    {
        $writers = [];
        $strays = [];
        foreach (self::libFiles() as $relative => $path) {
            $analysis = self::writerAnalysis((string) file_get_contents($path));
            foreach ($analysis['writers'] as $function => $fenced) {
                $writers[$relative . '::' . $function] = $fenced;
            }
            if ($analysis['strays'] > 0) {
                $strays[] = $relative;
            }
        }
        ksort($writers);

        return ['writers' => $writers, 'strays' => $strays];
    }

    /**
     * @return array{writers: array<string, bool>, strays: int}
     *     writers: function name => the function calls the fence in its own body
     *     strays: writes of deploy_credentials outside any named function
     */
    private static function writerAnalysis(string $source): array
    {
        // A source that never spells the table cannot write it. Skipping it before
        // tokenizing keeps the memory of a run to the files that matter.
        if (!str_contains(strtolower($source), self::TABLE)) {
            return ['writers' => [], 'strays' => 0];
        }
        $tokens = self::tokenize($source);
        $functions = self::functionRanges($tokens);

        $writing = [];
        $strays = 0;
        foreach (self::targetWriteSites($tokens) as $site) {
            $owner = self::enclosingFunction($functions, $site);
            if ($owner === null) {
                $strays++;
            } else {
                $writing[$owner['name']] = true;
            }
        }

        $fenced = [];
        foreach (self::callSites($tokens, self::FENCE) as $site) {
            $owner = self::enclosingFunction($functions, $site);
            if ($owner !== null) {
                $fenced[$owner['name']] = true;
            }
        }

        $writers = [];
        foreach (array_keys($writing) as $name) {
            $writers[$name] = isset($fenced[$name]);
        }

        return ['writers' => $writers, 'strays' => $strays];
    }

    /**
     * Token indexes of the first token of every SQL string that writes deploy_credentials
     * target columns or deletes from it. Adjacent string literals joined with "." or
     * split by whitespace or comments form one statement; any other token ends it.
     *
     * @return list<int>
     */
    private static function targetWriteSites(array $tokens): array
    {
        $sites = [];
        $group = null;
        foreach ($tokens as $index => $token) {
            if ($token[0] === T_CONSTANT_ENCAPSED_STRING || $token[0] === T_ENCAPSED_AND_WHITESPACE) {
                $group ??= ['start' => $index, 'sql' => ''];
                $group['sql'] .= $token[0] === T_CONSTANT_ENCAPSED_STRING ? substr($token[1], 1, -1) : $token[1];
                continue;
            }
            if ($group !== null && self::continuesStatement($token)) {
                continue;
            }
            if ($group !== null && self::isTargetWrite($group['sql'])) {
                $sites[] = $group['start'];
            }
            $group = null;
        }
        if ($group !== null && self::isTargetWrite($group['sql'])) {
            $sites[] = $group['start'];
        }

        return $sites;
    }

    /** @param array{0: int|null, 1: string} $token */
    private static function continuesStatement(array $token): bool
    {
        return in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true) || $token[1] === '.';
    }

    /**
     * True for a DELETE from deploy_credentials, and for an UPDATE whose SET clause
     * (not its WHERE clause) names one of the target columns.
     */
    private static function isTargetWrite(string $sql): bool
    {
        if (preg_match('/\bDELETE\s+FROM\s+`?' . self::TABLE . '`?(?!\w)/i', $sql) === 1) {
            return true;
        }
        if (preg_match('/\bUPDATE\s+`?' . self::TABLE . '`?(?!\w)/i', $sql) !== 1
            || preg_match('/\bSET\b(.*?)(?=\bWHERE\b|$)/is', $sql, $set) !== 1
        ) {
            return false;
        }

        return preg_match('/\b(?:' . self::TARGET_COLUMNS . ')\s*=/i', $set[1]) === 1;
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

    /**
     * The function names that call $name in one source, '(file scope)' for a call
     * outside any function. A declaration of $name is not a call.
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

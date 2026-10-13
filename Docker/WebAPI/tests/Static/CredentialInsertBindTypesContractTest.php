<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Every INSERT INTO deploy_credentials binds each placeholder with the type its
 * column has in the fresh schema (K21-N4).
 *
 * mysqli reads the bind string by position. 'ssisss' on (type, name, host, port,
 * username, secret_ciphertext) hands host to MySQL as an integer, which stores
 * '0', and port as a string. The row is still written, so the defect shows up as
 * wrong data far from the insert instead of as an error at the insert.
 *
 * Neither the column types nor the insert sites are listed here. The types are
 * parsed from the CREATE TABLE in Docker/mysql/mysql-init/struktur.sql, and every
 * insert under Docker/WebAPI is derived with the PHP tokenizer, in both shapes the
 * repo uses: a prepare() whose statement is bound on the same variable, and a
 * helper call that takes (db, sql, types, params). An insert whose bind string
 * cannot be read is a finding, never a skip. The scanner has its own tests on
 * inline fixtures, so a scan that matches nothing fails instead of passing.
 */
final class CredentialInsertBindTypesContractTest extends TestCase
{
    private const TABLE = 'deploy_credentials';

    private const INSERT_PATTERN = '/\b(?:INSERT|REPLACE)\b(?:\s+IGNORE)?\s+INTO\s+`?' . self::TABLE . '`?(?!\w)/i';

    /**
     * This file's fixtures are the scanner's own inputs, which contain the insert
     * text on purpose. Only this file is excluded; every other file is scanned.
     */
    private const OWN_FILE = 'tests/Static/CredentialInsertBindTypesContractTest.php';

    /** Columns for the scanner self-tests only; the real checks read the fresh schema. */
    private const FIXTURE_SCHEMA = <<<'SQL'
CREATE TABLE IF NOT EXISTS deploy_credentials (
    id INT AUTO_INCREMENT PRIMARY KEY,
    type ENUM('esxi','ansible') NOT NULL,
    name VARCHAR(191) NOT NULL,
    host VARCHAR(255) NOT NULL,
    port INT NULL,
    username VARCHAR(191) NOT NULL,
    secret_ciphertext TEXT NOT NULL,
    created_by INT NULL,
    ansible_host_accept_new TINYINT UNSIGNED NOT NULL DEFAULT 0,
    UNIQUE KEY credential_name_type_unique (type, name),
    CONSTRAINT fk_deploy_credentials_created_by FOREIGN KEY (created_by) REFERENCES deploy_users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL;

    public function testEveryInsertIntoDeployCredentialsBindsEachPlaceholderWithItsColumnType(): void
    {
        $scan = self::scanRepository();

        self::assertGreaterThan(
            0,
            $scan['inserts'],
            'Zero match: no INSERT INTO deploy_credentials was found under Docker/WebAPI, so this contract would pass on nothing.'
        );
        self::assertCount(
            0,
            $scan['findings'],
            sprintf(
                "%d of %d INSERT INTO deploy_credentials statements bind a placeholder with the wrong type (%d position findings):\n%s",
                $scan['badInserts'],
                $scan['inserts'],
                count($scan['findings']),
                implode("\n", $scan['findings'])
            )
        );
    }

    public function testTheFreshSchemaGivesEveryDeployCredentialsColumnABindCharacter(): void
    {
        $columns = self::columnBindCharacters(self::schemaSource());

        self::assertNotSame(
            [],
            $columns,
            'Zero match: the fresh schema has no CREATE TABLE deploy_credentials, so no insert could be checked against it.'
        );
        foreach ($columns as $column => $character) {
            self::assertContains($character, ['s', 'i', 'd'], "Column $column maps to an unknown bind character.");
        }
    }

    public function testTheSchemaParserReadsTheColumnsOfTheCreateTableAndNoConstraint(): void
    {
        self::assertSame(
            [
                'id' => 'i',
                'type' => 's',
                'name' => 's',
                'host' => 's',
                'port' => 'i',
                'username' => 's',
                'secret_ciphertext' => 's',
                'created_by' => 'i',
                'ansible_host_accept_new' => 'i',
            ],
            self::columnBindCharacters(self::FIXTURE_SCHEMA)
        );
    }

    public function testAPrepareFormBindStringWithHostAndPortSwappedIsReportedPerColumn(): void
    {
        $source = <<<'PHP'
        <?php
        function fixtureWrongPrepare($db, $type, $name, $host, $port, $user, $secret): void
        {
            $stmt = $db->prepare('INSERT INTO deploy_credentials (type, name, host, port, username, secret_ciphertext) VALUES (?, ?, ?, ?, ?, ?)');
            $stmt->bind_param('ssisss', $type, $name, $host, $port, $user, $secret);
        }
        PHP;

        $scan = self::fixtureScan($source);

        self::assertSame(1, $scan['inserts']);
        self::assertSame(1, $scan['badInserts']);
        self::assertSame(
            [
                "fixture.php:4 (bind string at line 5): column host, position 3: expected 's', found 'i'.",
                "fixture.php:4 (bind string at line 5): column port, position 4: expected 'i', found 's'.",
            ],
            $scan['findings']
        );
    }

    public function testTheCorrectPrepareFormBindStringIsNotReported(): void
    {
        $source = <<<'PHP'
        <?php
        function fixtureRightPrepare($db, $type, $name, $host, $port, $user, $secret): void
        {
            $stmt = $db->prepare('INSERT INTO deploy_credentials (type, name, host, port, username, secret_ciphertext) VALUES (?, ?, ?, ?, ?, ?)');
            $stmt->bind_param('sssiss', $type, $name, $host, $port, $user, $secret);
        }
        PHP;

        $scan = self::fixtureScan($source);

        self::assertSame(1, $scan['inserts']);
        self::assertSame(0, $scan['badInserts']);
        self::assertSame([], $scan['findings']);
    }

    public function testARepoExecuteFormWithTheWrongTypeIsReported(): void
    {
        $source = <<<'PHP'
        <?php
        function fixtureWrongExecute($db, $type, $name, $host, $port, $user, $secret): void
        {
            repo_execute($db, 'INSERT INTO deploy_credentials (type,name,host,port,username,secret_ciphertext) VALUES (?,?,?,?,?,?)', 'ssisss', [$type, $name, $host, $port, $user, $secret]);
        }
        PHP;

        $scan = self::fixtureScan($source);

        self::assertSame(1, $scan['inserts']);
        self::assertSame(1, $scan['badInserts']);
        self::assertSame(
            [
                "fixture.php:4 (bind string at line 4): column host, position 3: expected 's', found 'i'.",
                "fixture.php:4 (bind string at line 4): column port, position 4: expected 'i', found 's'.",
            ],
            $scan['findings']
        );
    }

    /**
     * Literal values in VALUES, such as the 0 that credentials.php writes for
     * ansible_host_accept_new, take no placeholder and no bind character. The
     * double-quoted form with its own literals is read the same way.
     */
    public function testLiteralValuesAreSkippedAndOnlyPlaceholdersNeedABindCharacter(): void
    {
        $source = <<<'PHP'
        <?php
        function fixtureLiterals($db, $type, $name, $host, $port, $user, $secret): void
        {
            $stmt = $db->prepare('INSERT INTO deploy_credentials (type, name, host, port, username, secret_ciphertext, ansible_host_accept_new) VALUES (?, ?, ?, ?, ?, ?, 0)');
            $stmt->bind_param('sssiss', $type, $name, $host, $port, $user, $secret);
            $stmt = $db->prepare("INSERT INTO deploy_credentials (type,name,host,username,secret_ciphertext) VALUES ('esxi', ?, ?, 'fixture', 'unused')");
            $stmt->bind_param('ss', $name, $host);
        }
        PHP;

        $scan = self::fixtureScan($source);

        self::assertSame(2, $scan['inserts']);
        self::assertSame(0, $scan['badInserts']);
        self::assertSame([], $scan['findings']);
    }

    public function testAShortBindStringIsReportedAsALengthMismatch(): void
    {
        $source = <<<'PHP'
        <?php
        function fixtureShort($db, $type, $name, $host, $port, $user, $secret): void
        {
            $stmt = $db->prepare('INSERT INTO deploy_credentials (type, name, host, port, username, secret_ciphertext) VALUES (?, ?, ?, ?, ?, ?)');
            $stmt->bind_param('sssis', $type, $name, $host, $port, $user, $secret);
        }
        PHP;

        $scan = self::fixtureScan($source);

        self::assertSame(1, $scan['badInserts']);
        self::assertSame(
            ["fixture.php:4 (bind string at line 5): bind string 'sssis' has 5 characters, the INSERT has 6 placeholders."],
            $scan['findings']
        );
    }

    /**
     * An insert whose bind string cannot be read is reported with its location and
     * the reason, one finding per insert. Three shapes: a bind string held in a
     * variable, interpolated SQL, and an insert passed to a method as its first
     * argument.
     */
    public function testBindStringsThatCannotBeReadAreFindingsNotSkips(): void
    {
        $source = <<<'PHP'
        <?php
        function fixtureUnreadable($db, $types, $type, $name, $host, $port, $user, $secret): void
        {
            $stmt = $db->prepare('INSERT INTO deploy_credentials (type, name, host, port, username, secret_ciphertext) VALUES (?, ?, ?, ?, ?, ?)');
            $stmt->bind_param($types, $type, $name, $host, $port, $user, $secret);
            $stmt = $db->prepare("INSERT INTO deploy_credentials (type, name) VALUES ('esxi', $name)");
            $stmt->bind_param('s', $name);
            $this->store('INSERT INTO deploy_credentials (type, name) VALUES (?, ?)', 'ss', [$type, $name]);
        }
        PHP;

        $scan = self::fixtureScan($source);

        self::assertSame(3, $scan['inserts']);
        self::assertSame(3, $scan['badInserts']);
        self::assertSame(
            [
                'fixture.php:4: INSERT INTO deploy_credentials, bind string not determinable: the bind_param() on line 5 takes no string literal as its first argument.',
                'fixture.php:6: INSERT INTO deploy_credentials, bind string not determinable: the SQL is interpolated or not one string literal.',
                'fixture.php:8: INSERT INTO deploy_credentials, bind string not determinable: unknown insert form (neither ->prepare() nor a function call with the SQL as its second argument).',
            ],
            $scan['findings']
        );
    }

    /** Inserts into other tables and selects on this table are not checked, and a scan that finds none counts zero. */
    public function testInsertsIntoOtherTablesAreIgnoredAndAScanWithoutAnyInsertCountsZero(): void
    {
        $source = <<<'PHP'
        <?php
        function fixtureOther($db, $type, $name, $host, $port, $user, $secret): void
        {
            $stmt = $db->prepare('INSERT INTO remote_inventory (type, name, host, port, username, secret_ciphertext) VALUES (?, ?, ?, ?, ?, ?)');
            $stmt->bind_param('ssisss', $type, $name, $host, $port, $user, $secret);
            $stmt = $db->prepare('SELECT id FROM deploy_credentials WHERE host = ?');
            $stmt->bind_param('s', $host);
        }
        PHP;

        $scan = self::fixtureScan($source);

        self::assertSame(0, $scan['inserts']);
        self::assertSame(0, $scan['badInserts']);
        self::assertSame([], $scan['findings']);
    }

    /**
     * The scan of the real repository: every file under Docker/WebAPI except vendor/
     * and this file's own fixtures, with the column types of the fresh schema.
     *
     * @return array{inserts: int, badInserts: int, findings: list<string>}
     */
    private static function scanRepository(): array
    {
        $schema = self::columnBindCharacters(self::schemaSource());
        $total = ['inserts' => 0, 'badInserts' => 0, 'findings' => []];
        foreach (self::phpFiles() as $relative => $path) {
            if ($relative === self::OWN_FILE) {
                continue;
            }
            $scan = self::insertFindings($relative, (string) file_get_contents($path), $schema);
            $total['inserts'] += $scan['inserts'];
            $total['badInserts'] += $scan['badInserts'];
            $total['findings'] = array_merge($total['findings'], $scan['findings']);
        }

        return $total;
    }

    /** @return array{inserts: int, badInserts: int, findings: list<string>} */
    private static function fixtureScan(string $source): array
    {
        return self::insertFindings('fixture.php', $source, self::columnBindCharacters(self::FIXTURE_SCHEMA));
    }

    private static function schemaSource(): string
    {
        $source = file_get_contents(dirname(__DIR__, 3) . '/mysql/mysql-init/struktur.sql');
        if ($source === false) {
            throw new RuntimeException('The fresh schema struktur.sql is not readable, so the bind types cannot be derived.');
        }

        return $source;
    }

    /**
     * @return array<string, string> path relative to Docker/WebAPI => absolute path (forward slashes); vendor/ is not walked
     */
    private static function phpFiles(): array
    {
        $root = str_replace('\\', '/', dirname(__DIR__, 2));
        $directories = new RecursiveDirectoryIterator(dirname(__DIR__, 2), FilesystemIterator::SKIP_DOTS);
        $walk = new RecursiveIteratorIterator(new RecursiveCallbackFilterIterator(
            $directories,
            static fn (SplFileInfo $entry): bool => !($entry->isDir() && $entry->getFilename() === 'vendor')
        ));

        $files = [];
        foreach ($walk as $path => $entry) {
            if (!$entry->isFile() || $entry->getExtension() !== 'php') {
                continue;
            }
            $normalized = str_replace('\\', '/', (string) $path);
            $files[substr($normalized, strlen($root) + 1)] = $normalized;
        }
        ksort($files);

        return $files;
    }

    /**
     * @return array<string, string> column => bind character, from the CREATE TABLE of TABLE only
     */
    private static function columnBindCharacters(string $schema): array
    {
        if (preg_match('/CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?`?' . self::TABLE . '`?\s*\((.*?)^\)/ims', $schema, $table) !== 1) {
            return [];
        }

        $columns = [];
        foreach (preg_split('/\R/', $table[1]) ?: [] as $line) {
            $definition = rtrim(trim($line), ',');
            if ($definition === '' || str_starts_with($definition, '--')) {
                continue;
            }
            if (preg_match('/^(?:CONSTRAINT|PRIMARY|UNIQUE|KEY|INDEX|FOREIGN|CHECK|FULLTEXT)\b/i', $definition) === 1) {
                continue;
            }
            if (preg_match('/^`?([A-Za-z_]\w*)`?\s+([A-Za-z]+)/', $definition, $column) !== 1) {
                throw new RuntimeException('The schema line is neither a column nor a constraint: ' . $definition);
            }
            $columns[strtolower($column[1])] = self::bindCharacter($column[2]);
        }

        return $columns;
    }

    private static function bindCharacter(string $sqlType): string
    {
        return match (strtoupper($sqlType)) {
            'INT', 'INTEGER', 'TINYINT', 'SMALLINT', 'MEDIUMINT', 'BIGINT', 'BOOLEAN', 'BOOL' => 'i',
            'DECIMAL', 'NUMERIC', 'FLOAT', 'DOUBLE', 'REAL' => 'd',
            default => 's',
        };
    }

    /**
     * @param array<string, string> $schema column => bind character
     * @return array{inserts: int, badInserts: int, findings: list<string>}
     */
    private static function insertFindings(string $file, string $source, array $schema): array
    {
        $tokens = self::significantTokens($source);
        $inserts = 0;
        $badInserts = 0;
        $findings = [];
        foreach ($tokens as $index => $token) {
            $isLiteral = $token['id'] === T_CONSTANT_ENCAPSED_STRING || $token['id'] === T_ENCAPSED_AND_WHITESPACE;
            if (!$isLiteral || preg_match(self::INSERT_PATTERN, $token['text']) !== 1) {
                continue;
            }

            ++$inserts;
            $location = $file . ':' . $token['line'];
            $sql = self::literalValue($token);
            $problem = '';
            $binding = null;
            if ($sql === null) {
                $problem = 'the SQL is interpolated or not one string literal';
            } else {
                $binding = self::bindingOf($tokens, $index, $problem);
            }
            $shape = $sql === null ? null : self::insertShape($sql);
            if ($binding !== null && $shape === null) {
                $problem = 'the column list, the VALUES tuple or the placeholder count does not parse';
            }
            if ($binding === null || $shape === null) {
                $findings[] = sprintf('%s: INSERT INTO deploy_credentials, bind string not determinable: %s.', $location, $problem);
                ++$badInserts;
                continue;
            }

            $mismatches = self::mismatches($location, $shape, $binding['types'], $binding['line'], $schema);
            if ($mismatches !== []) {
                ++$badInserts;
                $findings = array_merge($findings, $mismatches);
            }
        }

        return ['inserts' => $inserts, 'badInserts' => $badInserts, 'findings' => $findings];
    }

    /**
     * @param list<string> $placeholders the lowercased column of every ? in VALUES, in order
     * @param array<string, string> $schema
     * @return list<string>
     */
    private static function mismatches(string $location, array $placeholders, string $types, int $bindLine, array $schema): array
    {
        $findings = [];
        if (count($placeholders) !== strlen($types)) {
            $findings[] = sprintf(
                "%s (bind string at line %d): bind string '%s' has %d characters, the INSERT has %d placeholders.",
                $location,
                $bindLine,
                $types,
                strlen($types),
                count($placeholders)
            );
        }
        foreach ($placeholders as $position => $column) {
            if (!isset($schema[$column])) {
                $findings[] = sprintf('%s: column %s is not in the CREATE TABLE of deploy_credentials.', $location, $column);
                continue;
            }
            if (!isset($types[$position]) || $types[$position] === $schema[$column]) {
                continue;
            }
            $findings[] = sprintf(
                "%s (bind string at line %d): column %s, position %d: expected '%s', found '%s'.",
                $location,
                $bindLine,
                $column,
                $position + 1,
                $schema[$column],
                $types[$position]
            );
        }

        return $findings;
    }

    /**
     * The bind string of one insert, or null with $problem set when it cannot be read.
     *
     * @param list<array{id: int|string, text: string, line: int}> $tokens
     * @return array{types: string, line: int}|null
     */
    private static function bindingOf(array $tokens, int $index, string &$problem): ?array
    {
        if (self::charAt($tokens, $index - 1, '(') && self::methodNamedAt($tokens, $index - 2, 'prepare')) {
            $variable = self::assignedVariable($tokens, $index);
            if ($variable === null) {
                $problem = 'the prepare() result is not assigned to a variable';

                return null;
            }

            return self::bindOnVariable($tokens, $index + 1, $variable, $problem);
        }

        $call = self::enclosingCall($tokens, $index);
        if ($call === null || $call['method'] || $call['argument'] !== 1) {
            $problem = 'unknown insert form (neither ->prepare() nor a function call with the SQL as its second argument)';

            return null;
        }
        $types = $tokens[$index + 2] ?? null;
        if (!self::charAt($tokens, $index + 1, ',') || $types === null || $types['id'] !== T_CONSTANT_ENCAPSED_STRING) {
            $problem = sprintf('the third argument of %s() is not a string literal', $call['name']);

            return null;
        }
        $value = self::literalValue($types);
        if ($value === null) {
            $problem = 'the bind string is interpolated';

            return null;
        }

        return ['types' => $value, 'line' => $types['line']];
    }

    /**
     * @param list<array{id: int|string, text: string, line: int}> $tokens
     * @return array{types: string, line: int}|null
     */
    private static function bindOnVariable(array $tokens, int $from, string $variable, string &$problem): ?array
    {
        for ($j = $from, $count = count($tokens); $j < $count; ++$j) {
            if ($tokens[$j]['id'] === T_FUNCTION || self::methodNamedAt($tokens, $j, 'prepare')) {
                break;
            }
            if ($tokens[$j]['id'] !== T_VARIABLE || $tokens[$j]['text'] !== $variable
                || !self::methodNamedAt($tokens, $j + 2, 'bind_param') || !self::charAt($tokens, $j + 3, '(')) {
                continue;
            }

            $line = $tokens[$j + 2]['line'];
            $first = $tokens[$j + 4] ?? null;
            if ($first === null || $first['id'] !== T_CONSTANT_ENCAPSED_STRING) {
                $problem = sprintf('the bind_param() on line %d takes no string literal as its first argument', $line);

                return null;
            }
            $value = self::literalValue($first);
            if ($value === null) {
                $problem = sprintf('the bind_param() on line %d takes an interpolated bind string', $line);

                return null;
            }

            return ['types' => $value, 'line' => $first['line']];
        }

        $problem = sprintf('no bind_param() on %s before the next prepare() or function', $variable);

        return null;
    }

    /**
     * The lowercased column of each ? in VALUES, in order, or null when the INSERT
     * is not a plain column list with one VALUES tuple of the same length.
     *
     * @return list<string>|null
     */
    private static function insertShape(string $sql): ?array
    {
        if (preg_match('/\bINTO\s+`?' . self::TABLE . '`?(?!\w)\s*\(/i', $sql, $head, PREG_OFFSET_CAPTURE) !== 1) {
            return null;
        }
        $columnsOpen = $head[0][1] + strlen($head[0][0]) - 1;
        $columnsClose = self::closingParenthesis($sql, $columnsOpen);
        if ($columnsClose === null || preg_match('/^\s*VALUES\s*\(/i', substr($sql, $columnsClose + 1), $values) !== 1) {
            return null;
        }
        $valuesOpen = $columnsClose + 1 + strlen($values[0]) - 1;
        $valuesClose = self::closingParenthesis($sql, $valuesOpen);
        if ($valuesClose === null) {
            return null;
        }

        $columns = self::splitTopLevel(substr($sql, $columnsOpen + 1, $columnsClose - $columnsOpen - 1));
        $items = self::splitTopLevel(substr($sql, $valuesOpen + 1, $valuesClose - $valuesOpen - 1));
        if (count($columns) !== count($items)) {
            return null;
        }

        $placeholders = [];
        foreach ($items as $position => $item) {
            if ($item === '?') {
                $placeholders[] = strtolower(trim($columns[$position], " `"));
            } elseif (str_contains($item, '?')) {
                return null;
            }
        }
        if (substr_count($sql, '?') !== count($placeholders)) {
            return null;
        }

        return $placeholders;
    }

    private static function closingParenthesis(string $sql, int $open): ?int
    {
        $depth = 0;
        $quote = null;
        for ($i = $open, $length = strlen($sql); $i < $length; ++$i) {
            $char = $sql[$i];
            if ($quote !== null) {
                if ($char === $quote) {
                    $quote = null;
                }
                continue;
            }
            if ($char === "'" || $char === '"') {
                $quote = $char;
            } elseif ($char === '(') {
                ++$depth;
            } elseif ($char === ')') {
                --$depth;
                if ($depth === 0) {
                    return $i;
                }
            }
        }

        return null;
    }

    /** @return list<string> */
    private static function splitTopLevel(string $list): array
    {
        $parts = [];
        $current = '';
        $depth = 0;
        $quote = null;
        foreach (str_split($list) as $char) {
            if ($quote !== null) {
                if ($char === $quote) {
                    $quote = null;
                }
                $current .= $char;
                continue;
            }
            if ($char === "'" || $char === '"') {
                $quote = $char;
            } elseif ($char === '(') {
                ++$depth;
            } elseif ($char === ')') {
                --$depth;
            } elseif ($char === ',' && $depth === 0) {
                $parts[] = trim($current);
                $current = '';
                continue;
            }
            $current .= $char;
        }
        $parts[] = trim($current);

        return $parts;
    }

    /**
     * The value of a string literal, or null when it interpolates a variable or is
     * not a string literal at all.
     *
     * @param array{id: int|string, text: string, line: int} $token
     */
    private static function literalValue(array $token): ?string
    {
        if ($token['id'] !== T_CONSTANT_ENCAPSED_STRING) {
            return null;
        }
        $body = substr($token['text'], 1, -1);
        if ($token['text'][0] === "'") {
            return str_replace(['\\\\', "\\'"], ['\\', "'"], $body);
        }

        return str_contains($body, '$') ? null : stripcslashes($body);
    }

    /**
     * The variable that a prepare() call is assigned to, or null when the statement
     * assigns none.
     *
     * @param list<array{id: int|string, text: string, line: int}> $tokens
     */
    private static function assignedVariable(array $tokens, int $index): ?string
    {
        for ($j = $index - 1; $j >= 0; --$j) {
            $id = $tokens[$j]['id'];
            if (in_array($id, [';', '{', '}'], true)) {
                return null;
            }
            if ($id === '=' && ($tokens[$j - 1]['id'] ?? null) === T_VARIABLE) {
                return $tokens[$j - 1]['text'];
            }
        }

        return null;
    }

    /**
     * The function or method call that the token at $index is an argument of, with
     * the zero-based position of that argument. Null when the token is not inside
     * a call of its own statement.
     *
     * @param list<array{id: int|string, text: string, line: int}> $tokens
     * @return array{name: string, argument: int, method: bool}|null
     */
    private static function enclosingCall(array $tokens, int $index): ?array
    {
        $argument = 0;
        $depth = 0;
        for ($j = $index - 1; $j >= 0; --$j) {
            $id = $tokens[$j]['id'];
            if (in_array($id, [')', ']', '}'], true)) {
                ++$depth;
                continue;
            }
            if (in_array($id, ['(', '[', '{'], true)) {
                if ($depth > 0) {
                    --$depth;
                    continue;
                }
                $name = $tokens[$j - 1] ?? null;
                $before = $tokens[$j - 2] ?? null;
                if ($id !== '(' || $name === null || !in_array($name['id'], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)) {
                    return null;
                }

                return [
                    'name' => $name['text'],
                    'argument' => $argument,
                    'method' => $before !== null && in_array($before['id'], [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON], true),
                ];
            }
            if ($depth === 0 && $id === ';') {
                return null;
            }
            if ($depth === 0 && $id === ',') {
                ++$argument;
            }
        }

        return null;
    }

    /** @param list<array{id: int|string, text: string, line: int}> $tokens */
    private static function charAt(array $tokens, int $index, string $char): bool
    {
        $token = $tokens[$index] ?? null;

        return $token !== null && $token['id'] === $char;
    }

    /** @param list<array{id: int|string, text: string, line: int}> $tokens */
    private static function methodNamedAt(array $tokens, int $index, string $name): bool
    {
        $token = $tokens[$index] ?? null;
        $arrow = $tokens[$index - 1] ?? null;

        return $token !== null
            && $arrow !== null
            && $token['id'] === T_STRING
            && strtolower($token['text']) === $name
            && in_array($arrow['id'], [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR], true);
    }

    /**
     * The tokens without whitespace and comments, each with the line it starts on.
     *
     * @return list<array{id: int|string, text: string, line: int}>
     */
    private static function significantTokens(string $source): array
    {
        $tokens = [];
        $line = 1;
        foreach (token_get_all($source) as $token) {
            [$id, $text] = is_array($token) ? [$token[0], $token[1]] : [$token, $token];
            $startLine = $line;
            $line += substr_count($text, "\n");
            if (in_array($id, [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT, T_OPEN_TAG], true)) {
                continue;
            }
            $tokens[] = ['id' => $id, 'text' => $text, 'line' => $startLine];
        }

        return $tokens;
    }
}

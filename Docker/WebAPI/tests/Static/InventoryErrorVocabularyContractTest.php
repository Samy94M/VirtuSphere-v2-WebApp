<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/lib/connection_errors.php';

/** Keeps the stored inventory error vocabulary synchronized with every mirror. */
final class InventoryErrorVocabularyContractTest extends TestCase
{
    private const ANSIBLE_CODES = [
        'ansible_dns',
        'ansible_unreachable',
        'ansible_auth',
        'ansible_authz',
        'ansible_preflight',
        'ansible_config',
        'ansible_sftp',
        'ansible_timeout',
        'ansible_transport',
    ];

    public function testTheRealSourcesShareOneCompleteVocabulary(): void
    {
        $categories = VIRTUSPHERE_INVENTORY_ERROR_CATEGORIES;
        $messages = array_keys(VIRTUSPHERE_CONNECTION_MESSAGE_KEYS);
        $help = $this->helpCodesByLocale();
        $doc = $this->operationsDocCodes();
        [$schemaWidth, $migrationWidth] = $this->databaseWidths();

        self::assertSame([], $this->validateVocabulary(
            $categories,
            $messages,
            $help,
            $doc,
            $schemaWidth,
            $migrationWidth
        ));

        foreach (Lang::LOCALES as $locale) {
            Lang::load($locale);
            foreach (VIRTUSPHERE_CONNECTION_MESSAGE_KEYS as $key) {
                $text = __t($key, ['host' => 'host.example', 'port' => 443, 'status' => 500]);
                self::assertNotSame($key, $text, $locale . ' does not resolve ' . $key);
                self::assertDoesNotMatchRegularExpression('/:[a-z_][a-z0-9_]*/i', $text, $locale . ' leaves a placeholder in ' . $key);
            }
        }
    }

    public function testOriginLegacyAndPauseSemanticsAreExplicit(): void
    {
        $ansibleCodes = array_values(array_filter(
            VIRTUSPHERE_INVENTORY_ERROR_CATEGORIES,
            static fn (string $code): bool => str_starts_with($code, 'ansible_')
        ));
        self::assertSame(self::ANSIBLE_CODES, $ansibleCodes, 'The Ansible origin vocabulary is incomplete or reordered.');

        foreach (VIRTUSPHERE_INVENTORY_ERROR_CATEGORIES as $code) {
            self::assertSame(str_starts_with($code, 'ansible_'), inventory_error_is_ansible($code), $code);
            self::assertSame($code === VIRTUSPHERE_INVENTORY_ERROR_AUTH, inventory_error_pauses_credential($code), $code);
        }
        self::assertContains(VIRTUSPHERE_INVENTORY_ERROR_SSH, VIRTUSPHERE_INVENTORY_ERROR_CATEGORIES);
        self::assertContains(VIRTUSPHERE_INVENTORY_ERROR_HTTP, VIRTUSPHERE_INVENTORY_ERROR_CATEGORIES);
    }

    public function testValidatorRejectsEveryDriftDirection(): void
    {
        $categories = VIRTUSPHERE_INVENTORY_ERROR_CATEGORIES;
        $messages = array_keys(VIRTUSPHERE_CONNECTION_MESSAGE_KEYS);
        $help = $this->helpCodesByLocale();
        $doc = $this->operationsDocCodes();
        [$schemaWidth, $migrationWidth] = $this->databaseWidths();

        $fixtures = [];
        $fixtures['empty list'] = [[], $messages, $help, $doc, $schemaWidth, $migrationWidth];
        $fixtures['missing key'] = [$categories, array_slice($messages, 1), $help, $doc, $schemaWidth, $migrationWidth];
        $fixtures['additional key'] = [$categories, [...$messages, 'additional_code'], $help, $doc, $schemaWidth, $migrationWidth];
        $invalid = $categories;
        $invalid[0] = 'Invalid-Code';
        $fixtures['invalid token'] = [$invalid, $messages, $help, $doc, $schemaWidth, $migrationWidth];
        $tooLong = $categories;
        $tooLong[0] = str_repeat('a', ($schemaWidth ?? $migrationWidth) + 1);
        $fixtures['too long'] = [$tooLong, $messages, $help, $doc, $schemaWidth, $migrationWidth];

        foreach ($fixtures as $name => $fixture) {
            self::assertNotSame([], $this->validateVocabulary(...$fixture), $name . ' fixture unexpectedly passed.');
        }
    }

    public function testTheRunbookStatesOriginRetentionAndLegacyLimitsHonestly(): void
    {
        self::assertSame([], $this->validateOperationsSemantics($this->operationsDocSource()));
    }

    public function testTheRunbookSemanticsRejectMutationsAndAnEmptyScan(): void
    {
        $source = $this->operationsDocSource();
        $fixtures = [
            'zero match' => '',
            'old heading' => str_replace(
                '## Fehlerbilder: Cache bleibt erhalten; nur ESXi-Auth pausiert die Automatik',
                '## Fehlerbilder (nie blockierend, Cache bleibt immer)',
                $source
            ),
            'timeout folded into unreachable' => str_replace(
                'Host aus oder nicht erreichbar',
                'Host aus oder nicht erreichbar, Timeout',
                $source
            ),
            'second error-log copy promised' => str_replace(
                'keine zweite Kopie dieses Inventarfehlers',
                'zusätzlich eine zweite Kopie dieses Inventarfehlers',
                $source
            ),
            'legacy backfill boundary removed' => str_replace(
                'keinen sicheren Backfill',
                'einen sicheren Backfill',
                $source
            ),
            'detail shadow boundary removed' => str_replace(
                'Es gibt bewusst keine zweite Detailkopie in `last_error_detail`',
                'Es gibt eine zweite Detailkopie in `last_error_detail`',
                $source
            ),
        ];

        foreach ($fixtures as $name => $fixture) {
            if ($source !== '' && $fixture !== '') {
                self::assertNotSame($source, $fixture, $name . ' fixture did not mutate the source.');
            }
            self::assertNotSame([], $this->validateOperationsSemantics($fixture), $name . ' fixture unexpectedly passed.');
        }
    }

    /**
     * @param array<int, string> $categories
     * @param array<int, string> $messageCodes
     * @param array<string, array<int, string>> $helpCodesByLocale
     * @param array<int, string> $docCodes
     * @return array<int, string>
     */
    private function validateVocabulary(
        array $categories,
        array $messageCodes,
        array $helpCodesByLocale,
        array $docCodes,
        ?int $schemaWidth,
        int $migrationWidth
    ): array {
        $errors = [];
        if ($categories === []) {
            $errors[] = 'category list is empty';
        }
        if ($categories !== array_values(array_unique($categories))) {
            $errors[] = 'categories are not unique';
        }
        foreach ($categories as $category) {
            if (preg_match('/^[a-z][a-z0-9_]*$/D', $category) !== 1) {
                $errors[] = 'invalid category token: ' . $category;
            }
            if (strlen($category) > ($schemaWidth ?? $migrationWidth)) {
                $errors[] = 'category exceeds database width: ' . $category;
            }
        }
        if ($schemaWidth !== null && $schemaWidth !== $migrationWidth) {
            $errors[] = 'fresh schema and migration widths differ';
        }

        $expected = $this->sortedUnique($categories);
        if ($expected !== $this->sortedUnique($messageCodes)) {
            $errors[] = 'connection message keys differ from categories';
        }
        foreach ($helpCodesByLocale as $locale => $helpCodes) {
            if ($expected !== $this->sortedUnique($helpCodes)) {
                $errors[] = $locale . ' help codes differ from categories';
            }
        }
        if ($docCodes !== [] && $expected !== $this->sortedUnique($docCodes)) {
            $errors[] = 'operations table codes differ from categories';
        }

        return $errors;
    }

    /** @return array<string, array<int, string>> */
    private function helpCodesByLocale(): array
    {
        $byLocale = [];
        foreach (Lang::LOCALES as $locale) {
            $catalog = require dirname(__DIR__, 2) . '/lang/' . $locale . '/help_system_status.php';
            self::assertIsArray($catalog);
            foreach (array_keys($catalog) as $key) {
                if (str_starts_with($key, 'esxi_cause_fix_')) {
                    $byLocale[$locale][] = substr($key, strlen('esxi_cause_fix_'));
                }
            }
            self::assertNotEmpty($byLocale[$locale] ?? [], $locale . ' help scan matched no cause keys.');
        }

        return $byLocale;
    }

    /** @return array<int, string> */
    private function operationsDocCodes(): array
    {
        $source = $this->operationsDocSource();
        self::assertSame(1, preg_match('/^## Fehlerbilder.*?$(.*?)(?=^## |\z)/ms', $source, $section));
        preg_match_all('/^\|\s*`([a-z][a-z0-9_]*)`\s*\|/m', $section[1], $matches);
        self::assertNotEmpty($matches[1], 'The first-column operations table scan matched no codes.');

        return $matches[1];
    }

    private function operationsDocSource(): string
    {
        $path = dirname(__DIR__, 4) . '/docs/operations/esxi-inventory.md';

        return is_file($path) ? (string) file_get_contents($path) : '';
    }

    /** @return array<int, string> */
    private function validateOperationsSemantics(string $source): array
    {
        $errors = [];
        $required = [
            '## Fehlerbilder: Cache bleibt erhalten; nur ESXi-Auth pausiert die Automatik',
            'Das technische Original steht ausschließlich in `deploy_job_logs`',
            'keine zweite Kopie dieses Inventarfehlers',
            'an `deploy.run` und die Aufbewahrung des Auftrags gebunden',
            'keinen sicheren Backfill',
            'Ein gezielter erfolgreicher Einzelabruf',
            'erneute Speichern des ESXi-Zugangsdatums',
            '`ON DELETE SET NULL`',
            'Es gibt bewusst keine zweite Detailkopie in `last_error_detail`',
        ];
        foreach ($required as $needle) {
            if (!str_contains($source, $needle)) {
                $errors[] = 'operations contract is missing: ' . $needle;
            }
        }

        if (preg_match('/^\|\s*`unreachable`\s*\|([^|]*)\|/m', $source, $row) !== 1) {
            $errors[] = 'unreachable operations row is missing';
        } elseif (stripos($row[1], 'timeout') !== false) {
            $errors[] = 'unreachable still claims a timeout';
        }
        if (str_contains($source, 'zusätzlich eine zweite Kopie dieses Inventarfehlers')) {
            $errors[] = 'operations contract promises a second error-log copy';
        }
        if (str_contains($source, 'gibt es einen sicheren Backfill')) {
            $errors[] = 'operations contract promises a speculative legacy backfill';
        }
        if (str_contains($source, 'Es gibt eine zweite Detailkopie in `last_error_detail`')) {
            $errors[] = 'operations contract promises a detail shadow copy';
        }

        return $errors;
    }

    /** @return array{0:?int,1:int} */
    private function databaseWidths(): array
    {
        $root = dirname(__DIR__, 4);
        $migration = (string) file_get_contents(dirname(__DIR__, 2) . '/lib/migrate.php');
        $schemaPath = $root . '/Docker/mysql/mysql-init/struktur.sql';

        return [
            is_file($schemaPath) ? $this->inventoryStateWidth((string) file_get_contents($schemaPath), 'fresh schema') : null,
            $this->inventoryStateWidth($migration, 'migration'),
        ];
    }

    private function inventoryStateWidth(string $source, string $label): int
    {
        self::assertSame(1, preg_match(
            '/CREATE TABLE IF NOT EXISTS deploy_esxi_inventory_state\s*\((.*?)\)\s*ENGINE/s',
            $source,
            $table
        ), $label . ' has no inventory state table.');
        self::assertSame(1, preg_match('/last_error_category\s+VARCHAR\((\d+)\)/', $table[1], $width));

        return (int) $width[1];
    }

    /** @param array<int, string> $values @return array<int, string> */
    private function sortedUnique(array $values): array
    {
        $values = array_values(array_unique($values));
        sort($values);

        return $values;
    }
}

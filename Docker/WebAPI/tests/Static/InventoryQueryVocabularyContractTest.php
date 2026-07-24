<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/lib/deploy_constants.php';
require_once dirname(__DIR__, 2) . '/lib/ansible_inventory.php';

/**
 * The per-query outcome words are a vocabulary an operator reads, not an
 * internal enum: they appear verbatim in a job-log line, and the portal help
 * plus the operations doc teach what each one means. That makes four places for
 * three words, and renaming one of them silently would leave a rotating admin
 * looking up a word the log no longer prints.
 *
 * `VIRTUSPHERE_INVENTORY_QUERY_*` is the SSoT; everything else mirrors it. The
 * constants are deliberately not a DB ENUM (nothing is stored), so the ENUM
 * drift check cannot cover them and this contract does it instead.
 */
final class InventoryQueryVocabularyContractTest extends TestCase
{
    private const STATES = [
        VIRTUSPHERE_INVENTORY_QUERY_ANSWERED,
        VIRTUSPHERE_INVENTORY_QUERY_REJECTED,
        VIRTUSPHERE_INVENTORY_QUERY_SKIPPED,
    ];

    /**
     * Portal help, per locale. Always visible: they live under the app root.
     * Checked per locale rather than per file, and globbed rather than listed:
     * which help module holds a sentence is a layout decision (help.php was
     * split at the 400-line budget), while "the help teaches this word" is the
     * contract. Naming files here is how the confirm and post-guard contracts
     * silently lost their forms to a module split once already.
     */
    private const HELP_LOCALES = ['de', 'en'];

    /** The operations doc, which only exists outside the container mount. */
    private const OPERATIONS_DOC = 'docs/operations/esxi-inventory.md';

    public function testTheThreeStatesAreDistinct(): void
    {
        self::assertSame(self::STATES, array_values(array_unique(self::STATES)));
    }

    /**
     * `answered` is the only state the log line stays quiet about, so it is the
     * one word whose meaning must not drift into "something is wrong".
     */
    public function testOnlyTheAnsweredStateIsSilentInTheLogLine(): void
    {
        foreach (self::STATES as $state) {
            $line = ansible_inventory_queries_log_line(['some_query' => ['state' => $state, 'message' => '']]);
            self::assertIsString($line);
            if ($state === VIRTUSPHERE_INVENTORY_QUERY_ANSWERED) {
                self::assertStringNotContainsString('some_query', $line, 'An answered query must not be listed as a problem.');
                continue;
            }
            self::assertStringContainsString('some_query ' . $state, $line, 'A query that did not answer must be named with its state.');
        }
    }

    /**
     * A state word the log can print but no help explains would send an
     * operator searching for a term nothing defines. `answered` is exempt: it
     * is taught through the "all answered" sentence, not as a lookup word.
     *
     * @return iterable<string, array{0:string}>
     */
    public static function helpLocales(): iterable
    {
        foreach (self::HELP_LOCALES as $locale) {
            yield $locale => [$locale];
        }
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('helpLocales')]
    public function testEveryStateIsExplainedInThePortalHelp(string $locale): void
    {
        $pattern = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'lang' . DIRECTORY_SEPARATOR . $locale . DIRECTORY_SEPARATOR . 'help*.php';
        $files = glob($pattern) ?: [];
        self::assertNotSame([], $files, sprintf('%s has no help catalog; the vocabulary would be undocumented.', $locale));

        $source = '';
        foreach ($files as $file) {
            $source .= (string) file_get_contents($file);
        }

        // Quoted, the way the help cites a term the log prints verbatim. The
        // bare word is not enough: an English catalog says "the VM is skipped
        // as well" about something unrelated, and a mutation test showed the
        // contract passing on exactly that sentence while the real explanation
        // had been deleted. Both quoting styles are accepted, because which one
        // a locale uses is house style, not contract.
        foreach (self::statesOperatorsLookUp() as $state) {
            self::assertMatchesRegularExpression(
                '/[„"]' . preg_quote($state, '/') . '"/u',
                $source,
                sprintf('No %s help catalog explains the query state "%s" that the job log prints.', $locale, $state)
            );
        }
    }

    /**
     * Same contract for the operations doc. It lives outside the container
     * mount (only Docker/WebAPI is mounted), so this runs on the host and in
     * the QA lane's full-repo mount, following the pattern HttpsConfigTest uses
     * for the image Dockerfiles.
     */
    public function testEveryStateIsExplainedInTheOperationsDoc(): void
    {
        $path = dirname(__DIR__, 4) . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, self::OPERATIONS_DOC);
        if (!is_file($path)) {
            self::markTestSkipped('Repo root not visible; docs/ only exists outside the container mount.');
        }

        // Backticked, so the word has to be the term itself rather than an
        // English sentence that happens to contain "skipped".
        $source = (string) file_get_contents($path);
        foreach (self::statesOperatorsLookUp() as $state) {
            self::assertStringContainsString(
                '`' . $state . '`',
                $source,
                sprintf('%s does not explain the query state "%s" that the job log prints.', self::OPERATIONS_DOC, $state)
            );
        }
    }

    /** @return array<int, string> */
    private static function statesOperatorsLookUp(): array
    {
        return array_values(array_filter(
            self::STATES,
            static fn (string $state): bool => $state !== VIRTUSPHERE_INVENTORY_QUERY_ANSWERED
        ));
    }

    /**
     * The playbook writes the raw fields the parser maps onto these states.
     * A renamed field would leave every query reading as "answered", which is
     * the one failure mode this whole report exists to prevent: silence that
     * looks like success.
     */
    public function testPlaybookStillReportsTheFieldsTheParserMaps(): void
    {
        $source = self::playbook();
        foreach (["'failed':", "'skipped':", "'msg':"] as $field) {
            self::assertStringContainsString($field, $source, sprintf('The queries block no longer reports %s.', $field));
        }
    }

    /**
     * The log line names the query, not the card number it feeds, so the query
     * names are operator vocabulary too. An operator reading "datastores
     * rejected" must find that word in the operations doc; a query added or
     * renamed in the playbook without the doc following would print a name
     * nothing explains.
     */
    public function testEveryQueryNameIsExplainedInTheOperationsDoc(): void
    {
        $names = self::queryNames();
        self::assertGreaterThanOrEqual(7, count($names), 'Query-name scan found fewer queries than the playbook reports.');

        $path = dirname(__DIR__, 4) . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, self::OPERATIONS_DOC);
        if (!is_file($path)) {
            self::markTestSkipped('Repo root not visible; docs/ only exists outside the container mount.');
        }

        $doc = (string) file_get_contents($path);
        foreach ($names as $name) {
            self::assertStringContainsString(
                '`' . $name . '`',
                $doc,
                sprintf('%s does not explain the query "%s" that the job log can name.', self::OPERATIONS_DOC, $name)
            );
        }
    }

    /**
     * Query keys of the playbook's queries block, which is the SSoT for what
     * the log line can print.
     *
     * @return array<int, string>
     */
    private static function queryNames(): array
    {
        self::assertSame(1, preg_match('/^\s*queries:\s*>-\R(.+?)^\s*- name:/ms', self::playbook(), $block));
        preg_match_all("/^\s*'(\w+)':\s*\{/m", $block[1], $names);

        return $names[1];
    }

    private static function playbook(): string
    {
        $source = file_get_contents(ansible_source_dir() . DIRECTORY_SEPARATOR . 'inventoryESXi_playbook.yml');
        self::assertIsString($source);

        return $source;
    }
}

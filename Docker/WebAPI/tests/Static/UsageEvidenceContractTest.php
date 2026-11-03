<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * F08 "Verwendet in" contracts, read from the sources:
 * - the reader never selects a credential secret or touches the credential table,
 * - the renderer prints no credential field,
 * - the OS usage counter is the shared reader's, not a second query,
 * - the open-job predicate is the credential fence's own status set,
 * - each page reads a usage section only for a row it lists, after its permission check,
 * - every key the section and its help sentences use exists in DE and EN, without dashes.
 *
 * String literals are read through the tokenizer, so comments that name a column
 * ("no secret, host or username") cannot make a guard fail or pass by accident.
 */
final class UsageEvidenceContractTest extends TestCase
{
    /** Substrings no literal of the reader or the renderer may contain. */
    private const CREDENTIAL_FIELD_MARKERS = [
        'secret',
        'ciphertext',
        'certificate',
        'fingerprint',
        'username',
        'password',
        'host',
        'deploy_credentials',
    ];

    public function testTheReaderNeverReadsTheCredentialTableOrItsSecrets(): void
    {
        $literals = $this->stringLiterals($this->source('lib/repo/usage_evidence.php'));

        foreach (self::CREDENTIAL_FIELD_MARKERS as $marker) {
            self::assertNoFieldMarker($marker, $literals, 'the reader must not select "' . $marker . '"');
        }
    }

    public function testTheRendererPrintsNoCredentialField(): void
    {
        $literals = $this->stringLiterals($this->source('lib/usage_evidence_view.php'));

        foreach (self::CREDENTIAL_FIELD_MARKERS as $marker) {
            self::assertNoFieldMarker($marker, $literals, 'the renderer must not print "' . $marker . '"');
        }
    }

    public function testTheOsUsageCounterIsTheSharedReadersAndNotASecondQuery(): void
    {
        $catalog = $this->source('lib/repo/catalog.php');

        self::assertStringContainsString('return usage_evidence_os_vm_counts($db);', $catalog);
        self::assertStringNotContainsString('GROUP BY vm_os', $catalog, 'the OS count must not be queried in catalog.php again');
        self::assertStringContainsString("require_once __DIR__ . '/usage_evidence.php';", $catalog);
        self::assertStringContainsString('GROUP BY vm_os', $this->source('lib/repo/usage_evidence.php'));
    }

    public function testTheOpenJobPredicateIsTheCredentialFencesStatusSet(): void
    {
        $reader = $this->source('lib/repo/usage_evidence.php');
        $fence = $this->source('lib/repo/deploy_create_fence.php');

        self::assertStringContainsString('VIRTUSPHERE_DEPLOY_JOB_ACTIVE_STATUSES', $reader);
        self::assertStringContainsString('VIRTUSPHERE_DEPLOY_JOB_ACTIVE_STATUSES', $fence);
        // A second, hand-written status list would let the evidence and the delete check disagree.
        self::assertStringNotContainsString("'cancelling'", $this->stringLiterals($reader));
    }

    public function testEachPageReadsASectionOnlyForARowItListsAfterItsPermissionCheck(): void
    {
        $pages = [
            'portal/packages.php' => ['usage_evidence_package(', 'portal_require_user($connection)'],
            'portal/os.php' => ['usage_evidence_os(', 'portal_require_user($connection)'],
            'portal/vlans.php' => ['usage_evidence_vlan(', 'portal_require_user($connection)'],
        ];
        foreach ($pages as $page => [$reader, $guard]) {
            $source = $this->source($page);
            self::assertStringContainsString('usage_evidence_requested_id($_GET', $source, $page);
            self::assertStringContainsString('usage_evidence_find_row($rows, $usageId)', $source, $page);
            self::assertStringContainsString($reader, $source, $page);
            self::assertStringContainsString('usage_evidence_expansion_row(', $source, $page);
            self::assertStringContainsString('usage_evidence_toggle_html(', $source, $page);
            self::assertLessThan(strpos($source, $reader), strpos($source, $guard), $page . ': the guard must come before the read');
        }

        $credentials = $this->source('lib/credentials_panels.php');
        self::assertStringContainsString('usage_evidence_credential($connection, $rowId', $credentials);
        self::assertStringContainsString('usage_evidence_disclosure_row(usage_evidence_credential_anchor($rowId)', $credentials);

        $page = $this->source('portal/credentials.php');
        self::assertLessThan(
            strpos($page, 'credentials_render_page('),
            strpos($page, "can('credentials.manage', \$user)"),
            'the credentials page checks its permission before the section is rendered'
        );
    }

    public function testTheSectionCatalogsHaveTheSameKeysInBothLocalesWithoutDashes(): void
    {
        $de = require dirname(__DIR__, 2) . '/lang/de/usage_evidence.php';
        $en = require dirname(__DIR__, 2) . '/lang/en/usage_evidence.php';
        self::assertSame(array_keys($de), array_keys($en), 'DE and EN keys must match in order');

        $view = $this->source('lib/usage_evidence_view.php');
        preg_match_all("/__t\\('usage_evidence\\.([a-z_]+)'/", $view, $matches);
        self::assertNotEmpty($matches[1]);
        foreach (array_unique($matches[1]) as $key) {
            self::assertArrayHasKey($key, $de, 'missing DE key ' . $key);
            self::assertArrayHasKey($key, $en, 'missing EN key ' . $key);
        }

        $helpKeys = [
            'help_packages' => ['packages_usage', 'packages_os_usage'],
            'help_system_status' => ['vlans_usage'],
            'help_credentials' => ['credentials_usage'],
        ];
        foreach ($helpKeys as $catalog => $keys) {
            $deHelp = require dirname(__DIR__, 2) . '/lang/de/' . $catalog . '.php';
            $enHelp = require dirname(__DIR__, 2) . '/lang/en/' . $catalog . '.php';
            foreach ($keys as $key) {
                self::assertArrayHasKey($key, $deHelp, $catalog . '.' . $key);
                self::assertArrayHasKey($key, $enHelp, $catalog . '.' . $key);
                self::assertNoSentenceDash($deHelp[$key]);
                self::assertNoSentenceDash($enHelp[$key]);
            }
        }

        foreach (array_merge($de, $en) as $text) {
            self::assertNoSentenceDash($text);
        }
    }

    public function testTheSectionRowsHaveStylesheetRules(): void
    {
        $css = $this->source('portal/assets/css/tables.css');

        self::assertStringContainsString('tr.row-usage', $css);
        self::assertStringContainsString('.usage-evidence', $css);
        self::assertStringContainsString('.usage-group', $css);
    }

    private static function assertNoSentenceDash(string $text): void
    {
        self::assertStringNotContainsString("\u{2014}", $text, 'em dash in: ' . $text);
        self::assertStringNotContainsString("\u{2013}", $text, 'en dash in: ' . $text);
    }

    private static function assertNoFieldMarker(string $marker, string $literals, string $message): void
    {
        self::assertFalse(stripos($literals, $marker) !== false, $message);
    }

    /** The text of every string literal in a PHP file, comments excluded. */
    private function stringLiterals(string $source): string
    {
        $literals = '';
        foreach (token_get_all($source) as $token) {
            if (is_array($token) && in_array($token[0], [T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE], true)) {
                $literals .= $token[1] . "\n";
            }
        }

        return $literals;
    }

    private function source(string $relativePath): string
    {
        $path = dirname(__DIR__, 2) . '/' . $relativePath;
        self::assertFileExists($path);

        return (string) file_get_contents($path);
    }
}

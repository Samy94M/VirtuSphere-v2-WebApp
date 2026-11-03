<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/lib/mac.php';

final class MacNormalizeTest extends TestCase
{
    /**
     * The canonicalization exists three times (PHP here, PowerShell on the MECM
     * server and on the deploy clients) because the three run in different
     * places and cannot share a file. tests/fixtures/mac-vectors.json is the
     * shared truth instead: this test and Pester's Mac.Tests.ps1 read the same
     * table, so a change on one side that the others do not follow fails a
     * build. Without it the drift is silent - the portal stores a MAC MECM
     * then cannot find, and nothing raises an error (TESTPLAN 2.2).
     *
     * @return iterable<string, array{0: string|null, 1: string|null}>
     */
    public static function macVectors(): iterable
    {
        $path = dirname(__DIR__) . '/fixtures/mac-vectors.json';
        $raw = file_get_contents($path);
        self::assertIsString($raw, 'MAC vector fixture is unreadable: ' . $path);

        /** @var array{vectors: list<array{input: string, expected: string|null, why: string}>} $data */
        $data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);

        foreach ($data['vectors'] as $vector) {
            yield $vector['why'] => [$vector['input'], $vector['expected']];
        }
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('macVectors')]
    public function testSharedVectorTable(?string $input, ?string $expected): void
    {
        self::assertSame($expected, virtusphere_normalize_mac($input));
    }

    public function testNullInput(): void
    {
        // Not in the shared table: PowerShell has no null string, so the two
        // languages express "absent" differently and only PHP can assert it.
        self::assertNull(virtusphere_normalize_mac(null));
    }

    public function testIdempotence(): void
    {
        $canonical = virtusphere_normalize_mac('aa-bb-cc-dd-ee-ff');
        self::assertSame($canonical, virtusphere_normalize_mac((string) $canonical));
    }
}

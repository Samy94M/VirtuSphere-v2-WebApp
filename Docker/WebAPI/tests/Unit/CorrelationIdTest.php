<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/lib/errors.php';
require_once dirname(__DIR__, 2) . '/lib/deploy_constants.php';
require_once dirname(__DIR__, 2) . '/lib/ansible_command.php';

/**
 * ADR-0032 test matrix, points 1 (mint) and 5 (command chain): the process
 * mints exactly one id and keeps it stable, adoption swaps the trace for a
 * worker's claimed job and can always be dropped again, and the remote
 * command chain exports the current id before any playbook runs.
 */
final class CorrelationIdTest extends TestCase
{
    protected function tearDown(): void
    {
        // Never leak an adopted id into other tests of this process.
        virtusphere_correlation_adopt(null);
    }

    public function testTheProcessMintsOneStableWellFormedId(): void
    {
        $id = virtusphere_correlation_id();
        self::assertMatchesRegularExpression('/^[0-9a-f]{16}$/', $id);
        self::assertSame($id, virtusphere_correlation_id(), 'the id must be stable within the process');
    }

    public function testAdoptionSwapsTheTraceAndDropReturnsToTheMintedId(): void
    {
        $minted = virtusphere_correlation_id();

        virtusphere_correlation_adopt('feedface00000001');
        self::assertSame('feedface00000001', virtusphere_correlation_id());

        virtusphere_correlation_adopt(null);
        self::assertSame($minted, virtusphere_correlation_id(), 'dropping must restore the process id, not mint a new one');
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function invalidIds(): iterable
    {
        yield 'non-hex' => ['XYZ12345'];
        yield 'too short' => ['abc'];
        yield 'too long' => [str_repeat('a', 33)];
        yield 'uppercase' => ['DEADBEEF00000001'];
        yield 'empty' => [''];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('invalidIds')]
    public function testAnInvalidIdIsNeverAdopted(string $invalid): void
    {
        $minted = virtusphere_correlation_id();
        virtusphere_correlation_adopt($invalid);

        self::assertFalse(virtusphere_correlation_id_is_valid($invalid));
        self::assertSame($minted, virtusphere_correlation_id(), 'garbage must not replace the trace');
    }

    public function testTheErrorReferenceIsTheCorrelationId(): void
    {
        // The reference on an error page must lead to the audit rows of the
        // same request, so the two are one value by construction.
        self::assertSame(virtusphere_correlation_id(), virtusphere_error_reference());
    }

    public function testTheRemoteCommandChainExportsTheCurrentId(): void
    {
        virtusphere_correlation_adopt('feedface00000002');
        $command = ansible_remote_command('/tmp/vs-deploy', ['mode' => 'export']);

        self::assertStringContainsString("export VS_CORRELATION_ID='feedface00000002'", $command);
        // Before the first playbook, so every step runs inside the trace.
        self::assertLessThan(
            strpos($command, 'ansible-playbook'),
            strpos($command, 'VS_CORRELATION_ID'),
            'the export must precede the first playbook step'
        );
    }
}

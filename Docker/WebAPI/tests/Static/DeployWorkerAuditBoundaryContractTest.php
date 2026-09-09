<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/** A fail-soft audit catch must never hide a rollback of the domain writes. */
final class DeployWorkerAuditBoundaryContractTest extends TestCase
{
    public function testOutcomeAuditsFollowTheirOwnershipTransactions(): void
    {
        $owners = [
            'deploy_worker_conclude_sequence' => 'deploy_worker_finish.php',
            'deploy_worker_handle_failure' => 'deploy_worker_finish.php',
            'deploy_worker_conclude_create_section' => 'deploy_worker_create.php',
        ];
        foreach ($owners as $function => $file) {
            $tokens = token_get_all((string) file_get_contents(dirname(__DIR__, 2) . '/lib/' . $file));
            $start = null;
            foreach ($tokens as $index => $token) {
                if (is_array($token) && $token[0] === T_STRING && $token[1] === $function) {
                    $start = $index;
                    break;
                }
            }
            self::assertNotNull($start, 'Missing owner ' . $function);
            $ownerCall = false;
            $depth = 0;
            $finished = false;
            $audits = 0;
            $auditBoundaries = [];
            foreach (array_slice($tokens, $start + 1) as $token) {
                if (is_array($token) && $token[0] === T_FUNCTION && $finished) {
                    break;
                }
                if (is_array($token) && $token[0] === T_STRING) {
                    if ($token[1] === 'deploy_worker_owned_transaction') {
                        $ownerCall = true;
                    }
                    if ($token[1] === 'deploy_worker_audit_outcome') {
                        $auditBoundaries[] = $finished;
                        $audits++;
                    }
                }
                if ($ownerCall && $token === '(') {
                    $depth++;
                } elseif ($ownerCall && $token === ')' && --$depth === 0) {
                    $finished = true;
                    $ownerCall = false;
                }
            }
            self::assertTrue($finished, 'No ownership transaction found in ' . $function);
            self::assertSame(1, $audits, $function . ' must audit exactly once after terminal publication.');
            self::assertSame([true], $auditBoundaries, $function . ': audit still runs inside the ownership transaction.');
        }
    }
}

<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/lib/deploy_worker_outcome.php';

/**
 * Phase-aware classification of thrown inventory-job failures (B6).
 *
 * Every throw between "claimed" and "finished" used to fall back to the `parse`
 * category: a refused SSH connection, a DNS failure on the Ansible host, a
 * mission deleted mid-run and a genuinely corrupt marker all told the operator
 * "the host answered unexpectedly" and sent them to check a network path that
 * may never have been used. The worker now records WHERE it was (config / ssh /
 * sftp / transport / marker / db) and only refines with text evidence inside
 * the phases where text can distinguish anything, in the binding order of
 * section 3 of the deploy-reliability masterplan.
 */
final class DeployWorkerFailureClassificationTest extends TestCase
{
    /** @return array<string, array{string, string, string}> */
    public static function classificationCases(): array
    {
        return [
            // Preparation failures never reached the network: a missing template
            // or a broken credential row is OUR deployment, not the host's answer.
            'config: unreadable artifact' => [VIRTUSPHERE_DEPLOY_PHASE_CONFIG, 'Cannot read upload_mac_list.py.', VIRTUSPHERE_INVENTORY_ERROR_CONFIG],
            'config: even network-sounding text stays config' => [VIRTUSPHERE_DEPLOY_PHASE_CONFIG, 'timeout while decrypting', VIRTUSPHERE_INVENTORY_ERROR_CONFIG],

            // Inside the ssh/transport phases the text refines the category and
            // is qualified as Ansible-host-originated (Etappe 7): both phases
            // sit entirely on the Portal-to-Ansible-host leg, so a bare
            // `unreachable`/`dns`/`auth` would misname who to fix.
            'ssh: refused' => [VIRTUSPHERE_DEPLOY_PHASE_SSH, 'Connection refused by 10.0.0.5', VIRTUSPHERE_INVENTORY_ERROR_ANSIBLE_UNREACHABLE],
            'ssh: dns' => [VIRTUSPHERE_DEPLOY_PHASE_SSH, 'php_network_getaddresses: getaddrinfo failed', VIRTUSPHERE_INVENTORY_ERROR_ANSIBLE_DNS],
            'ssh: auth' => [VIRTUSPHERE_DEPLOY_PHASE_SSH, 'Permission denied (publickey,password)', VIRTUSPHERE_INVENTORY_ERROR_ANSIBLE_AUTH],
            'transport: timeout' => [VIRTUSPHERE_DEPLOY_PHASE_TRANSPORT, 'Read timed out after 30s', VIRTUSPHERE_INVENTORY_ERROR_ANSIBLE_UNREACHABLE],

            // The old defect: unrecognized transport text fell to `parse` and
            // blamed the host's answer. It is an Ansible-transport failure.
            'ssh: unrecognized text is ansible_transport, not parse' => [VIRTUSPHERE_DEPLOY_PHASE_SSH, 'channel 3: open failed', VIRTUSPHERE_INVENTORY_ERROR_ANSIBLE_TRANSPORT],
            'transport: unrecognized text is ansible_transport, not parse' => [VIRTUSPHERE_DEPLOY_PHASE_TRANSPORT, 'sftp write aborted', VIRTUSPHERE_INVENTORY_ERROR_ANSIBLE_TRANSPORT],

            // Only the marker phase may say parse: that is the one place where
            // "the host answered unexpectedly" is the true sentence.
            'marker: corrupt payload' => [VIRTUSPHERE_DEPLOY_PHASE_MARKER, 'Inventory payload is not valid base64.', VIRTUSPHERE_INVENTORY_ERROR_PARSE],

            // Failures while writing the cache are our worker/database, and the
            // text (often a foreign key wording) must not re-route them.
            'db: constraint wording stays worker' => [VIRTUSPHERE_DEPLOY_PHASE_DB, 'Cannot add or update a child row: a foreign key constraint fails', VIRTUSPHERE_INVENTORY_ERROR_WORKER],
        ];
    }

    #[DataProvider('classificationCases')]
    public function testPhasePlusTextClassifies(string $phase, string $message, string $expected): void
    {
        self::assertSame($expected, deploy_worker_classify_inventory_failure($phase, $message));
    }

    public function testAnUnknownPhaseFallsBackToWorkerNotParse(): void
    {
        // A phase this map does not know is a coding error in the worker, which
        // is a worker problem by definition; blaming the host's answer is the
        // exact defect this function exists to remove.
        self::assertSame(VIRTUSPHERE_INVENTORY_ERROR_WORKER, deploy_worker_classify_inventory_failure('surprise', 'anything'));
    }

    /**
     * Section 3, rule 1: a database failure is ours in every phase.
     *
     * Etappe 7 made this worse before Etappe 8 fixed it. While the transport
     * phases still fell back to the generic classifier, a mysqli failure handed
     * through them came out as the vague `ssh`; since those phases are
     * qualified as Ansible-host findings it came out as `ansible_transport` and
     * named the Ansible host as the cause of a database outage. The phase says
     * nothing about the origin here, so the type has to be read before it.
     */
    public function testADatabaseFailureIsOursInEveryPhase(): void
    {
        foreach ([
            VIRTUSPHERE_DEPLOY_PHASE_CONFIG,
            VIRTUSPHERE_DEPLOY_PHASE_SSH,
            VIRTUSPHERE_DEPLOY_PHASE_SFTP,
            VIRTUSPHERE_DEPLOY_PHASE_TRANSPORT,
            VIRTUSPHERE_DEPLOY_PHASE_MARKER,
            VIRTUSPHERE_DEPLOY_PHASE_DB,
        ] as $phase) {
            self::assertSame(
                VIRTUSPHERE_INVENTORY_ERROR_WORKER,
                deploy_worker_classify_inventory_failure(
                    $phase,
                    // Wording a stream logger really produces, and every word in
                    // it is a needle of the generic classifier.
                    new mysqli_sql_exception('MySQL server has gone away')
                ),
                sprintf('A mysqli failure in phase %s must stay `worker`.', $phase)
            );
        }
    }

    /**
     * The negative half of the rule above: the type is what decides, not the
     * wording. A remote failure whose message mentions a table must not become
     * `worker` and send the operator to the database.
     */
    public function testDatabaseSoundingTextFromTheRemoteHostIsNotOurs(): void
    {
        self::assertSame(
            VIRTUSPHERE_INVENTORY_ERROR_ANSIBLE_TRANSPORT,
            deploy_worker_classify_inventory_failure(
                VIRTUSPHERE_DEPLOY_PHASE_TRANSPORT,
                new RuntimeException('mysql server has gone away')
            )
        );
    }

    /**
     * Section 3, rule 6: the budget type means `ansible_timeout` only on the
     * three legs that talk to the Ansible host.
     *
     * A budget type raised in CONFIG never opened a socket, so it is a coding
     * error in the worker, not a remote timeout; naming a host that phase never
     * contacted is the exact defect the phase model exists to remove. Not
     * reachable today, because budgets are raised in the SSH/SFTP code only,
     * but the contradiction stood in writing between plan and code and the new
     * SFTP phase moves that code around.
     */
    public function testABudgetTypeOutsideTheRemoteLegsKeepsItsPhaseAnswer(): void
    {
        self::assertSame(
            VIRTUSPHERE_INVENTORY_ERROR_CONFIG,
            deploy_worker_classify_inventory_failure(
                VIRTUSPHERE_DEPLOY_PHASE_CONFIG,
                new SshTransportBudgetExceeded('idle budget')
            )
        );
        self::assertSame(
            VIRTUSPHERE_INVENTORY_ERROR_PARSE,
            deploy_worker_classify_inventory_failure(
                VIRTUSPHERE_DEPLOY_PHASE_MARKER,
                new SshTransportBudgetExceeded('idle budget')
            )
        );
    }

    /**
     * Section 3, rule 9: inside the upload leg the generic transport fallback
     * is said more precisely, because that phase only ever runs SFTP.
     */
    public function testTheUploadLegSaysFileTransferInsteadOfTheGenericFallback(): void
    {
        self::assertSame(
            VIRTUSPHERE_INVENTORY_ERROR_ANSIBLE_SFTP,
            deploy_worker_classify_inventory_failure(VIRTUSPHERE_DEPLOY_PHASE_SFTP, 'channel 3: open failed')
        );
        // Only the fallback is specialized: evidence the wording DOES carry
        // survives, or a broken name resolution would read as a file problem.
        self::assertSame(
            VIRTUSPHERE_INVENTORY_ERROR_ANSIBLE_DNS,
            deploy_worker_classify_inventory_failure(VIRTUSPHERE_DEPLOY_PHASE_SFTP, 'getaddrinfo failed')
        );
        self::assertSame(
            VIRTUSPHERE_INVENTORY_ERROR_ANSIBLE_TIMEOUT,
            deploy_worker_classify_inventory_failure(
                VIRTUSPHERE_DEPLOY_PHASE_SFTP,
                new SshTransportBudgetExceeded('SFTP total budget')
            )
        );
    }

    public function testExactTransportTypesWinOverTheirText(): void
    {
        self::assertSame(
            VIRTUSPHERE_INVENTORY_ERROR_ANSIBLE_TIMEOUT,
            deploy_worker_classify_inventory_failure(
                VIRTUSPHERE_DEPLOY_PHASE_TRANSPORT,
                new SshTransportBudgetExceeded('arbitrary words')
            )
        );
        self::assertSame(
            VIRTUSPHERE_INVENTORY_ERROR_ANSIBLE_SFTP,
            deploy_worker_classify_inventory_failure(
                VIRTUSPHERE_DEPLOY_PHASE_TRANSPORT,
                new SftpTransportFailed('permission denied')
            )
        );
        self::assertSame(
            VIRTUSPHERE_INVENTORY_ERROR_CONFIG,
            deploy_worker_classify_inventory_failure(
                VIRTUSPHERE_DEPLOY_PHASE_TRANSPORT,
                new SshTransportConfigurationException('phpseclib is missing')
            )
        );
    }

    public function testMatchingTextDoesNotPromoteAGenericOrCancelledExceptionToBudget(): void
    {
        $text = 'Remote command produced no output for 30 seconds (idle timeout).';
        self::assertSame(
            VIRTUSPHERE_INVENTORY_ERROR_ANSIBLE_UNREACHABLE,
            deploy_worker_classify_inventory_failure(VIRTUSPHERE_DEPLOY_PHASE_TRANSPORT, new RuntimeException($text))
        );
        self::assertSame(
            VIRTUSPHERE_INVENTORY_ERROR_ANSIBLE_UNREACHABLE,
            deploy_worker_classify_inventory_failure(VIRTUSPHERE_DEPLOY_PHASE_TRANSPORT, new DeployWorkerCancelled($text))
        );
    }

    /**
     * The audit line "auto-pull paused" is written once per onset, and the
     * decision is now a predicate rather than two conditions inlined in a
     * catch block that no test can reach.
     */
    public function testThePauseAuditFiresOnceAndOnlyForTheCategoryThatPauses(): void
    {
        $notPaused = ['paused_until_credential_change' => 0];
        $paused = ['paused_until_credential_change' => 1];

        self::assertTrue(deploy_worker_inventory_pause_onset($notPaused, VIRTUSPHERE_INVENTORY_ERROR_AUTH));
        // A credential that has never failed has no state row yet, and that
        // first auth failure is an onset like any other.
        self::assertTrue(deploy_worker_inventory_pause_onset(null, VIRTUSPHERE_INVENTORY_ERROR_AUTH));
        // Already paused: the operator retried by hand and it failed again.
        // One line per retry would bury the sign-in trail it lives in.
        self::assertFalse(deploy_worker_inventory_pause_onset($paused, VIRTUSPHERE_INVENTORY_ERROR_AUTH));

        // No Ansible-side finding pauses anything, so none may announce a
        // pause. `ansible_auth` is the trap: same word, other machine.
        foreach ([
            VIRTUSPHERE_INVENTORY_ERROR_ANSIBLE_AUTH,
            VIRTUSPHERE_INVENTORY_ERROR_ANSIBLE_AUTHZ,
            VIRTUSPHERE_INVENTORY_ERROR_ANSIBLE_TIMEOUT,
            VIRTUSPHERE_INVENTORY_ERROR_ANSIBLE_UNREACHABLE,
            VIRTUSPHERE_INVENTORY_ERROR_ANSIBLE_PREFLIGHT,
            VIRTUSPHERE_INVENTORY_ERROR_WORKER,
        ] as $category) {
            self::assertFalse(deploy_worker_inventory_pause_onset($notPaused, $category), $category);
        }
    }

    public function testSecretsAreRedactedFromFailureMessages(): void
    {
        $message = 'LOGIN failed for esxi with s3cr3t-pw (url https://h/?pw=s3cr3t-pw)';
        $redacted = deploy_worker_redact_secrets($message, ['s3cr3t-pw', null, '']);
        self::assertStringNotContainsString('s3cr3t-pw', $redacted);
        self::assertStringContainsString('***', $redacted);
        // The rest of the sentence survives: the redaction must not shred the
        // evidence the operator needs.
        self::assertStringContainsString('LOGIN failed for esxi', $redacted);
    }

    public function testATinySecretIsNotRedacted(): void
    {
        // Same rule as connection_error_detail(): replacing a 1-3 character
        // secret would shred the message ("a" appears everywhere).
        self::assertSame('war in a jar', deploy_worker_redact_secrets('war in a jar', ['a']));
    }

    public function testTheUrlEncodedFormOfASecretIsRedactedToo(): void
    {
        $secret = 'p@ss wort';
        $redacted = deploy_worker_redact_secrets('POST body pw=' . rawurlencode($secret), [$secret]);
        self::assertStringNotContainsString(rawurlencode($secret), $redacted);
    }
}

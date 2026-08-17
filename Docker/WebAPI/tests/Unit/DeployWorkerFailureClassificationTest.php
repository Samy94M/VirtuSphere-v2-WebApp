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
 * transport / marker / db) and only refines with text evidence inside the
 * phases where text can distinguish anything.
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

            // Inside the ssh/transport phases the text refines the category.
            'ssh: refused' => [VIRTUSPHERE_DEPLOY_PHASE_SSH, 'Connection refused by 10.0.0.5', VIRTUSPHERE_INVENTORY_ERROR_UNREACHABLE],
            'ssh: dns' => [VIRTUSPHERE_DEPLOY_PHASE_SSH, 'php_network_getaddresses: getaddrinfo failed', VIRTUSPHERE_INVENTORY_ERROR_DNS],
            'ssh: auth' => [VIRTUSPHERE_DEPLOY_PHASE_SSH, 'Permission denied (publickey,password)', VIRTUSPHERE_INVENTORY_ERROR_AUTH],
            'transport: timeout' => [VIRTUSPHERE_DEPLOY_PHASE_TRANSPORT, 'Read timed out after 30s', VIRTUSPHERE_INVENTORY_ERROR_UNREACHABLE],

            // The old defect: unrecognized transport text fell to `parse` and
            // blamed the host's answer. It is an SSH-layer failure.
            'ssh: unrecognized text is ssh, not parse' => [VIRTUSPHERE_DEPLOY_PHASE_SSH, 'channel 3: open failed', VIRTUSPHERE_INVENTORY_ERROR_SSH],
            'transport: unrecognized text is ssh, not parse' => [VIRTUSPHERE_DEPLOY_PHASE_TRANSPORT, 'sftp write aborted', VIRTUSPHERE_INVENTORY_ERROR_SSH],

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
            VIRTUSPHERE_INVENTORY_ERROR_UNREACHABLE,
            deploy_worker_classify_inventory_failure(VIRTUSPHERE_DEPLOY_PHASE_TRANSPORT, new RuntimeException($text))
        );
        self::assertSame(
            VIRTUSPHERE_INVENTORY_ERROR_UNREACHABLE,
            deploy_worker_classify_inventory_failure(VIRTUSPHERE_DEPLOY_PHASE_TRANSPORT, new DeployWorkerCancelled($text))
        );
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

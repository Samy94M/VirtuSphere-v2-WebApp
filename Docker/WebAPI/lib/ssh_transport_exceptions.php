<?php

declare(strict_types=1);

/** A VirtuSphere-owned SSH or SFTP time budget expired. */
final class SshTransportBudgetExceeded extends RuntimeException
{
}

/** The remote SFTP subsystem or an SFTP operation failed. */
final class SftpTransportFailed extends RuntimeException
{
}

/** A local prerequisite for the SSH/SFTP transport is missing or invalid. */
final class SshTransportConfigurationException extends RuntimeException
{
}

/** A closed host-identity finding, before any password authentication. */
final class SshHostIdentityRejected extends RuntimeException
{
    /** @param array{fingerprint:string,type:string}|null $observed */
    public function __construct(public readonly string $reason, ?array $observed = null, string $expected = '')
    {
        $message = match ($reason) {
            'mismatch' => 'Ansible host identity differs from the pinned key.',
            'unconfirmed' => 'The new Ansible host identity requires administrator confirmation.',
            'credential_changed' => 'The Ansible credential changed before authentication.',
            'invalid_key' => 'No verified SSH server public key is available.',
            'storage_failed' => 'The Ansible host identity could not be checked or persisted.',
            'reconnect_unverified' => 'SSH authentication cannot be repeated on this connection; a fresh guarded connection is required.',
            default => throw new InvalidArgumentException('Unknown SSH host identity rejection reason.'),
        };
        parent::__construct($message
            . ($expected === '' ? '' : ' Expected: ' . $expected . '.')
            . ($observed === null ? '' : ' Observed: ' . $observed['type'] . ' ' . $observed['fingerprint'] . '.'));
    }
}

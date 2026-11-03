<?php

declare(strict_types=1);

use phpseclib3\Net\SSH2;
use phpseclib3\Net\SFTP;

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/ssh_transport_exceptions.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/repo/credential_host_identity.php';

/** @return array{fingerprint:string,type:string} */
function ssh_host_identity(SSH2 $transport): array
{
    // phpseclib validates the handshake signature in this call. It must happen
    // before parent::login(), including phpseclib's private reconnect path.
    $publicKey = $transport->getServerPublicHostKey();
    if (!is_string($publicKey) || preg_match('/^\S+ ([A-Za-z0-9+\/=]+)$/D', $publicKey, $match) !== 1) {
        throw new SshHostIdentityRejected('invalid_key');
    }
    $wire = base64_decode($match[1], true);
    if ($wire === false || strlen($wire) < 5) {
        throw new SshHostIdentityRejected('invalid_key');
    }
    $header = unpack('Nlength', substr($wire, 0, 4));
    if (!is_array($header)) {
        throw new SshHostIdentityRejected('invalid_key');
    }
    $length = (int) ($header['length'] ?? 0);
    if ($length < 1 || $length > 64 || strlen($wire) <= 4 + $length) {
        throw new SshHostIdentityRejected('invalid_key');
    }
    // RSA SHA-2 negotiation names differ from the wire key's ssh-rsa type.
    // Fingerprint the unchanged public-key blob, exactly as ssh-keygen does.
    $type = substr($wire, 4, $length);
    if (preg_match('/^[a-zA-Z0-9][a-zA-Z0-9@._+-]{0,63}$/D', $type) !== 1) {
        throw new SshHostIdentityRejected('invalid_key');
    }
    return ['type' => $type, 'fingerprint' => 'SHA256:' . rtrim(base64_encode(hash('sha256', $wire, true)), '=')];
}

/** @param null|callable(array,array):void $verify */
function ssh_verify_host_identity(SSH2 $transport, array $credential, ?callable $verify = null): void
{
    $observed = ssh_host_identity($transport);
    if ($verify !== null) {
        $verify($credential, $observed);
        return;
    }
    try {
        $connection = db();
        if (repo_transaction_depth($connection) !== 0) {
            throw new SshHostIdentityRejected('storage_failed', $observed);
        }
        repo_verify_ansible_host_identity($connection, $credential, $observed);
    } catch (SshHostIdentityRejected $exception) {
        throw $exception;
    } catch (Throwable) {
        // A persistence outage cannot fall through into password transfer.
        throw new SshHostIdentityRejected('storage_failed', $observed);
    }
}

/** Both connection classes enforce the same guard, even on library relogin. */
interface VirtuSphereHostIdentityConnection
{
    /** @param null|callable(array,array):void $verify */
    public function requireHostIdentity(array $credential, ?callable $verify = null): void;
}

trait VirtuSphereHostIdentityGuard
{
    private bool $authenticationAttempted = false;
    /** @var array<string,mixed>|null */
    private ?array $hostCredential = null;
    /** @var null|Closure(array,array):void */
    private ?Closure $hostVerifier = null;

    /** @param null|callable(array,array):void $verify */
    public function requireHostIdentity(array $credential, ?callable $verify = null): void
    {
        $this->hostCredential = $credential;
        $this->hostVerifier = $verify === null ? null : Closure::fromCallable($verify);
    }

    public function login($username, ...$args)
    {
        // phpseclib caches its private signature_validated flag across an
        // in-place reconnect. This object cannot prove a fresh handshake.
        if ($this->authenticationAttempted) {
            throw new SshHostIdentityRejected('reconnect_unverified');
        }
        $this->authenticationAttempted = true;
        if ($this->hostCredential === null || (string) $username !== (string) ($this->hostCredential['username'] ?? '')) {
            throw new SshHostIdentityRejected('credential_changed');
        }
        if ($this->hostVerifier === null) {
            ssh_verify_host_identity($this, $this->hostCredential);
        } else {
            $this->hostVerifier->__invoke($this->hostCredential, ssh_host_identity($this));
        }
        return parent::login($username, ...$args);
    }
}

class VirtuSphereSshConnection extends SSH2 implements VirtuSphereHostIdentityConnection
{
    use VirtuSphereHostIdentityGuard;
}

class VirtuSphereSftpConnection extends SFTP implements VirtuSphereHostIdentityConnection
{
    use VirtuSphereHostIdentityGuard;
}

/** @param null|callable(array,array):void $verify */
function ssh_verified_login(SSH2 $transport, string $username, string $secret, array $credential, ?callable $verify = null): bool
{
    if ($transport instanceof VirtuSphereHostIdentityConnection) {
        $transport->requireHostIdentity($credential, $verify);
    } else {
        // The explicit transport seam lets behavior tests use a network-free
        // phpseclib mock; production constructors use the guarded subclasses.
        ssh_verify_host_identity($transport, $credential, $verify);
    }
    return $transport->login($username, $secret);
}

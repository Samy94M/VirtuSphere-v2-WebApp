<?php

declare(strict_types=1);

require_once __DIR__ . '/constants.php';
require_once __DIR__ . '/lang.php';
require_once __DIR__ . '/validate.php';
require_once __DIR__ . '/backup_status.php';
require_once __DIR__ . '/request.php';
require_once __DIR__ . '/repo/settings.php';

/**
 * K19 (VT-05, VT-E2): backups are encrypted to a public key. The portal stores
 * only an X.509 certificate; the private key stays offline with the operator
 * and never reaches the app host, so nothing here generates, accepts or logs
 * one. `scripts/backup.sh` reads the certificate from this setting at run time
 * and encrypts dump and config archive separately with `openssl cms`.
 *
 * Public-key encryption gives confidentiality, not authenticity: anyone holding
 * the certificate can produce a valid envelope. Corruption is still detected by
 * the manifest, which is why the script uses CBC EnvelopedData that an OpenSSL
 * 1.1 restore machine can open.
 */
const VIRTUSPHERE_BACKUP_ENCRYPTION_MIN_RSA_BITS = 3072;
const VIRTUSPHERE_BACKUP_ENCRYPTION_ACTIONS = ['save_backup_certificate', 'remove_backup_certificate'];

const VIRTUSPHERE_BACKUP_FINDING_UNENCRYPTED = 'unencrypted';
const VIRTUSPHERE_BACKUP_FINDING_LAST_UNENCRYPTED = 'last_unencrypted';

/**
 * Validates one PEM certificate and returns what the portal shows about it.
 * Rejects any private key block outright, more than one PEM block, anything
 * that is not RSA and an RSA key below the minimum.
 *
 * @return array{pem:string,fingerprint:string,subject:string,valid_from:int,valid_to:int,bits:int}
 */
function backup_encryption_parse_certificate(string $pem): array
{
    $pem = trim(str_replace("\r\n", "\n", $pem));
    $field = 'backup_certificate';
    if ($pem === '') {
        throw new ValidationException([$field => __t('settings.backup_cert_required')]);
    }
    // Checked first and on its own: a pasted key pair must never be stored,
    // and the operator has to learn why rather than read "invalid".
    if (preg_match('/-----BEGIN [A-Z0-9 ]*PRIVATE KEY-----/', $pem) === 1) {
        throw new ValidationException([$field => __t('settings.backup_cert_private_key')]);
    }
    if (preg_match_all('/-----BEGIN ([A-Z0-9 ]+)-----/', $pem, $blocks) !== 1 || $blocks[1][0] !== 'CERTIFICATE') {
        throw new ValidationException([$field => __t('settings.backup_cert_single')]);
    }
    $certificate = @openssl_x509_read($pem);
    $publicKey = $certificate === false ? false : @openssl_pkey_get_public($certificate);
    $details = $publicKey === false ? false : openssl_pkey_get_details($publicKey);
    $parsed = $certificate === false ? false : openssl_x509_parse($certificate);
    $fingerprint = $certificate === false ? false : openssl_x509_fingerprint($certificate, 'sha256');
    if (!is_array($details) || !is_array($parsed) || !is_string($fingerprint)) {
        throw new ValidationException([$field => __t('settings.backup_cert_invalid')]);
    }
    if (($details['type'] ?? null) !== OPENSSL_KEYTYPE_RSA || (int) ($details['bits'] ?? 0) < VIRTUSPHERE_BACKUP_ENCRYPTION_MIN_RSA_BITS) {
        throw new ValidationException([$field => __t('settings.backup_cert_weak', ['bits' => VIRTUSPHERE_BACKUP_ENCRYPTION_MIN_RSA_BITS])]);
    }

    return [
        'pem' => $pem . "\n",
        'fingerprint' => strtolower($fingerprint),
        'subject' => backup_encryption_subject($parsed['subject'] ?? []),
        'valid_from' => (int) ($parsed['validFrom_time_t'] ?? 0),
        'valid_to' => (int) ($parsed['validTo_time_t'] ?? 0),
        'bits' => (int) $details['bits'],
    ];
}

/** @param array<string,mixed> $subject */
function backup_encryption_subject(array $subject): string
{
    $parts = [];
    foreach ($subject as $key => $value) {
        foreach ((array) $value as $item) {
            $parts[] = $key . '=' . (string) $item;
        }
    }

    return implode(', ', $parts);
}

/**
 * The stored certificate, or null when none is stored. A stored value that no
 * longer parses is logged and reported as null; the backup script fails closed
 * on it, so the run status names the failure.
 *
 * @return array{pem:string,fingerprint:string,subject:string,valid_from:int,valid_to:int,bits:int}|null
 */
function backup_encryption_certificate(mysqli $db): ?array
{
    $pem = repo_setting_value($db, VIRTUSPHERE_SETTING_BACKUP_ENCRYPTION_CERTIFICATE, '');
    if (trim($pem) === '') {
        return null;
    }
    try {
        return backup_encryption_parse_certificate($pem);
    } catch (ValidationException) {
        error_log('[backup-encryption] stored certificate does not parse');

        return null;
    }
}

/**
 * The one derivation of "are the backups encrypted", shared by System status
 * and the settings backup card. Without a certificate every future run is
 * unencrypted. With one, the newest successful run must say it was encrypted
 * to exactly that certificate; a failed run is the backup state's business.
 *
 * @param array<string,mixed>|null $certificate
 * @param array<string,mixed> $backupStatus result of backup_status_read()
 */
function backup_encryption_finding(?array $certificate, array $backupStatus): ?string
{
    if ($certificate === null) {
        return VIRTUSPHERE_BACKUP_FINDING_UNENCRYPTED;
    }
    $last = $backupStatus['last'] ?? null;
    if (!is_array($last) || (string) ($last['status'] ?? '') !== VIRTUSPHERE_BACKUP_STATE_OK) {
        return null;
    }
    if (($last['encrypted'] ?? false) !== true
        || !hash_equals((string) $certificate['fingerprint'], strtolower((string) ($last['recipient_sha256'] ?? '')))) {
        return VIRTUSPHERE_BACKUP_FINDING_LAST_UNENCRYPTED;
    }

    return null;
}

function backup_encryption_finding_message(string $finding): string
{
    return $finding === VIRTUSPHERE_BACKUP_FINDING_UNENCRYPTED
        ? __t('settings.backup_unencrypted')
        : __t('settings.backup_last_unencrypted');
}

/**
 * The two settings actions. The page shell already checked system.config and
 * CSRF (portal_guard_post); the redirect stays with the settings dispatcher.
 *
 * @param array<string,mixed> $user
 */
function backup_encryption_handle_action(mysqli $db, array $user, string $action): void
{
    $previous = backup_encryption_certificate($db);
    try {
        if ($action === 'save_backup_certificate') {
            $certificate = backup_encryption_parse_certificate(request_string($_POST, 'backup_certificate'));
            repo_set_setting($db, VIRTUSPHERE_SETTING_BACKUP_ENCRYPTION_CERTIFICATE, $certificate['pem']);
            audit_event($db, VIRTUSPHERE_AUDIT_EVENT_SETTINGS_CHANGED, 'setting', VIRTUSPHERE_SETTING_BACKUP_ENCRYPTION_CERTIFICATE, VIRTUSPHERE_AUDIT_RESULT_SUCCESS, [
                'action' => 'updated',
                'old_value' => $previous === null ? 'none' : substr($previous['fingerprint'], 0, 16),
                'new_value' => substr($certificate['fingerprint'], 0, 16),
            ], (int) $user['id']);
            flash_set('success', __t('settings.backup_cert_saved'));

            return;
        }
        repo_delete_setting($db, VIRTUSPHERE_SETTING_BACKUP_ENCRYPTION_CERTIFICATE);
        audit_event($db, VIRTUSPHERE_AUDIT_EVENT_SETTINGS_CHANGED, 'setting', VIRTUSPHERE_SETTING_BACKUP_ENCRYPTION_CERTIFICATE, VIRTUSPHERE_AUDIT_RESULT_SUCCESS, ['action' => 'cleared'], (int) $user['id']);
        flash_set('success', __t('settings.backup_cert_removed'));
    } catch (ValidationException $exception) {
        // The pasted text is never echoed back: it may be the private key the
        // validator just refused.
        form_remember('backup_cert', [], $exception->errors());
        flash_portal_error($exception, $user);
    } catch (Throwable $exception) {
        flash_portal_error($exception, $user);
    }
}

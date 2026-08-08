<?php

declare(strict_types=1);

require_once __DIR__ . '/../credentials.php';
require_once __DIR__ . '/../crypto.php';
require_once __DIR__ . '/../deploy_constants.php';
require_once __DIR__ . '/../validate.php';
require_once __DIR__ . '/helpers.php';

function credential_normalize_type(string $type): string
{
    $type = strtolower(trim($type));
    if (!in_array($type, VIRTUSPHERE_CREDENTIAL_TYPES, true)) {
        throw new InvalidArgumentException('Credential type must be esxi or ansible.');
    }

    return $type;
}

function credential_validate_host_literal(Validator $validator, string $field, string $host, string $label): void
{
    if (preg_match('/[\x00-\x20\x7F]/', $host) === 1) {
        $validator->add($field, validator_text('validate.host_no_whitespace', ':field must not contain whitespace or control characters.', ['field' => $label]));
        return;
    }

    if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
        return;
    }

    $hostnamePattern = '/^(?=.{1,253}$)(?:[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?\.)*[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?$/';
    if (preg_match($hostnamePattern, $host) !== 1) {
        $validator->add($field, validator_text('validate.host_invalid', ':field must be a valid hostname or IP address.', ['field' => $label]));
    }
}

function credential_validate_host(Validator $validator, string $type, mixed $value): string
{
    $hostLabel = validator_label('host', 'Host');
    $host = $validator->requireString('host', $value, $hostLabel, 255);
    if ($host === '') {
        return $host;
    }

    $hasScheme = preg_match('#^[A-Za-z][A-Za-z0-9+.-]*://#', $host) === 1;
    if (!$hasScheme) {
        credential_validate_host_literal($validator, 'host', $host, $hostLabel);
        return $host;
    }

    if ($type !== VIRTUSPHERE_CREDENTIAL_TYPE_ESXI) {
        $validator->add('host', validator_text('validate.ansible_host_no_url', 'Ansible SSH host must be a hostname or IP address, not a URL.'));
        return $host;
    }

    $parts = parse_url($host);
    if (!is_array($parts) || empty($parts['host'])) {
        $validator->add('host', validator_text('validate.esxi_url_host_missing', 'ESXi host URL must include a host.'));
        return $host;
    }

    $scheme = strtolower((string) ($parts['scheme'] ?? ''));
    if (!in_array($scheme, VIRTUSPHERE_ESXI_SCHEMES, true)) {
        $validator->add('host', validator_text('validate.esxi_url_scheme', 'ESXi host URL must use http or https.'));
    }
    if (!empty($parts['user']) || !empty($parts['pass']) || !empty($parts['query']) || !empty($parts['fragment'])) {
        $validator->add('host', validator_text('validate.esxi_url_no_userinfo', 'ESXi host URL must not include credentials, query or fragment parts.'));
    }
    if (isset($parts['path']) && !in_array((string) $parts['path'], ['', '/'], true)) {
        $validator->add('host', validator_text('validate.esxi_url_no_path', 'ESXi host URL must not include a path.'));
    }
    if (isset($parts['port']) && ((int) $parts['port'] < 1 || (int) $parts['port'] > 65535)) {
        $validator->add('host', validator_text('validate.esxi_url_port_range', 'ESXi host URL port must be between 1 and 65535.'));
    }

    credential_validate_host_literal($validator, 'host', (string) $parts['host'], validator_label('esxi_host_url_host', 'ESXi host URL host'));

    return $host;
}

function credential_name_exists(mysqli $db, string $type, string $name, int $excludeId = 0): bool
{
    if ($excludeId > 0) {
        $row = repo_fetch_one($db, 'SELECT id FROM deploy_credentials WHERE type = ? AND name = ? AND id <> ? LIMIT 1', 'ssi', [$type, $name, $excludeId]);
    } else {
        $row = repo_fetch_one($db, 'SELECT id FROM deploy_credentials WHERE type = ? AND name = ? LIMIT 1', 'ss', [$type, $name]);
    }

    return $row !== null;
}

function credential_validate_payload(mysqli $db, array $data, ?string $secret, bool $secretRequired, int $excludeId = 0): array
{
    $validator = new Validator();
    $type = $validator->enum('type', $data['type'] ?? '', validator_label('credential_type', 'Credential type'), VIRTUSPHERE_CREDENTIAL_TYPES);
    $name = $validator->requireString('name', $data['name'] ?? '', validator_label('name', 'Name'), 191);
    $host = credential_validate_host($validator, $type, $data['host'] ?? '');
    $port = $validator->optionalIntRange('port', $data['port'] ?? null, validator_label('port', 'Port'), 1, 65535);
    $username = $validator->requireString('username', $data['username'] ?? '', validator_label('username', 'Username'), 191);
    $esxiCertKind = null;
    $esxiCertificatePem = null;
    if ($type === VIRTUSPHERE_CREDENTIAL_TYPE_ESXI) {
        $rawCertificate = trim((string) ($data['esxi_certificate_pem'] ?? ''));
        if ($rawCertificate !== '') {
            $esxiCertKind = $validator->enum(
                'esxi_cert_kind',
                $data['esxi_cert_kind'] ?? '',
                validator_label('esxi_cert_kind', 'ESXi certificate type'),
                VIRTUSPHERE_ESXI_CERT_KINDS
            );
            if (in_array($esxiCertKind, VIRTUSPHERE_ESXI_CERT_KINDS, true)) {
                try {
                    $esxiCertificatePem = credential_esxi_certificate_normalize($esxiCertKind, $rawCertificate);
                } catch (InvalidArgumentException $exception) {
                    $validator->add('esxi_certificate_pem', validator_text('validate.esxi_certificate_invalid', 'The ESXi certificate is not a valid PEM certificate or bundle.'));
                }
            }
        }
    }

    if ($secretRequired && trim((string) ($secret ?? '')) === '') {
        $validator->add('secret', validator_text('validate.secret_required', 'Secret is required.'));
    }

    $validator->throwIfInvalid();

    if (credential_name_exists($db, $type, $name, $excludeId)) {
        $message = validator_text('validate.credential_name_taken_for_type', 'A credential with this name already exists for this type.');
        throw new ValidationException(['name' => $message], $message);
    }

    return [
        'type' => $type,
        'name' => $name,
        'host' => $host,
        'port' => $port,
        'username' => $username,
        'esxi_cert_kind' => $esxiCertKind,
        'esxi_certificate_pem' => $esxiCertificatePem,
    ];
}

function repo_credentials(mysqli $db): array
{
    $stmt = $db->prepare('SELECT c.id, c.type, c.name, c.host, c.port, c.username, c.esxi_trust_mode, c.esxi_cert_kind, c.esxi_certificate_pem, c.esxi_strict_tested_at, c.created_by, u.name AS created_by_name, c.created_at, c.updated_at FROM deploy_credentials c LEFT JOIN deploy_users u ON u.id = c.created_by ORDER BY c.type, c.name');
    $stmt->execute();

    return repo_fetch_all($stmt->get_result());
}

function repo_credentials_by_type(mysqli $db, string $type): array
{
    $type = credential_normalize_type($type);
    $stmt = $db->prepare('SELECT id, type, name, host, port, username, esxi_trust_mode, esxi_cert_kind, esxi_certificate_pem, esxi_strict_tested_at, created_at, updated_at FROM deploy_credentials WHERE type = ? ORDER BY name');
    $stmt->bind_param('s', $type);
    $stmt->execute();

    return repo_fetch_all($stmt->get_result());
}

function repo_credential(mysqli $db, int $id, bool $includeSecret = false): ?array
{
    if ($includeSecret) {
        $stmt = $db->prepare('SELECT id, type, name, host, port, username, secret_ciphertext, esxi_trust_mode, esxi_cert_kind, esxi_certificate_pem, esxi_strict_tested_at, created_by, created_at, updated_at FROM deploy_credentials WHERE id = ? LIMIT 1');
    } else {
        $stmt = $db->prepare('SELECT id, type, name, host, port, username, esxi_trust_mode, esxi_cert_kind, esxi_certificate_pem, esxi_strict_tested_at, created_by, created_at, updated_at FROM deploy_credentials WHERE id = ? LIMIT 1');
    }
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();

    return $row ?: null;
}

function repo_credential_secret(mysqli $db, int $id): string
{
    $row = repo_credential($db, $id, true);
    if ($row === null) {
        throw new RuntimeException('Credential not found.');
    }

    return crypto_decrypt_secret((string) $row['secret_ciphertext']);
}

function repo_create_credential(mysqli $db, array $data, string $secret, int $createdBy): int
{
    $values = credential_validate_payload($db, $data, $secret, true);
    credential_assert_strict_esxi_https($values, VIRTUSPHERE_ESXI_TRUST_DEFAULT_NEW);
    $ciphertext = crypto_encrypt_secret($secret);
    $trustMode = VIRTUSPHERE_ESXI_TRUST_DEFAULT_NEW;

    $stmt = $db->prepare('INSERT INTO deploy_credentials (type, name, host, port, username, secret_ciphertext, esxi_trust_mode, esxi_cert_kind, esxi_certificate_pem, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
    $stmt->bind_param('sssisssssi', $values['type'], $values['name'], $values['host'], $values['port'], $values['username'], $ciphertext, $trustMode, $values['esxi_cert_kind'], $values['esxi_certificate_pem'], $createdBy);
    $stmt->execute();

    return (int) $db->insert_id;
}

function repo_update_credential(mysqli $db, int $id, array $data, ?string $secret = null): bool
{
    if ($id <= 0) {
        throw new InvalidArgumentException('Credential id is required.');
    }

    $current = repo_credential($db, $id, true);
    if ($current === null) {
        throw new RuntimeException('Credential not found.');
    }
    $values = credential_validate_payload($db, $data, $secret, false, $id);
    $effectiveTrustMode = $values['type'] === VIRTUSPHERE_CREDENTIAL_TYPE_ESXI
        ? credential_esxi_trust_mode($current)
        : VIRTUSPHERE_ESXI_TRUST_LEGACY_INSECURE;
    credential_assert_strict_esxi_https($values, $effectiveTrustMode);
    $secretChanges = $secret !== null && trim($secret) !== '';
    $resetStrictTest = $secretChanges
        || (string) ($current['type'] ?? '') !== $values['type']
        || trim((string) ($current['host'] ?? '')) !== trim((string) $values['host'])
        || (string) ($current['port'] ?? '') !== (string) ($values['port'] ?? '')
        || trim((string) ($current['username'] ?? '')) !== trim((string) $values['username'])
        || (string) ($current['esxi_cert_kind'] ?? '') !== (string) ($values['esxi_cert_kind'] ?? '')
        || trim((string) ($current['esxi_certificate_pem'] ?? '')) !== trim((string) ($values['esxi_certificate_pem'] ?? ''));
    $resetStrictTestInt = $resetStrictTest ? 1 : 0;

    // A blank or whitespace-only secret means "keep the stored secret". Real
    // secrets are encrypted verbatim (no trim), so surrounding spaces survive.
    if ($secretChanges) {
        $ciphertext = crypto_encrypt_secret($secret);
        $stmt = $db->prepare('UPDATE deploy_credentials SET type = ?, name = ?, host = ?, port = ?, username = ?, secret_ciphertext = ?, esxi_cert_kind = ?, esxi_certificate_pem = ?, esxi_strict_tested_at = IF(? = 1, NULL, esxi_strict_tested_at), updated_at = NOW() WHERE id = ?');
        $stmt->bind_param('sssissssii', $values['type'], $values['name'], $values['host'], $values['port'], $values['username'], $ciphertext, $values['esxi_cert_kind'], $values['esxi_certificate_pem'], $resetStrictTestInt, $id);
    } else {
        $stmt = $db->prepare('UPDATE deploy_credentials SET type = ?, name = ?, host = ?, port = ?, username = ?, esxi_cert_kind = ?, esxi_certificate_pem = ?, esxi_strict_tested_at = IF(? = 1, NULL, esxi_strict_tested_at), updated_at = NOW() WHERE id = ?');
        $stmt->bind_param('sssisssii', $values['type'], $values['name'], $values['host'], $values['port'], $values['username'], $values['esxi_cert_kind'], $values['esxi_certificate_pem'], $resetStrictTestInt, $id);
    }

    $stmt->execute();
    if ($stmt->affected_rows === 0 && repo_credential($db, $id) === null) {
        throw new RuntimeException('Credential not found.');
    }

    return true;
}

function credential_assert_strict_esxi_https(array $values, string $trustMode): void
{
    if (($values['type'] ?? '') !== VIRTUSPHERE_CREDENTIAL_TYPE_ESXI || $trustMode !== VIRTUSPHERE_ESXI_TRUST_STRICT) {
        return;
    }
    $endpoint = credential_esxi_normalize((string) ($values['host'] ?? ''), $values['port'] ?? null);
    if ($endpoint !== null && $endpoint['scheme'] === 'https') {
        return;
    }

    $message = validator_text('validate.esxi_strict_https', 'Strict ESXi certificate verification requires an HTTPS host URL.');
    throw new ValidationException(['host' => $message], $message);
}

function repo_record_esxi_strict_test_success(mysqli $db, int $id): bool
{
    $stmt = $db->prepare("UPDATE deploy_credentials SET esxi_strict_tested_at = NOW() WHERE id = ? AND type = 'esxi'");
    $stmt->bind_param('i', $id);
    $stmt->execute();

    return $stmt->affected_rows === 1;
}

function repo_activate_esxi_strict_trust(mysqli $db, int $id): bool
{
    $credential = repo_credential($db, $id);
    if ($credential === null || (string) ($credential['type'] ?? '') !== VIRTUSPHERE_CREDENTIAL_TYPE_ESXI) {
        throw new RuntimeException('ESXi credential not found.');
    }
    $endpoint = credential_esxi_normalize((string) ($credential['host'] ?? ''), $credential['port'] ?? null);
    if ($endpoint === null || $endpoint['scheme'] !== 'https') {
        throw new RuntimeException('Strict ESXi certificate verification requires HTTPS.');
    }
    credential_esxi_certificate_normalize(
        (string) ($credential['esxi_cert_kind'] ?? ''),
        (string) ($credential['esxi_certificate_pem'] ?? '')
    );
    if (empty($credential['esxi_strict_tested_at'])) {
        throw new RuntimeException('Strict ESXi certificate verification must pass a connection test before activation.');
    }

    $stmt = $db->prepare("UPDATE deploy_credentials SET esxi_trust_mode = 'strict', updated_at = NOW() WHERE id = ? AND type = 'esxi'");
    $stmt->bind_param('i', $id);
    $stmt->execute();

    return $stmt->affected_rows === 1 || credential_esxi_trust_mode(repo_credential($db, $id) ?? []) === VIRTUSPHERE_ESXI_TRUST_STRICT;
}

function repo_activate_esxi_legacy_trust(mysqli $db, int $id): bool
{
    $stmt = $db->prepare("UPDATE deploy_credentials SET esxi_trust_mode = 'legacy_insecure', esxi_strict_tested_at = NULL, updated_at = NOW() WHERE id = ? AND type = 'esxi'");
    $stmt->bind_param('i', $id);
    $stmt->execute();
    if ($stmt->affected_rows === 0) {
        $credential = repo_credential($db, $id);
        if ($credential === null || (string) ($credential['type'] ?? '') !== VIRTUSPHERE_CREDENTIAL_TYPE_ESXI) {
            throw new RuntimeException('ESXi credential not found.');
        }
    }

    return true;
}

function repo_delete_credential(mysqli $db, int $id): bool
{
    if ($id <= 0) {
        throw new InvalidArgumentException('Credential id is required.');
    }

    $active = (int) repo_scalar(
        $db,
        'SELECT COUNT(*) FROM deploy_jobs WHERE status IN (?, ?) AND (credential_esxi_id = ? OR credential_ansible_id = ?)',
        'ssii',
        [VIRTUSPHERE_DEPLOY_STATUS_QUEUED, VIRTUSPHERE_DEPLOY_STATUS_RUNNING, $id, $id]
    );
    if ($active > 0) {
        throw new RuntimeException('Credential is used by an active deploy job.');
    }

    $stmt = $db->prepare('DELETE FROM deploy_credentials WHERE id = ?');
    $stmt->bind_param('i', $id);
    $stmt->execute();
    if ($stmt->affected_rows === 0) {
        throw new RuntimeException('Credential not found.');
    }

    return true;
}

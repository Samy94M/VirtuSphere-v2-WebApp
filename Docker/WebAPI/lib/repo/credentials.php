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
    ];
}

function repo_credentials(mysqli $db): array
{
    $stmt = $db->prepare('SELECT c.id, c.type, c.name, c.host, c.port, c.username, c.created_by, u.name AS created_by_name, c.created_at, c.updated_at FROM deploy_credentials c LEFT JOIN deploy_users u ON u.id = c.created_by ORDER BY c.type, c.name');
    $stmt->execute();

    return repo_fetch_all($stmt->get_result());
}

function repo_credentials_by_type(mysqli $db, string $type): array
{
    $type = credential_normalize_type($type);
    $stmt = $db->prepare('SELECT id, type, name, host, port, username, created_at, updated_at FROM deploy_credentials WHERE type = ? ORDER BY name');
    $stmt->bind_param('s', $type);
    $stmt->execute();

    return repo_fetch_all($stmt->get_result());
}

function repo_credential(mysqli $db, int $id, bool $includeSecret = false): ?array
{
    if ($includeSecret) {
        $stmt = $db->prepare('SELECT id, type, name, host, port, username, secret_ciphertext, created_by, created_at, updated_at FROM deploy_credentials WHERE id = ? LIMIT 1');
    } else {
        $stmt = $db->prepare('SELECT id, type, name, host, port, username, created_by, created_at, updated_at FROM deploy_credentials WHERE id = ? LIMIT 1');
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
    $ciphertext = crypto_encrypt_secret($secret);

    $stmt = $db->prepare('INSERT INTO deploy_credentials (type, name, host, port, username, secret_ciphertext, created_by) VALUES (?, ?, ?, ?, ?, ?, ?)');
    $stmt->bind_param('sssissi', $values['type'], $values['name'], $values['host'], $values['port'], $values['username'], $ciphertext, $createdBy);
    $stmt->execute();

    return (int) $db->insert_id;
}

function repo_update_credential(mysqli $db, int $id, array $data, ?string $secret = null): bool
{
    if ($id <= 0) {
        throw new InvalidArgumentException('Credential id is required.');
    }

    $values = credential_validate_payload($db, $data, $secret, false, $id);

    // A blank or whitespace-only secret means "keep the stored secret". Real
    // secrets are encrypted verbatim (no trim), so surrounding spaces survive.
    if ($secret !== null && trim($secret) !== '') {
        $ciphertext = crypto_encrypt_secret($secret);
        $stmt = $db->prepare('UPDATE deploy_credentials SET type = ?, name = ?, host = ?, port = ?, username = ?, secret_ciphertext = ?, updated_at = NOW() WHERE id = ?');
        $stmt->bind_param('sssissi', $values['type'], $values['name'], $values['host'], $values['port'], $values['username'], $ciphertext, $id);
    } else {
        $stmt = $db->prepare('UPDATE deploy_credentials SET type = ?, name = ?, host = ?, port = ?, username = ?, updated_at = NOW() WHERE id = ?');
        $stmt->bind_param('sssisi', $values['type'], $values['name'], $values['host'], $values['port'], $values['username'], $id);
    }

    $stmt->execute();
    if ($stmt->affected_rows === 0 && repo_credential($db, $id) === null) {
        throw new RuntimeException('Credential not found.');
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

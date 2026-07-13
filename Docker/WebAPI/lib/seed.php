<?php

declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/permissions.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

// No elvis-style env fallback here (GROK forbidden pattern #6): getenv()
// returns false when unset, the string cast folds that to '', and the usage
// check below rejects empty credentials. There is no silent default secret.
$name = (string) ($argv[1] ?? getenv('SEED_ADMIN_USER'));
$password = (string) ($argv[2] ?? getenv('SEED_ADMIN_PASSWORD'));
$email = (string) ($argv[3] ?? getenv('SEED_ADMIN_EMAIL'));
if ($email === '') {
    $email = 'admin@localhost';
}

if ($name === '' || $password === '') {
    fwrite(STDERR, "Usage: php lib/seed.php <admin-user> <admin-password> [email]\n");
    exit(2);
}

$db = db();
$stmt = $db->prepare('SELECT COUNT(*) AS c FROM deploy_users');
$stmt->execute();
$result = $stmt->get_result();
$row = $result->fetch_assoc();
$result->free();
if (!is_array($row) || !array_key_exists('c', $row)) {
    throw new RuntimeException('Seed user count returned no count.');
}

$count = (int) $row['c'];
if ($count > 0) {
    fwrite(STDOUT, "seed: users already exist\n");
    exit(0);
}

$hash = password_hash($password, PASSWORD_DEFAULT);
$role = VIRTUSPHERE_ROLE_ADMIN;
$active = 1;
$mustChange = 1;
$stmt = $db->prepare('INSERT INTO deploy_users (name, password, email, role, is_active, must_change_password) VALUES (?, ?, ?, ?, ?, ?)');
$stmt->bind_param('ssssii', $name, $hash, $email, $role, $active, $mustChange);
$stmt->execute();

fwrite(STDOUT, "seed: admin created; password change required\n");

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
//
// Forms, by argument count (argv[0] is the script):
//   <user> <email>             the documented form. The password is read from
//                              STDIN, one line: an argument would stay in the
//                              shell history and in the process list.
//   <user> <password> [email]  the legacy positional form, kept for scripts.
//   no arguments               SEED_ADMIN_* (Docker/scripts/setup.sh); an empty
//                              password is read from STDIN.
$usage = "Usage: php lib/seed.php <admin-user> <admin-email>   (password: one line on STDIN)\n"
    . "       php lib/seed.php <admin-user> <admin-password> [email]   (legacy form)\n";
$arguments = array_slice($argv ?? [], 1);
$name = (string) ($arguments[0] ?? getenv('SEED_ADMIN_USER'));
if (count($arguments) === 2) {
    $email = (string) $arguments[1];
    if (!str_contains($email, '@')) {
        // The legacy form `<user> <password>` lands here too: it must not turn
        // the password into an email address.
        fwrite(STDERR, $usage);
        exit(2);
    }
    $password = '';
} else {
    $password = (string) ($arguments[1] ?? getenv('SEED_ADMIN_PASSWORD'));
    $email = (string) ($arguments[2] ?? getenv('SEED_ADMIN_EMAIL'));
}
if ($email === '') {
    $email = 'admin@localhost';
}
if ($name !== '' && $password === '') {
    // One line from STDIN; only the line terminator is removed.
    $password = rtrim((string) fgets(STDIN), "\r\n");
}

if ($name === '' || $password === '') {
    fwrite(STDERR, $usage);
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

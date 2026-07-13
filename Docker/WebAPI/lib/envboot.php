<?php

declare(strict_types=1);

require_once __DIR__ . '/errors.php';

virtusphere_install_error_handlers();

function envboot_load_dotenv(): void
{
    static $loaded = false;
    if ($loaded) {
        return;
    }

    $loaded = true;
    $paths = [
        dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . '.env',
        dirname(__DIR__) . DIRECTORY_SEPARATOR . '.env',
    ];

    foreach ($paths as $path) {
        if (!is_file($path) || !is_readable($path)) {
            continue;
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) {
            continue;
        }

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
                continue;
            }

            [$name, $value] = explode('=', $line, 2);
            $name = trim($name);
            $value = trim($value);
            if ($name === '' || getenv($name) !== false) {
                continue;
            }

            if ((str_starts_with($value, '"') && str_ends_with($value, '"')) || (str_starts_with($value, "'") && str_ends_with($value, "'"))) {
                $value = substr($value, 1, -1);
            }

            putenv($name . '=' . $value);
            $_ENV[$name] = $value;
        }
    }
}

function envboot_required(string $name): string
{
    envboot_load_dotenv();
    $value = getenv($name);
    if ($value === false || trim((string) $value) === '') {
        throw new RuntimeException('Missing required environment value: ' . $name);
    }

    return (string) $value;
}

function envboot_optional(string $name, string $default): string
{
    envboot_load_dotenv();
    $value = getenv($name);
    if ($value === false || trim((string) $value) === '') {
        return $default;
    }

    return (string) $value;
}

function envboot_app_key_bytes(): string
{
    $raw = envboot_required('APP_KEY');
    if (str_starts_with($raw, 'base64:')) {
        $raw = substr($raw, 7);
    }

    $bytes = base64_decode($raw, true);
    $expectedBytes = defined('SODIUM_CRYPTO_SECRETBOX_KEYBYTES') ? SODIUM_CRYPTO_SECRETBOX_KEYBYTES : 32;
    if ($bytes === false || strlen($bytes) !== $expectedBytes) {
        throw new RuntimeException('APP_KEY must contain exactly 32 random bytes encoded as base64.');
    }

    return $bytes;
}

function envboot_assert_secure_runtime(): void
{
    foreach (['DB_HOST', 'DB_NAME', 'DB_USER', 'DB_PASS', 'MYSQL_ROOT_PASSWORD', 'APP_KEY'] as $name) {
        envboot_required($name);
    }

    foreach (['DB_PASS', 'MYSQL_ROOT_PASSWORD'] as $name) {
        $value = envboot_required($name);
        if (strlen($value) < 16 || preg_match('/^(change-me|password|secret|root|admin)/i', $value) === 1) {
            throw new RuntimeException($name . ' is missing or too weak for runtime boot.');
        }
    }

    envboot_app_key_bytes();
}
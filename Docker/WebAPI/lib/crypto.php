<?php

declare(strict_types=1);

require_once __DIR__ . '/envboot.php';

function crypto_encrypt_secret(string $plaintext): string
{
    if ($plaintext === '') {
        throw new InvalidArgumentException('Secret must not be empty.');
    }
    if (!function_exists('sodium_crypto_secretbox')) {
        throw new RuntimeException('libsodium is required for credential encryption.');
    }

    $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
    $ciphertext = sodium_crypto_secretbox($plaintext, $nonce, envboot_app_key_bytes());

    return 'v1:' . base64_encode($nonce . $ciphertext);
}

function crypto_decrypt_secret(string $stored): string
{
    if (!str_starts_with($stored, 'v1:')) {
        throw new RuntimeException('Unsupported encrypted secret format.');
    }
    if (!function_exists('sodium_crypto_secretbox_open')) {
        throw new RuntimeException('libsodium is required for credential decryption.');
    }

    $payload = base64_decode(substr($stored, 3), true);
    if ($payload === false || strlen($payload) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
        throw new RuntimeException('Encrypted secret payload is invalid.');
    }

    $nonce = substr($payload, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
    $ciphertext = substr($payload, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
    $plaintext = sodium_crypto_secretbox_open($ciphertext, $nonce, envboot_app_key_bytes());
    if ($plaintext === false) {
        throw new RuntimeException('Encrypted secret could not be decrypted.');
    }

    return $plaintext;
}
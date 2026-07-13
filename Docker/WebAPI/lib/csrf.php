<?php

declare(strict_types=1);

function csrf_token(): string
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        throw new RuntimeException('CSRF token requires an active session.');
    }

    if (empty($_SESSION['_csrf'])) {
        $_SESSION['_csrf'] = bin2hex(random_bytes(32));
    }

    return (string) $_SESSION['_csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="_csrf" value="' . htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') . '">';
}

/**
 * @param mixed $token the raw request value; an array (`_csrf[]=x`) must reject,
 *                     not reach hash_equals and throw a TypeError into a 500
 */
function csrf_verify(mixed $token): bool
{
    if (session_status() !== PHP_SESSION_ACTIVE || empty($_SESSION['_csrf']) || !is_string($token)) {
        return false;
    }

    return hash_equals((string) $_SESSION['_csrf'], $token);
}
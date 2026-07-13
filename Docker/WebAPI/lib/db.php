<?php

declare(strict_types=1);

require_once __DIR__ . '/errors.php';
require_once __DIR__ . '/envboot.php';

virtusphere_install_error_handlers();
virtusphere_assert_log_dir_writable();

function db(bool $reconnect = false): mysqli
{
    static $connection = null;

    if ($reconnect && $connection instanceof mysqli) {
        try {
            $connection->close();
        } catch (Throwable) {
        }
        $connection = null;
    }

    if ($connection instanceof mysqli) {
        return $connection;
    }

    envboot_assert_secure_runtime();

    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

    $connection = new mysqli(
        envboot_required('DB_HOST'),
        envboot_required('DB_USER'),
        envboot_required('DB_PASS'),
        envboot_required('DB_NAME'),
        (int) envboot_optional('DB_PORT', '3306')
    );
    $connection->set_charset('utf8mb4');
    // Pin the session to UTC so TIMESTAMP reads and NOW()/UTC_TIMESTAMP() are
    // identical regardless of the container's system timezone (ADR-0022). The
    // portal converts to the display timezone only at render time.
    $connection->query("SET time_zone = '+00:00'");

    return $connection;
}
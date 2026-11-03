<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/errors.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/ansible_test_scheduler.php';
require_once __DIR__ . '/log_redaction.php';
require_once __DIR__ . '/worker_stop_signal.php';

virtusphere_install_error_handlers();
worker_install_stop_handler('ansible-test-worker');

try {
    if (!function_exists('pcntl_alarm')) {
        throw new RuntimeException('Scheduled Ansible diagnostics require pcntl.');
    }
    pcntl_signal(SIGALRM, static function (): never {
        fwrite(STDERR, "[ansible-test-worker] full test exceeded its total time budget; inspect stored evidence\n");
        exit(2);
    });
    pcntl_alarm(VIRTUSPHERE_ANSIBLE_TEST_TOTAL_TIMEOUT_SECONDS);
    if (worker_stop_requested()) {
        exit;
    }
    ansible_test_run_due(db(true));
    pcntl_alarm(0);
} catch (Throwable $exception) {
    fwrite(STDERR, '[ansible-test-worker] ' . virtusphere_redact_log_text($exception->getMessage()) . "\n");
    exit(1);
}

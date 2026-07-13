<?php

declare(strict_types=1);

require_once __DIR__ . '/../lib/lang.php';
Lang::load(Lang::DEFAULT_LOCALE);

require_once __DIR__ . '/../lib/validate.php';
require_once __DIR__ . '/../lib/permissions.php';
require_once __DIR__ . '/../lib/constants.php';
require_once __DIR__ . '/../lib/deploy_constants.php';
require_once __DIR__ . '/../lib/forms.php';

function virtusphere_test_base_url(): string
{
    $baseUrl = getenv('VIRTUSPHERE_TEST_BASE_URL');
    if (!is_string($baseUrl) || trim($baseUrl) === '') {
        return 'http://webserver:8080';
    }

    return rtrim($baseUrl, '/');
}

<?php

declare(strict_types=1);

/**
 * MECM terms of an English MECM console (PR-R3M, i18n R15).
 *
 * Same names as lib/mecm_terms/de.php. The `de` form is the word inside a
 * German portal text (German capitalises nouns and joins compounds with a
 * hyphen), the `en` form the word inside English running text, lower case as
 * in the Microsoft documentation; `SMS Provider` stays capitalised there too.
 */
return [
    'task_sequence' => ['de' => 'Task Sequence', 'en' => 'task sequence'],
    'task_sequences' => ['de' => 'Task Sequences', 'en' => 'task sequences'],
    'distribution_point' => ['de' => 'Distribution Point', 'en' => 'distribution point'],
    'distribution_points' => ['de' => 'Distribution Points', 'en' => 'distribution points'],
    'site' => ['de' => 'Site', 'en' => 'site'],
    'sites' => ['de' => 'Sites', 'en' => 'sites'],
    'the_site' => ['de' => 'die Site', 'en' => 'the site'],
    'site_code' => ['de' => 'Site-Code', 'en' => 'site code'],
    'site_server' => ['de' => 'Site-Server', 'en' => 'site server'],
    'site_database_server' => ['de' => 'Site-Datenbankserver', 'en' => 'site database server'],
    'site_status' => ['de' => 'Site-Status', 'en' => 'site status'],
    'sms_provider' => ['de' => 'SMS Provider', 'en' => 'SMS Provider'],
    'sms_providers' => ['de' => 'SMS Provider', 'en' => 'SMS Providers'],
    'collection' => ['de' => 'Collection', 'en' => 'collection'],
    'collections' => ['de' => 'Collections', 'en' => 'collections'],
    'deployment' => ['de' => 'Deployment', 'en' => 'deployment'],
    'deployments' => ['de' => 'Deployments', 'en' => 'deployments'],
    // PR-R3MP: object words and console paths. A path is the console text, the
    // same in both portal languages.
    'application' => ['de' => 'Application', 'en' => 'application'],
    'applications' => ['de' => 'Applications', 'en' => 'applications'],
    'deployment_type' => ['de' => 'Deployment Type', 'en' => 'deployment type'],
    'deployment_types' => ['de' => 'Deployment Types', 'en' => 'deployment types'],
    'path_system_status' => ['de' => 'Monitoring → System Status', 'en' => 'Monitoring → System Status'],
    'path_component_status' => ['de' => 'Monitoring → System Status → Component Status', 'en' => 'Monitoring → System Status → Component Status'],
    'path_content_status' => ['de' => 'Monitoring → Distribution Status → Content Status', 'en' => 'Monitoring → Distribution Status → Content Status'],
    'path_devices' => ['de' => 'Assets and Compliance → Devices', 'en' => 'Assets and Compliance → Devices'],
    'path_device_collections' => ['de' => 'Assets and Compliance → Device Collections', 'en' => 'Assets and Compliance → Device Collections'],
    'path_applications' => ['de' => 'Software Library → Application Management → Applications', 'en' => 'Software Library → Application Management → Applications'],
];

<?php

declare(strict_types=1);

/*
 * The closed page registry (A62): every rendered portal entrypoint, by script
 * name. layout.php selects each page's stylesheets and scripts from it and
 * fails closed on an unknown page; login_return.php accepts a sign-in target
 * only from it. Its own file, without dependencies, because logout.php and
 * every bootstrap closure reach the sign-in target without the layout.
 */
const VIRTUSPHERE_LAYOUT_PAGES = [
    'account.php',
    'credentials.php',
    'dashboard.php',
    'deploy.php',
    'deploy_log.php',
    'help.php',
    'login.php',
    'logs.php',
    'mission_details.php',
    'missions.php',
    'os.php',
    'package_run.php',
    'package_reports.php',
    'packages.php',
    'settings.php',
    'system_status.php',
    'users.php',
    'vlans.php',
    'vm_edit.php',
    'vm_overview.php',
    'vms.php',
];

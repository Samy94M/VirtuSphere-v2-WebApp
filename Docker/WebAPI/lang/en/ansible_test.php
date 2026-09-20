<?php
declare(strict_types=1);
return [
    'title' => 'Automatic Ansible full test',
    'interval' => 'Test interval (hours, 0 = off)',
    'hint' => 'Checks every registered Ansible credential using the same full test as “Run full test now”: SSH, tools, SFTP and, when an API return URL is configured, portal connectivity. Default: :default hours. Whole hours up to :max are allowed; 0 disables automation.',
    'behaviour' => 'The deployment service starts due tests in the background when it is idle and accepting jobs. The interval starts at the last test attempt, including failures and interruptions. Manual tests also move the next due time. Changes apply to future tests; they do not cancel a test already running.',
    'invalid' => 'Enter a whole number of hours between :min and :max.',
    'status_link' => 'Open Ansible system status',
    'configure' => 'Change test interval',
    'cadence' => 'Automatically every :hours h when the service is idle and accepting jobs; evidence valid for :days days. Manual full tests remain available.',
    'cadence_off' => 'Automatic full test disabled; manual testing on click. Evidence valid for :days days.',
    'help' => 'Users with settings permission can change the interval under Settings → Catalogs and inventory. A credential without a previous test is due at the next idle pass. A busy, paused or stopped deployment service postpones the start. Editing credentials makes a new test due. An older test cannot overwrite newer test evidence or prove a newer configuration. Results appear in System status and the test logs, which identify scheduled tests. A deployment does not replace this full test.',
];

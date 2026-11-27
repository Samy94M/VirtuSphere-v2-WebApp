<?php
declare(strict_types=1);
return [
    'title' => 'Automatic Ansible full test',
    'interval' => 'Test interval (hours, 0 = off)',
    'hint' => 'Checks every registered Ansible credential with the full test at this interval. The default is :default hours, whole hours up to :max are allowed; 0 turns the automation off.',
    'behaviour' => 'The deploy service starts due tests in the background when it is idle; the interval counts from the last test start.',
    'invalid' => 'Enter a whole number of hours between :min and :max.',
    'status_link' => 'Open System status',
    'configure' => 'Change test interval',
    'cadence' => 'Automatically every :hours h when the service is idle and accepting jobs; evidence valid for :days days. Manual full tests remain available.',
    'cadence_off' => 'Automatic full test disabled; manual testing on click. Evidence valid for :days days.',
    'help' => 'Users with settings permission can change the interval under Settings → Catalog and inventory. A credential without a previous test is due at the next idle pass. A busy, paused or stopped deploy service postpones the start. Editing credentials makes a new test due. An older test cannot overwrite newer test evidence or prove a newer configuration. Results appear in System status and the test logs, which identify scheduled tests. A deploy does not replace this full test.',
    'sweep_closed' => 'Removed ESXi access files of earlier jobs: :count.',
    'sweep_aborted' => 'Cleanup of earlier ESXi access files stopped (removed before the stop: :count). The rest follows at the next contact with this Ansible host.',
    'secret_cleanup_missing' => 'The timer virtusphere-secret-cleanup.timer is missing on the Ansible host. Access files of aborted jobs stay there until a contact removes them. One-time setup as root on the Ansible host from the offline bundle: sudo sh virtusphere/Ansible/host-cleanup/install-secret-cleanup.sh <ansible-account>.',
];

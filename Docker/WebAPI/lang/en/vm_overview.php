<?php

declare(strict_types=1);

return [
    'title_all' => 'VMs in missions',
    'title_mecm_pending' => 'MECM pending',
    'intro_all' => 'All VMs in real missions. Templates are not counted here.',
    'intro_mecm_pending' => 'VMs waiting for a takeover by MECM: marked for MECM or with a pending MECM status. Templates belong to this count and are marked.',
    'filter_label' => 'Choose view',
    'filter_all' => 'VMs in missions',
    'filter_mecm_pending' => 'MECM pending',
    // The four "check due" groups of the dashboard (SP4, F6); group, filter and
    // list share one name.
    'filter_mecm_overdue' => 'MECM waiting longer than :hours h',
    'filter_os_install_overdue' => 'OS installation longer than :hours h',
    'filter_job_failed' => 'Job failed',
    'filter_create_uncertain' => 'Create unresolved',
    'intro_mecm_overdue' => 'VMs in real missions that have been waiting for the takeover by MECM for longer than :hours h. Templates are not counted.',
    'intro_os_install_overdue' => 'VMs in real missions whose OS installation has been observed for longer than :hours h without completion. Templates are not counted.',
    'intro_job_failed' => 'VMs in real missions whose last job failed or ended with an unknown outcome. The finding of each row says which applies. Templates are not counted.',
    'intro_create_uncertain' => 'VMs in real missions with a Create unit whose result on the host is unknown. Templates are not counted.',
    'empty_mecm_overdue' => 'No VM is overdue waiting for MECM right now.',
    'empty_os_install_overdue' => 'No OS installation is overdue right now.',
    'empty_job_failed' => 'No VM has a failed job or a job with an unknown outcome right now.',
    'empty_create_uncertain' => 'No Create is unresolved right now.',
    'template_badge' => 'Template',
    'open_vm' => 'Open VM',
    'hits' => 'Hits: :count',
    'count_changed' => 'Since the count at :counted, the number changed from :expected to :actual; the list shows the current state.',
    'empty_all' => 'No VM in a real mission found.',
    'empty_mecm_pending' => 'No VM is waiting for a MECM takeover right now.',
    'back_dashboard' => 'Back to dashboard',
    'back_to_list' => 'Back to ":list"',
    'pagination_label' => 'Pages of the list',
    'previous' => 'Previous page',
    'next' => 'Next page',
    // :current and :last, not :page and :pages: see the German catalog.
    'page_info' => 'Page :current of :last',
];

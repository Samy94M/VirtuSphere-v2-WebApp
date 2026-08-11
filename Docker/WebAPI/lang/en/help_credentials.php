<?php

declare(strict_types=1);

return [
    'credentials_heading' => 'Which credentials belong here?',
    'credentials_p1' => 'The Credentials page deliberately supports only two account types. Secrets are stored encrypted and are never rendered back into a form or log.',
    'credentials_ansible' => 'Ansible: SSH account of the Linux execution host. The test checks connectivity, toolchain, SFTP, the portal return path and IP allowlisting.',
    'credentials_esxi' => 'ESXi: vSphere/ESXi account. The action starts a real read-only inventory pull through the selected Ansible host.',
    'credentials_mecm' => 'MECM: no account on this page. MECM uses the machine API IP allowlist and optionally the report token under Settings.',
    'credentials_trust_heading' => 'ESXi certificate verification',
    'credentials_trust_why_heading' => 'Why?',
    'credentials_trust_why' => 'Encryption alone does not prove which host is on the other end. Without certificate verification, an attacker on the LAN can impersonate ESXi and capture the ESXi account.',
    'credentials_trust_what_heading' => 'What is stored?',
    'credentials_trust_what' => 'A CA bundle trusts an issuing PKI and is the robust choice for regular certificate rotation. A single server certificate pins exactly that certificate; when it is renewed, replace and retest the pin.',
    'credentials_trust_how_heading' => 'How is it switched?',
    'credentials_trust_how' => 'Export the certificate as PEM from the ESXi Host Client or browser, store it in the credential and start the strict test. After it succeeds, the separate activation action appears. Legacy insecure explicitly means encrypted, but with an unverified host identity.',
    'credentials_tests_heading' => 'Tests, inventory pulls and several Ansible credentials',
    'credentials_tests_p1' => 'The manual Ansible full test runs immediately. Its latest status and timestamp remain visible in System status; it is not repeated automatically. After :days days without a test the traffic light therefore reads "Test outdated": this is not a reported failure, but says that today\'s state is unknown. The latest completed mission job is shown separately and does not refresh the full test.',
    'credentials_tests_p2' => 'An ESXi inventory pull is a background job. Queued, running, success, failure and auth pause appear on the matching ESXi card; full inventory details load only when that card is opened.',
    'credentials_cadence_heading' => 'The line under the status: does this refresh itself?',
    'credentials_cadence_p1' => 'Below the badge and the timestamp, every row says whether the value renews itself. An ESXi credential is pulled on the configured cycle; an Ansible credential only when somebody clicks "Check connection and environment". For ESXi there are three reasons why nothing runs anyway, and the line names the one that has to be fixed first:',
    'credentials_cadence_off' => '"no automatic pull (interval 0)": the automation is switched off. Set it under Settings, Catalogues and inventory.',
    'credentials_cadence_no_ansible' => '"no automatic pull, no Ansible credential selected": the pull runs over an Ansible host, and there is none, or several without a choice, or the chosen one was deleted. Select one under Settings, Catalogues and inventory. While this is open the timestamp stops moving too, because not even an attempt is made.',
    'credentials_cadence_paused' => '"paused, no automatic pull": this credential alone is held after login failures so the account cannot be locked out. It resumes as soon as the credential is saved; a single attempt is always available through "Start inventory pull".',
    'credentials_tests_p3' => 'With exactly one Ansible credential it is used automatically. With several, exactly one must be selected under Settings → Catalogues and inventory. If that credential is deleted or changed to ESXi, VirtuSphere clears and audits the selection.',
];

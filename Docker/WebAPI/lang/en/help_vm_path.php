<?php

declare(strict_types=1);

return [
    'heading' => 'The path of a VM',
    'intro' => 'Every VM passes through the same phases. The figure shows them in order and names the evidence that sets each one. What a phase requires and which findings can come with it is explained in the section ":section".',
    'desc' => 'The phases in order: :phases.',
    // One sentence per phase on the evidence that sets it; keys follow VIRTUSPHERE_VM_PHASES.
    'caption_created' => 'Created, imported or copied in the portal',
    'caption_in_progress' => 'A deploy job has marked the VM',
    'caption_on_esxi' => 'Create or the MAC export binds the VM',
    'caption_mac_known' => 'The Ansible host reports the MAC',
    'caption_handed_to_mecm' => 'The device sync reports the ResourceID',
    'caption_windows_reports' => 'The client confirms it is ready',
    'caption_client_done' => 'Every client phase reports "finished"',
    'note' => 'The highest phase whose evidence has arrived applies; a VM can skip phases. A failed job does not move it back; it appears as a finding.',
];

<?php

declare(strict_types=1);

return [
    'intro_heading' => 'What is VirtuSphere?',
    'intro_p1' => 'VirtuSphere plans and deploys virtual machines for training and test missions. This web interface is the VirtuSphere portal, the operator-facing part of the portal; below, "portal" always refers to this interface (the machine-facing part of the portal is the machine API for MECM, Ansible and the Windows clients, with no interface of its own). A mission bundles a group of VMs that are created together on ESXi and configured through Ansible.',
    'intro_p2' => 'Templates are reusable mission blueprints (name starts with "_"). A template can be cloned into a new, independent mission at any time, with fresh VM IDs and empty MAC addresses.',
    'workflow_heading' => 'Workflow in short',
    'workflow_step1' => '1. Create a mission or template under "Missions" / "Templates" and define the VMs you need.',
    'workflow_step2' => '2. Add an ESXi credential and an Ansible credential under "Credentials" (if not already present).',
    'workflow_step3' => '3. Under "Deploy", pick the mission, ESXi and Ansible credential and the mode, then queue the job.',
    'workflow_step4' => '4. Follow progress live in the deploy log; MECM automatically picks up registered VMs for OS installation.',
    'unsaved_heading' => 'Unsaved changes',
    'unsaved_p1' => 'Mission settings and the VM editor show whether the current form differs from the last confirmed server state. Adding or removing a network interface or disk counts as a change as well.',
    'unsaved_p2' => 'When you follow a link, use Browser Back, reload or close the tab with unsaved changes, the portal warns before leaving. Confirming the warning discards only the changes in the browser; cancelling keeps the editor and its values open.',
    'unsaved_p3' => 'A validation or concurrent-editing error does not make the form saved. Password and file contents are never copied into the comparison state. With JavaScript disabled, the normal server-rendered forms continue to work, but the browser cannot provide this early warning.',
    'copy_heading' => 'Copy displayed values',
    'copy_p1' => 'Copy buttons are available beside displayed VM names, Windows hostnames, active rollout names, configured IP addresses, MAC addresses and job IDs. For an IP or MAC, the button names its network adapter.',
    'copy_p2' => 'In an editor, clicking copies the field\'s current content. Empty values and an IP field disabled by DHCP have no copy action. “Configured IP address" means the desired value in the portal, not an observed address of the running system.',
    'copy_p3' => 'On an HTTP portal or when browser permission is denied, the clipboard may be unavailable. The button then reports the failure without claiming success; the displayed value remains selectable for manual copying.',
];

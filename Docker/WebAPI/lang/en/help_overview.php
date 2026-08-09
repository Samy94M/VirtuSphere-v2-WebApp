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
];

<?php

declare(strict_types=1);

/**
 * Owner registry for the deploy-mode and preflight command layer.
 *
 * Static contracts must walk this list instead of one historic source file:
 * lib/ansible_command.php is a facade, while its functions live in domain
 * modules. Paths are relative to Docker/WebAPI and are kept in load order.
 * The facade comes first because it is the only public require path.
 */
const VIRTUSPHERE_ANSIBLE_COMMAND_MODULES = [
    'lib/ansible_command.php',
    'lib/ansible_command_shell.php',
    'lib/ansible_command_modes.php',
    'lib/ansible_command_preflight.php',
];

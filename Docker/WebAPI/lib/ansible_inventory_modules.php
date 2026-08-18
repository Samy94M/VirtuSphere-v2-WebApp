<?php

declare(strict_types=1);

/**
 * Owner registry for the Ansible inventory transport/parsing layer.
 *
 * Static contracts must walk this list instead of one historic source file:
 * lib/ansible_inventory.php is a facade, while its functions live in domain
 * modules. Paths are relative to Docker/WebAPI and are kept in load order.
 * The facade comes first because it is the only public require path.
 */
const VIRTUSPHERE_ANSIBLE_INVENTORY_MODULES = [
    'lib/ansible_inventory.php',
    'lib/ansible_inventory_artifacts.php',
    'lib/ansible_inventory_parse.php',
    'lib/ansible_inventory_datastore.php',
    'lib/ansible_inventory_capability.php',
];

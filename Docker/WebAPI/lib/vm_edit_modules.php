<?php

declare(strict_types=1);

/**
 * Owner registry for the VM editor page and helper surface.
 *
 * Page scanners concatenate this list: the portal file is a request/layout
 * shell, while action, renderer, repeat-row and diagnostics owners live in lib.
 */
const VIRTUSPHERE_VM_EDIT_MODULES = [
    'portal/vm_edit.php',
    'lib/vm_edit_page.php',
    'lib/vm_edit_panels.php',
    'lib/vm_edit_form.php',
    'lib/vm_edit_values.php',
    'lib/vm_edit_rows.php',
    'lib/vm_edit_status.php',
];

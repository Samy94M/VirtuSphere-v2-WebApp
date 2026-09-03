<?php

declare(strict_types=1);

require_once __DIR__ . '/constants.php';
require_once __DIR__ . '/defaults.php';
require_once __DIR__ . '/inventory_field.php';
require_once __DIR__ . '/forms.php';
// vm_parse_interfaces() rejects an empty interface list with a field error.
require_once __DIR__ . '/validate.php';

/** Public compatibility facade for VM-editor form helpers. */
require_once __DIR__ . '/vm_edit_values.php';
require_once __DIR__ . '/vm_edit_rows.php';
require_once __DIR__ . '/vm_edit_status.php';
require_once __DIR__ . '/vm_edit_names.php';

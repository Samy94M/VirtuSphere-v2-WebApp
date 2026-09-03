<?php

declare(strict_types=1);

/** Mission import analysis and commit helpers; loaded by mission_transfer.php. */

/**
 * The write refused a report that is blocked.
 *
 * Expected by construction: the confirm re-runs the whole analysis against the
 * name actually typed, and a conflict created between preview and confirm is
 * meant to land here. Typed so the portal can keep the hand-off alive and show
 * the localized sentence instead of treating a normal refusal as a fault worth
 * an error reference.
 */
final class MissionTransferBlockedException extends RuntimeException
{
}

/**
 * Resolves a package reference (name + optional version) to an id, or 0.
 */
function mission_transfer_resolve_package_id(mysqli $db, string $name, string $version): int
{
    if ($name === '') {
        return 0;
    }

    return (int) repo_scalar(
        $db,
        'SELECT id FROM deploy_packages WHERE package_name = ? AND (? = "" OR package_version = ?) LIMIT 1',
        'sss',
        [$name, $version, $version]
    );
}

/**
 * Dry-run analysis and (when $dryRun is false) commit of a mission import.
 *
 * The report lists counts, resolved vs missing package references, missing
 * VLANs, colliding VM names and every field the portal's own validators reject,
 * so the confirm step can show all of it before writing.
 *
 * Everything reads ONE canonical document (mission_transfer_document_analyze()):
 * the counts, the VLAN collection, the field validation and the write all see
 * the same projected values, so a count in the preview is what the transaction
 * writes and a field the transfer format discards can neither block nor land in
 * the database. Nothing casts a raw container any more.
 *
 * A problem is REPORTED, never thrown, whenever the operator can act on it in
 * the confirm form or by correcting the file. That includes the mission name:
 * blank, spaced, over-long and template-prefixed names set name_invalid instead
 * of throwing, exactly like the long-standing name_conflict, because all four
 * are fixed by retyping the name in the same form. Only a document that cannot
 * be read at all (wrong format version, malformed structure) throws, and it
 * throws MissionTransferDocumentException with a localized message.
 *
 * Everything except a missing package sets report['blocked'] = true, the one
 * predicate the write refuses on; missing packages are a warning and are skipped
 * on write. report['blocked_in_file'] is the subset the confirm form cannot fix
 * and is what disables its button, so a name problem never locks the operator
 * out of the field that fixes it.
 *
 * @param array<string, mixed> $payload Parsed JSON document.
 * @return array<string, mixed> Import report.
 */
function mission_import(mysqli $db, array $payload, string $newName, bool $dryRun, ?int $userId = null): array
{
    $document = mission_transfer_document_analyze($payload);
    if ($document['document_error'] !== '') {
        throw new MissionTransferDocumentException($document['document_error']);
    }

    $newName = trim($newName);

    $report = [
        'format_version' => $document['format_version'],
        'mission_name' => $newName,
        // Validated display metadata: '' when the file carries no readable date,
        // so the preview omits the row instead of printing file content under a
        // timestamp heading.
        'exported_at' => $document['exported_at'],
        'name_conflict' => false,
        'name_invalid' => false,
        'name_invalid_message' => '',
        'counts' => $document['counts'],
        'resolved_packages' => [],
        'missing_packages' => [],
        'missing_vlans' => [],
        'vm_name_conflicts' => [],
        'vm_name_duplicates' => [],
        'vm_hostname_duplicates' => [],
        // The document's own shape findings are field errors like any other, and
        // they land in the same two report lists the panel already renders.
        'mission_field_errors' => $document['mission_shape_errors'],
        'vm_field_errors' => $document['vm_shape_errors'],
        'mac_note' => true,
        'blocked' => false,
        'blocked_in_file' => false,
        'imported' => false,
        'mission_id' => null,
    ];

    // Mission name: required, no spaces, unique. All three problems are
    // reported, never thrown - exactly like name_conflict, they are fixable by
    // retyping the name in the same confirm form, not file-structure errors that
    // need a re-upload. No return here: the computation below still runs even
    // when the name is bad, so the preview stays maximally informative (VM
    // counts, VLANs, conflicts) instead of going blank.
    if ($newName === '' || preg_match('/\s/', $newName) === 1 || mb_strlen($newName) > 255) {
        $report['name_invalid'] = true;
        $report['name_invalid_message'] = validator_text('validate.mission_name_invalid', 'Enter a valid mission name (no spaces, max 255 characters).');
    } elseif (mission_name_is_template($newName)) {
        $report['name_invalid'] = true;
        $report['name_invalid_message'] = validator_text('validate.mission_import_no_template', 'Imported missions must not start with the template prefix.');
    }
    if ($report['name_invalid']) {
        $report['blocked'] = true;
    }
    if (repo_mission_name_exists($db, $newName)) {
        $report['name_conflict'] = true;
        $report['blocked'] = true;
    }

    // Collect referenced VLANs (mission WDS + per-interface) and check presence.
    // Keyed by exact portgroup identity. Case-only variants are separate ESXi
    // objects and therefore separate findings.
    //
    // The value is the spelling shown to the operator, and it has to be the
    // FIRST one in the file: a plain assignment in the per-interface loop below
    // let the LAST VM decide, so the finding named a spelling the operator would
    // search for in vain at the top of their export. The mission's own VLAN is
    // the first reference by construction and simply assigns.
    $vlanRefs = [];
    $missionVlan = trim((string) ($document['mission']['wds_vlan'] ?? ''));
    if ($missionVlan !== '') {
        $vlanRefs[$missionVlan] = $missionVlan;
    }

    // Field-level validation on the canonical mission values: EXACTLY the value
    // set the real write validates further down, so the dry run cannot report a
    // problem the write would never hit, nor miss one it would.
    //
    // The projection is load-bearing: REPO_MISSION_COPYABLE_COLUMNS excludes
    // mission_name and mission_status on purpose, and the real write sets both
    // itself - mission_status is ALWAYS overwritten with
    // VIRTUSPHERE_MISSION_STATUS_DEFAULT, so the value in the file is never
    // written. Validating the raw file block instead would fail an export whose
    // mission_status is empty, blocking a preview over a field the import
    // discards anyway. requireName=false because name validity is reported
    // separately above.
    try {
        repo_validate_mission_values($db, $document['mission'], 0, false, false);
    } catch (ValidationException $fieldException) {
        foreach ($fieldException->errors() as $fieldMessage) {
            $report['mission_field_errors'][] = $fieldMessage;
        }
    }

    $seenVmNames = [];
    $seenHostnames = [];
    $reportedConflicts = [];
    foreach ($document['vms'] as $vm) {
        $vmName = $vm['vm_name'];
        // Disambiguates a field-error entry when $vmName is blank or repeated
        // within this same file: the position is the only stable handle in both
        // cases, since two duplicate-named VMs cannot be told apart by name.
        $vmLabel = $vm['label'];
        $nameKey = $vmName !== '' ? mission_transfer_vm_name_key($vmName) : '';
        $isDuplicateInFile = false;
        if ($vmName !== '') {
            if (isset($seenVmNames[$nameKey])) {
                $report['vm_name_duplicates'][] = $vmName;
                $isDuplicateInFile = true;
            } else {
                $seenVmNames[$nameKey] = true;
            }
        }
        if ($isDuplicateInFile) {
            $vmLabel .= ' #' . $vm['position'];
        }

        // Two VMs in one file asking for the same Windows name (Etappe 14D).
        // The claim table catches it at write time, but only for the SECOND VM
        // and only after the first one is already inserted, so the whole import
        // rolls back behind a preview that reported nothing. The effective value
        // comes from repo_vm_hostname_input(), the same derivation the write
        // uses, because a preview with its own idea of the fallback is how a dry
        // run comes back clean for an import that cannot succeed.
        //
        // Only a rollout-valid value is compared: an invalid one takes no claim,
        // so two of them collide with nothing.
        $hostnameInput = repo_vm_hostname_input($vm['fields'], $vmName);
        if (mecm_hostname_is_rollout_valid($hostnameInput)) {
            $hostnameKey = mecm_hostname_key($hostnameInput);
            if (isset($seenHostnames[$hostnameKey])) {
                $report['vm_hostname_duplicates'][] = $hostnameInput;
            } else {
                $seenHostnames[$hostnameKey] = true;
            }
        }

        $globalConflictVmNames = [];
        if ($vmName !== '') {
            $conflict = repo_vm_name_conflict_global($db, $vmName);
            if ($conflict !== null) {
                $globalConflictVmNames[$vmName] = true;
                // One entry per name, by the same key the duplicate check uses:
                // a name repeated inside the file collides with the SAME foreign
                // mission each time, and listing that link twice reads as two
                // separate problems. The repetition is reported once, as a
                // duplicate, with its positions.
                if (!isset($reportedConflicts[$nameKey])) {
                    $reportedConflicts[$nameKey] = true;
                    $report['vm_name_conflicts'][] = [
                        'vm_name' => $vmName,
                        'mission_name' => (string) $conflict['mission_name'],
                        'mission_id' => (int) $conflict['mission_id'],
                    ];
                }
            }
        }

        // Field-level validation on the canonical lists. repo_validate_interfaces()
        // and repo_validate_disks() are stateless. repo_validate_vm_payload() is
        // called with mission id 0 as a "mission does not exist yet" sentinel: its
        // own scoping queries already treat a nonexistent id as "no match" / "not
        // a template", which is the correct outcome here. Its trailing global
        // name-conflict re-check duplicates the check a few lines above for a VM
        // whose ONLY problem is that exact conflict; the $isSoleRedundantConflict
        // guard drops only that one duplicate message, any other combination (a
        // bad vm_name charset together with a conflict, or a conflict not already
        // caught above) stays visible.
        foreach (mission_import_list_field_errors(
            static fn (array $rows): array => repo_validate_interfaces($rows),
            'interfaces',
            $vm['interfaces']
        ) as $fieldMessage) {
            $report['vm_field_errors'][] = $vmLabel . ': ' . $fieldMessage;
        }
        foreach (vm_network_issues_for_interfaces($vm['interfaces'], 0, 0, $vmName) as $networkIssue) {
            $message = (string) $networkIssue['code'] === VIRTUSPHERE_VM_NETWORK_EMPTY
                ? validator_text('validate.interface_vlan_required', 'Every stored network interface requires a VLAN.')
                : validator_text(
                    'validate.interface_vlan_unique',
                    'Each network interface of a VM requires a different VLAN.',
                    ['vlan' => (string) $networkIssue['vlan']]
                );
            $report['vm_field_errors'][] = $vmLabel . ': ' . $message;
        }
        foreach (mission_import_list_field_errors(
            static fn (array $rows): array => repo_validate_disks($rows),
            'disks',
            $vm['disks']
        ) as $fieldMessage) {
            $report['vm_field_errors'][] = $vmLabel . ': ' . $fieldMessage;
        }
        try {
            repo_validate_vm_payload($db, 0, $vm['fields'], 0);
        } catch (ValidationException $fieldException) {
            $vmFieldErrors = $fieldException->errors();
            $isSoleRedundantConflict = count($vmFieldErrors) === 1
                && array_key_exists('vm_name', $vmFieldErrors)
                && isset($globalConflictVmNames[$vmName]);
            if (!$isSoleRedundantConflict) {
                foreach ($vmFieldErrors as $fieldMessage) {
                    $report['vm_field_errors'][] = $vmLabel . ': ' . $fieldMessage;
                }
            }
        }

        foreach ($vm['interfaces'] as $interface) {
            $ifVlan = trim($interface['vlan']);
            if ($ifVlan !== '') {
                $vlanRefs[$ifVlan] ??= $ifVlan;
            }
        }

        foreach ($vm['packages'] as $package) {
            if (mission_transfer_resolve_package_id($db, $package['name'], $package['version']) > 0) {
                $report['resolved_packages'][$package['name']] = true;
            } else {
                $report['missing_packages'][$package['name']] = true;
            }
        }
    }

    // Iterates the exact reference map: case-only variants are separate findings,
    // and the value preserves the first spelling from the uploaded document.
    foreach ($vlanRefs as $vlanName) {
        if (!repo_vlan_name_exists($db, $vlanName)) {
            $report['missing_vlans'][] = $vlanName;
        }
    }

    $report['resolved_packages'] = array_keys($report['resolved_packages']);
    $report['missing_packages'] = array_keys($report['missing_packages']);

    // Two flags, because the confirm step has two different answers to give.
    //
    // blocked_in_file is every finding that lives in the uploaded document, and
    // nothing in the confirm form can change it: the operator has to correct the
    // export and upload it again, so the button is disabled. A NAME problem is
    // the opposite - the field that fixes it sits right under the message, the
    // confirm re-runs this whole analysis against the name actually typed, and
    // disabling the button there would leave the operator reading an instruction
    // they cannot carry out. name_invalid and name_conflict therefore set blocked
    // at their own place above and are deliberately absent here.
    //
    // blocked stays the single predicate the WRITE refuses on; it is a superset.
    $report['blocked_in_file'] = $report['missing_vlans'] !== [] || $report['vm_name_conflicts'] !== []
        || $report['vm_name_duplicates'] !== [] || $report['vm_hostname_duplicates'] !== []
        || $report['mission_field_errors'] !== [] || $report['vm_field_errors'] !== [];
    if ($report['blocked_in_file']) {
        $report['blocked'] = true;
    }

    if ($dryRun) {
        return $report;
    }

    if ($report['blocked']) {
        // Belt-and-suspenders: the page disables confirm when blocked, but never
        // rely on the client to enforce it. Localized, because the confirm POST
        // renders this sentence in the portal.
        throw new MissionTransferBlockedException(validator_text('missions.import_err_blocked', 'The import is blocked. Please resolve the reported issues first.'));
    }

    return repo_transaction($db, static function () use ($db, $newName, $document, $userId, $report): array {
        // The canonical mission values omit an autostart key the file does not
        // carry, so a v1 export written before this feature lands on the column
        // defaults instead of pushing '' into an INT NOT NULL column.
        $missionValues = [
            'mission_name' => $newName,
            'mission_status' => VIRTUSPHERE_MISSION_STATUS_DEFAULT,
        ] + $document['mission'];
        $missionValues = repo_validate_mission_values($db, $missionValues, 0, true, false);
        // The mission row is created here, by the importer - a mission_creator in
        // the transfer file is untrusted external data and is never copied. The VM
        // rows below keep their own vm_creator, which is part of the exported spec.
        $missionValues['mission_creator'] = repo_creator_name($db, $userId);
        $missionId = repo_insert_from_values($db, 'deploy_missions', $missionValues);

        foreach ($document['vms'] as $vm) {
            // Untrusted external data: full validation (defaults fill gaps,
            // NetBIOS + global-uniqueness rules enforced). Same projected value
            // set the dry run validated.
            $values = repo_validate_vm_payload($db, $missionId, $vm['fields'], 0);
            $values['mission_id'] = $missionId;
            $values['vm_status'] = VIRTUSPHERE_STATUS_REGISTERED;
            $values['lifecycle_state'] = VIRTUSPHERE_LIFECYCLE_READY;
            $values['mecm_sync_state'] = VIRTUSPHERE_MECM_SYNC_NOT_READY;
            $values['updated'] = 0;
            $vmId = repo_insert_from_values($db, 'deploy_vms', $values);

            // The canonical interface rows carry no MAC field at all (the transfer
            // projection has none), and false means "do not preserve" on top.
            repo_replace_interfaces($db, $vmId, $vm['interfaces'], false);
            repo_replace_disks($db, $vmId, $vm['disks']);

            // Map the canonical references onto the catalog columns; unknown ones
            // are silently skipped by repo_replace_packages (reported as missing).
            $packages = [];
            foreach ($vm['packages'] as $package) {
                $row = [];
                foreach (VIRTUSPHERE_MISSION_TRANSFER_PACKAGE_FIELDS as $field => $column) {
                    $row[$column] = $package[$field];
                }
                $packages[] = $row;
            }
            repo_replace_packages($db, $vmId, $packages);
            repo_record_vm_status_event($db, $vmId, VIRTUSPHERE_LIFECYCLE_READY, VIRTUSPHERE_MECM_SYNC_NOT_READY, VIRTUSPHERE_STATUS_REGISTERED, 'imported from file', $userId);
        }

        $report['imported'] = true;
        $report['mission_id'] = $missionId;

        return $report;
    });
}

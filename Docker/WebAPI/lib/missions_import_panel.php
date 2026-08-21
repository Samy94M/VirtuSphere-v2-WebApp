<?php

declare(strict_types=1);

/**
 * The two panels of the mission import step (ADR-0021), split out of
 * portal/missions.php so the page stays inside the ADR-0006 line budget.
 *
 * Both are renderers only: the upload POST, the session hand-off and the
 * dry run itself stay on the page. __t()/h(), can() and the layout helpers come
 * from the portal bootstrap the page has already loaded.
 */

/**
 * The dry-run report as the confirm step: counts, every finding, and the name
 * field the operator fixes a name problem in.
 *
 * @param array<string, mixed> $importPreview token, suggested_name, exported_at, report
 * @param array<string, mixed> $user
 */
function missions_render_import_preview(array $importPreview, array $user): void
{
    $report = $importPreview['report'];
    // Both predicates come from the report, never re-derived here: the copy this
    // page used to keep had already drifted off the write path.
    //
    // blocked_in_file disables the button, because nothing in this form can fix a
    // finding that lives in the uploaded document. A name problem must NOT
    // disable it: the field that fixes it is in this very form, and the confirm
    // re-runs the analysis against the name actually typed.
    $blockedInFile = (bool) $report['blocked_in_file'];
    // The green line answers "is anything at all wrong", so it reads the full
    // predicate plus the one warning that does not block.
    $anyFinding = (bool) $report['blocked'] || $report['missing_packages'] !== [];
    // The subset of file findings that has no link to a page that fixes it, and
    // therefore carries the "correct the file and upload again" sentence.
    $fileErrors = $report['vm_name_duplicates'] !== [] || $report['mission_field_errors'] !== [] || $report['vm_field_errors'] !== [];
    ?>
    <section class="panel">
        <h2><?php echo h(__t('missions.import_preview_heading')); ?></h2>
        <p class="muted"><?php echo h(__t('missions.import_mac_note')); ?></p>
        <p class="muted"><?php echo h(__t('missions.import_ttl_hint', ['minutes' => intdiv(VIRTUSPHERE_MISSION_IMPORT_TTL_SECONDS, 60)])); ?></p>
        <div class="table-wrap" tabindex="0"><table>
            <tbody>
                <tr><th><?php echo h(__t('common.vms')); ?></th><td><?php echo h((string) $report['counts']['vms']); ?></td></tr>
                <tr><th><?php echo h(__t('missions.import_count_interfaces')); ?></th><td><?php echo h((string) $report['counts']['interfaces']); ?></td></tr>
                <tr><th><?php echo h(__t('missions.import_count_disks')); ?></th><td><?php echo h((string) $report['counts']['disks']); ?></td></tr>
                <tr><th><?php echo h(__t('missions.import_count_packages')); ?></th><td><?php echo h((string) $report['counts']['packages']); ?></td></tr>
                <?php // Answers "is this the file I meant", which no count can. ?>
                <?php if ($importPreview['exported_at'] !== '') { ?><tr><th><?php echo h(__t('missions.import_exported_at')); ?></th><td><?php echo h(portal_format_timestamp($importPreview['exported_at'])); ?></td></tr><?php } ?>
            </tbody>
        </table></div>

        <?php // Both catalog findings tell the operator to create something; the
              // catalog is a different page and a different permission, so the
              // link is gated like the page that owns the fix. ?>
        <?php if ($report['missing_vlans'] !== []) { ?>
            <div class="alert alert-error"><strong><?php echo h(__t('missions.import_missing_vlans')); ?></strong> <?php echo h(implode(', ', $report['missing_vlans'])); ?><?php if (can('catalog.write', $user)) { ?> <a href="vlans.php"><?php echo h(__t('missions.import_vlans_link')); ?></a><?php } ?></div>
        <?php } ?>
        <?php // A conflicting name is only actionable once the operator can see
              // WHICH mission holds it, so each entry links to that mission. ?>
        <?php if ($report['vm_name_conflicts'] !== []) { ?>
            <div class="alert alert-error"><strong><?php echo h(__t('missions.import_vm_conflicts')); ?></strong>
                <ul><?php foreach ($report['vm_name_conflicts'] as $conflictEntry) { ?>
                    <li><?php echo h($conflictEntry['vm_name']); ?> (<a href="mission_details.php?id=<?php echo h((string) $conflictEntry['mission_id']); ?>"><?php echo h($conflictEntry['mission_name']); ?></a>)</li>
                <?php } ?></ul>
            </div>
        <?php } ?>
        <?php if ($report['vm_name_duplicates'] !== []) { ?>
            <div class="alert alert-error"><strong><?php echo h(__t('missions.import_vm_name_duplicates')); ?></strong> <?php echo h(implode(', ', $report['vm_name_duplicates'])); ?></div>
        <?php } ?>
        <?php if ($report['mission_field_errors'] !== []) { ?>
            <div class="alert alert-error"><strong><?php echo h(__t('missions.import_mission_field_errors')); ?></strong>
                <ul><?php foreach ($report['mission_field_errors'] as $fieldError) { ?><li><?php echo h($fieldError); ?></li><?php } ?></ul>
            </div>
        <?php } ?>
        <?php if ($report['vm_field_errors'] !== []) { ?>
            <div class="alert alert-error"><strong><?php echo h(__t('missions.import_vm_field_errors')); ?></strong>
                <ul><?php foreach ($report['vm_field_errors'] as $fieldError) { ?><li><?php echo h($fieldError); ?></li><?php } ?></ul>
            </div>
        <?php } ?>
        <?php if ($fileErrors) { ?>
            <p class="muted"><?php echo h(__t('missions.import_field_errors_hint')); ?></p>
        <?php } ?>
        <?php if ($report['missing_packages'] !== []) { ?>
            <div class="alert alert-warning"><strong><?php echo h(__t('missions.import_missing_packages')); ?></strong> <?php echo h(implode(', ', $report['missing_packages'])); ?><?php if (can('catalog.write', $user)) { ?> <a href="packages.php"><?php echo h(__t('missions.import_packages_link')); ?></a><?php } ?></div>
        <?php } ?>
        <?php // Said only when there is genuinely nothing to report: a preview
              // that lists no finding otherwise reads as one that found nothing
              // out because it never ran. ?>
        <?php if (!$anyFinding) { ?>
            <div class="alert alert-success"><?php echo h(__t('missions.import_no_issues')); ?></div>
        <?php } ?>
        <p class="muted"><?php echo h(__t('missions.import_status_reset_note')); ?></p>

        <?php // One submit button and the action in a hidden input, per the
              // core.js busy-button contract: the busy handler disables the
              // button, which would drop a button-borne name/value from the POST.
              // Cancel therefore stays a link and does NOT delete the hand-off:
              // it leaves the view, the state ages out with the preview TTL (or
              // is replaced by the next upload), and Back within that window
              // shows this same preview again. ?>
        <form class="form-grid" method="post" action="missions.php?type=missions">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="action" value="import_confirm">
            <input type="hidden" name="import_token" value="<?php echo h($importPreview['token']); ?>">
            <label><?php echo h(__t('missions.import_new_name_label')); ?>
                <input name="mission_name" maxlength="255" pattern="\S+" title="<?php echo h(__t('missions.name_no_spaces_title')); ?>" value="<?php echo h((string) $importPreview['suggested_name']); ?>" required>
                <?php // Independent flags: a re-imported, already existing template
                      // is both invalid and taken, and both errors stack here. ?>
                <?php if ($report['name_invalid']) { ?><span class="field-error"><?php echo h($report['name_invalid_message']); ?></span><?php } ?>
                <?php if ($report['name_conflict']) { ?><span class="field-error"><?php echo h(__t('missions.import_name_conflict')); ?></span><?php } ?>
            </label>
            <div class="actions">
                <button class="button" type="submit" data-busy-label="<?php echo h(__t('missions.import_confirm_busy')); ?>" <?php echo $blockedInFile ? 'disabled' : ''; ?>><?php echo h(__t('missions.import_confirm_btn')); ?></button>
                <a class="button button-secondary" href="missions.php?type=missions"><?php echo h(__t('common.cancel')); ?></a>
            </div>
            <?php if ($blockedInFile) { ?><p class="muted"><?php echo h(__t('missions.import_blocked_note')); ?></p><?php } ?>
        </form>
    </section>
    <?php
}

/** The upload step: the file field that produces the preview above. */
function missions_render_import_upload(): void
{
    ?>
    <section class="panel">
        <h2><?php echo h(__t('missions.import_heading')); ?></h2>
        <?php // The size limit is named here rather than only in the rejection,
              // and it is interpolated from the constant, never spelled out. ?>
        <p class="muted"><?php echo h(__t('missions.import_hint', ['max' => virtusphere_human_bytes(VIRTUSPHERE_MISSION_IMPORT_MAX_BYTES)])); ?></p>
        <form class="form-grid" method="post" action="missions.php?type=missions" enctype="multipart/form-data">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="action" value="import_preview">
            <?php // MAX_FILE_SIZE must precede the file input, or PHP ignores it.
                  // It is the early-detection hint only (PHP answers an oversize
                  // upload with UPLOAD_ERR_FORM_SIZE instead of buffering it), and
                  // never a boundary: a client can change or drop it, so the
                  // server compares $_FILES['size'] against the same constant. ?>
            <input type="hidden" name="MAX_FILE_SIZE" value="<?php echo h((string) VIRTUSPHERE_MISSION_IMPORT_MAX_BYTES); ?>">
            <label><?php echo h(__t('missions.import_file_label')); ?>
                <input type="file" name="import_file" accept="application/json,.json" required>
            </label>
            <div class="actions"><button class="button button-secondary" type="submit" data-busy-label="<?php echo h(__t('missions.import_preview_busy')); ?>"><?php echo h(__t('missions.import_preview_btn')); ?></button></div>
        </form>
    </section>
    <?php
}

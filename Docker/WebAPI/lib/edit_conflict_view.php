<?php

declare(strict_types=1);

/**
 * Comparison panel of the mission and VM editors after a refused save
 * (QL-01/QL-03 B). It renders what lib/edit_conflict.php compared and offers
 * the continue step, which posts the editor form with the shown version and
 * writes nothing (F5). The panel sits above the form and reaches it through
 * the form attribute, so the form keeps its own layout and its save buttons.
 */

require_once __DIR__ . '/edit_conflict.php';

/**
 * @param array{mode:string, version:string, rows:list<array{label:string, submitted:string, current:string, rows:bool}>} $conflict
 */
function edit_conflict_render(array $conflict, string $formId): void
{
    $isConflict = $conflict['mode'] === VIRTUSPHERE_EDIT_CONFLICT_MODE_CONFLICT;
    $headingId = $formId . '-conflict-heading';
    $hasChildRows = in_array(true, array_column($conflict['rows'], 'rows'), true);
    ?>
    <section class="panel stack" id="<?php echo h($formId); ?>-conflict" aria-labelledby="<?php echo h($headingId); ?>" data-edit-conflict="<?php echo h($conflict['mode']); ?>">
        <h2 id="<?php echo h($headingId); ?>"><?php echo h(__t($isConflict ? 'edit_conflict.heading' : 'edit_conflict.heading_continued')); ?></h2>
        <?php if ($conflict['rows'] === []) { ?>
            <p><?php echo h(__t($isConflict ? 'edit_conflict.no_field_difference' : 'edit_conflict.no_field_difference_continued')); ?></p>
        <?php } else { ?>
            <p><?php echo h(__t($isConflict ? 'edit_conflict.intro' : 'edit_conflict.intro_continued')); ?></p>
            <div class="table-wrap" tabindex="0">
                <table class="edit-conflict-table">
                    <thead><tr><th><?php echo h(__t('edit_conflict.th_field')); ?></th><th><?php echo h(__t('edit_conflict.th_mine')); ?></th><th><?php echo h(__t('edit_conflict.th_current')); ?></th></tr></thead>
                    <tbody>
                    <?php foreach ($conflict['rows'] as $row) { ?>
                        <tr data-edit-conflict-row>
                            <td><?php echo h($row['label']); ?></td>
                            <td class="edit-conflict-value"><?php echo h($row['submitted']); ?></td>
                            <td class="edit-conflict-value"><?php echo h($row['current']); ?></td>
                        </tr>
                    <?php } ?>
                    </tbody>
                </table>
            </div>
            <?php if ($hasChildRows) { ?><p class="hint"><?php echo h(__t('edit_conflict.rows_hint')); ?></p><?php } ?>
        <?php } ?>
        <?php if ($isConflict) { ?>
            <input type="hidden" name="edit_conflict_version" value="<?php echo h($conflict['version']); ?>" form="<?php echo h($formId); ?>">
            <p class="hint"><?php echo h(__t('edit_conflict.continue_hint')); ?></p>
            <div class="actions">
                <button class="button button-secondary" type="submit" form="<?php echo h($formId); ?>" name="edit_conflict" value="<?php echo h(VIRTUSPHERE_EDIT_CONFLICT_CONTINUE); ?>" formnovalidate><?php echo h(__t('edit_conflict.continue')); ?></button>
            </div>
        <?php } ?>
    </section>
    <?php
}

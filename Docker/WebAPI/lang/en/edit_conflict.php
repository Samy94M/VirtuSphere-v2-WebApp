<?php

declare(strict_types=1);

/**
 * Comparison after a refused save in the mission and VM editors
 * (lib/edit_conflict_view.php, QL-01/QL-03 B).
 */
return [
    'heading' => 'What changed since you opened this page',
    'heading_continued' => 'Your input compared with the saved state',
    'intro' => 'Someone saved while you were editing. These fields differ between your input and what is saved now. Your input is still in the form.',
    'intro_continued' => 'Your input now builds on the saved state. These fields differ from it; saving stores the values from the "Your input" column. If someone saves again in the meantime, your save fails and shows a new comparison.',
    'no_field_difference' => 'Someone saved while you were editing. The values of this form still match your input.',
    'no_field_difference_continued' => 'Your input now builds on the saved state and matches it.',
    'th_field' => 'Field',
    'th_mine' => 'Your input',
    'th_current' => 'Saved now',
    'empty' => 'empty',
    'row_count' => 'Count: :count',
    'rows_hint' => 'For interfaces, disks and packages the comparison shows only the count. Check the individual rows in the form.',
    'continue' => 'Continue with my input',
    'continue_hint' => '"Continue with my input" saves nothing. You can then adjust your input; only the next save overwrites the state shown.',
];

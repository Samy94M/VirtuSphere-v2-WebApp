<?php

declare(strict_types=1);

require_once __DIR__ . '/log_filter.php';
require_once __DIR__ . '/repo/log.php';

/**
 * The filter form of logs.php.
 *
 * Split out of the page for the size budget (ADR-0006), but the split follows a
 * real seam: everything here renders ONE validated struct, and nothing here
 * decides anything. The page validates, the repository queries, this file
 * paints.
 *
 * Two shapes are deliberate. The everyday filters (text, IP, category, date
 * range) stay on the surface, and the diagnostic ones (correlation id, event
 * code, object, result) sit in a disclosure that opens by itself as soon as one
 * of them is set or rejected: they answer "what did this one request do" and
 * "who deleted this exact VM", which is not what the page is opened for most
 * days, and a form of ten controls makes the four that matter harder to find.
 *
 * The event codes and object types are shown as their raw identifiers on
 * purpose. They are the vocabulary of the audit registry, not prose: the same
 * string appears in the exported CSV, in a saved search and in this project's
 * own documentation, so translating it in the picker alone would give an
 * operator a word they cannot find anywhere else. What IS localized is the
 * grouping around them, so a code can be found without knowing the list.
 */

/** The form name for lib/forms.php ids; the page has exactly one form. */
const VIRTUSPHERE_LOG_FILTER_FORM = 'log_filter';

/**
 * Whether the diagnostic disclosure starts open: any advanced field carries a
 * value, or one of them was rejected and its message would otherwise be
 * rendered inside a closed element nobody sees.
 */
function logs_filter_advanced_is_open(array $filter): bool
{
    foreach (['correlation', 'event_code', 'object_type', 'object_id', 'result'] as $field) {
        if ((string) ($filter[$field] ?? '') !== '') {
            return true;
        }
    }
    foreach (['correlation', 'event', 'object_type', 'object_id', 'result'] as $field) {
        if (isset($filter['errors'][$field])) {
            return true;
        }
    }

    return false;
}

/** Localized message for one rejected field, or '' when it was accepted. */
function logs_filter_error(array $filter, string $field): string
{
    $key = $filter['errors'][$field] ?? '';

    return $key === '' ? '' : __t($key);
}

/** One text/date control with its label, error and sticky value. */
function logs_filter_field(string $name, string $label, string $value, string $error, string $type = 'text', string $placeholder = ''): string
{
    $attrs = form_control_attrs(VIRTUSPHERE_LOG_FILTER_FORM, $name, null, false, $error);
    $html = '<label>' . h($label)
        . '<input type="' . h($type) . '" name="' . h($name) . '" value="' . h($value) . '"'
        . ($placeholder !== '' ? ' placeholder="' . h($placeholder) . '"' : '')
        . $attrs . '>';

    return $html . form_error_html(VIRTUSPHERE_LOG_FILTER_FORM, $name, null, $error) . '</label>';
}

/**
 * One select with an "any" placeholder.
 *
 * @param array<string,string>|array<string,array<string,string>> $options
 *        value => label, or group label => (value => label) for an optgroup set
 */
function logs_filter_select(string $name, string $label, string $current, string $any, array $options, string $error = ''): string
{
    $attrs = form_control_attrs(VIRTUSPHERE_LOG_FILTER_FORM, $name, null, false, $error);
    $html = '<label>' . h($label) . '<select name="' . h($name) . '"' . $attrs . '>'
        . '<option value="">' . h($any) . '</option>';
    foreach ($options as $key => $option) {
        if (is_array($option)) {
            $html .= '<optgroup label="' . h((string) $key) . '">';
            foreach ($option as $value => $optionLabel) {
                $html .= logs_filter_option((string) $value, (string) $optionLabel, $current);
            }
            $html .= '</optgroup>';

            continue;
        }
        $html .= logs_filter_option((string) $key, (string) $option, $current);
    }

    return $html . '</select>' . form_error_html(VIRTUSPHERE_LOG_FILTER_FORM, $name, null, $error) . '</label>';
}

function logs_filter_option(string $value, string $label, string $current): string
{
    return '<option value="' . h($value) . '"' . ($current === $value ? ' selected' : '') . '>' . h($label) . '</option>';
}

/**
 * The event codes of this tab, grouped by the category they file under.
 *
 * The group is the localized category name the operator already knows from the
 * table's own badges, which is what makes a list of technical identifiers
 * navigable without translating each one.
 *
 * @return array<string,array<string,string>>
 */
function logs_filter_event_options(string $tab): array
{
    $registry = audit_event_registry();
    $groups = [];
    foreach (log_filter_event_codes_for_tab($tab) as $code) {
        $category = (string) ($registry[$code]['category'] ?? '');
        $group = $category !== '' ? log_category_label($category) : __t('logs.event_group_varies');
        $groups[$group][$code] = $code;
    }
    ksort($groups, SORT_STRING);

    return $groups;
}

/** Localized label for one audit result. */
function logs_filter_result_label(string $result): string
{
    return match ($result) {
        VIRTUSPHERE_AUDIT_RESULT_SUCCESS => __t('logs.result_success'),
        VIRTUSPHERE_AUDIT_RESULT_DENIED => __t('logs.result_denied'),
        VIRTUSPHERE_AUDIT_RESULT_WARNING => __t('logs.result_warning'),
        VIRTUSPHERE_AUDIT_RESULT_FAILURE => __t('logs.result_failure'),
        VIRTUSPHERE_AUDIT_RESULT_RECOVERED => __t('logs.result_recovered'),
        default => $result,
    };
}

/**
 * Renders the whole form.
 *
 * $resetUrl clears every filter but keeps the tab; $exportUrl is null when
 * there is nothing to export (no rows, or a filter the page refused to run).
 */
function logs_render_filter_form(array $filter, string $resetUrl, ?string $exportUrl): void
{
    $tab = (string) $filter['tab'];
    $categories = [];
    foreach (VIRTUSPHERE_LOG_TABS[$tab] as $category) {
        $categories[$category] = log_category_label($category);
    }
    $results = [];
    foreach (VIRTUSPHERE_AUDIT_RESULTS as $result) {
        $results[$result] = logs_filter_result_label($result);
    }
    $objectTypes = [];
    foreach (log_filter_object_types() as $objectType) {
        $objectTypes[$objectType] = $objectType;
    }
    ?>
    <form class="stack" method="get" action="logs.php">
        <input type="hidden" name="tab" value="<?php echo h($tab); ?>">
        <div class="form-grid">
            <?php
            // From/To adjacent: they are one control in two boxes, and the grid
            // is four wide, so any field between them puts the pair on two rows
            // at the width the page is normally read at.
            echo logs_filter_field('q', __t('logs.search'), (string) $filter['search'], '', 'text', __t('logs.search_placeholder'));
            echo logs_filter_field('ip', __t('logs.ip'), (string) $filter['ip'], '', 'text', __t('logs.ip_placeholder'));
            echo logs_filter_field('from', __t('logs.from'), (string) $filter['from'], logs_filter_error($filter, 'from'), 'date');
            echo logs_filter_field('to', __t('logs.to'), (string) $filter['to'], logs_filter_error($filter, 'to'), 'date');
            echo logs_filter_select('category', __t('logs.category'), (string) $filter['category'], __t('logs.category_all'), $categories);
            ?>
        </div>
        <?php // The dates are read in the display timezone while the rows are
              // stored in UTC, so the sentence says which day boundary applies;
              // an operator comparing against a UTC log elsewhere needs it. ?>
        <p class="hint"><?php echo h(__t('logs.date_range_hint', ['tz' => portal_timezone()])); ?></p>
        <details class="filter-advanced"<?php echo logs_filter_advanced_is_open($filter) ? ' open' : ''; ?>>
            <summary><?php echo h(__t('logs.advanced_heading')); ?></summary>
            <div class="form-grid">
                <?php
                echo logs_filter_field('correlation', __t('logs.correlation'), (string) $filter['correlation'], logs_filter_error($filter, 'correlation'), 'text', __t('logs.correlation_placeholder'));
                echo logs_filter_select('event', __t('logs.event_code'), (string) $filter['event_code'], __t('logs.event_any'), logs_filter_event_options($tab), logs_filter_error($filter, 'event'));
                echo logs_filter_select('object_type', __t('logs.object_type'), (string) $filter['object_type'], __t('logs.object_any'), $objectTypes, logs_filter_error($filter, 'object_type'));
                echo logs_filter_field('object_id', __t('logs.object_id'), (string) $filter['object_id'], logs_filter_error($filter, 'object_id'), 'text');
                echo logs_filter_select('result', __t('logs.result'), (string) $filter['result'], __t('logs.result_any'), $results, logs_filter_error($filter, 'result'));
                ?>
            </div>
            <p class="hint"><?php echo h(__t('logs.advanced_hint')); ?></p>
        </details>
        <div class="actions">
            <button class="button" type="submit"><?php echo h(__t('logs.apply')); ?></button>
            <a class="button button-secondary" href="<?php echo h($resetUrl); ?>"><?php echo h(__t('logs.reset')); ?></a>
            <?php if ($exportUrl !== null) { ?><a class="button button-secondary" href="<?php echo h($exportUrl); ?>"><?php echo h(__t('common.export_csv')); ?></a><?php } ?>
        </div>
    </form>
    <?php
}

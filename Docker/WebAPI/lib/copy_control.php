<?php

declare(strict_types=1);

require_once __DIR__ . '/lang.php';

/**
 * Shared copy accelerator for portal values (F04).
 *
 * The value itself always remains visible/selectable. Static displays carry
 * their displayed value; form controls carry only the source element id so the
 * browser reads the current input value rather than a stale server snapshot.
 */

function portal_copy_button(
    string $accessibleLabel,
    string $value = '',
    string $sourceId = '',
    bool $sourceMustBeEnabled = false,
    string $dynamicLabelTemplate = ''
): string {
    if ($sourceId === '' && $value === '') {
        return '';
    }

    $attrs = $sourceId !== ''
        ? ' data-copy-source="' . h($sourceId) . '"'
        : ' data-copy-value="' . h($value) . '"';
    if ($sourceMustBeEnabled) {
        $attrs .= ' data-copy-source-enabled';
    }
    if ($dynamicLabelTemplate !== '') {
        $attrs .= ' data-copy-label-template="' . h($dynamicLabelTemplate) . '"';
    }
    if ($sourceId !== '' && ($value === '' || $sourceMustBeEnabled)) {
        // JavaScript immediately resolves the enabled state. Hidden is the
        // safe server fallback for an empty value or a DHCP-controlled field.
        $attrs .= ' hidden';
    }

    return '<button type="button" class="button button-ghost copy-button"'
        . $attrs
        . ' aria-label="' . h($accessibleLabel) . '"'
        . ' data-copy-done="' . h(__t('common.copy_done')) . '"'
        . ' data-copy-failed="' . h(__t('common.copy_failed')) . '">'
        . h(__t('common.copy')) . '</button>'
        . '<span class="copy-status" role="status" data-copy-status hidden></span>';
}

/** A static value plus its copy accelerator; empty values get no action. */
function portal_copy_value(string $value, string $label, bool $code = false): string
{
    if ($value === '') {
        return '<span class="muted">&mdash;</span>';
    }

    $tag = $code ? 'code' : 'span';

    return '<span class="copy-control"><' . $tag . ' class="copy-value">' . h($value) . '</' . $tag . '>'
        . portal_copy_button(__t('common.copy_value', ['label' => $label]), $value)
        . '</span>';
}

/**
 * Copy accelerator beside a form field. The input remains the value owner.
 */
function portal_copy_input_button(
    string $sourceId,
    string $currentValue,
    string $label,
    bool $sourceMustBeEnabled = false,
    string $dynamicLabel = ''
): string {
    return portal_copy_button(
        __t('common.copy_value', ['label' => $label]),
        $currentValue,
        $sourceId,
        $sourceMustBeEnabled,
        $dynamicLabel === '' ? '' : __t('common.copy_value', ['label' => $dynamicLabel])
    );
}

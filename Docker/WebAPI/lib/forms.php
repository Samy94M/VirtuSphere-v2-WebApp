<?php

declare(strict_types=1);

/**
 * Sticky-form helpers: a POST handler stashes submitted values and per-field
 * validation errors in the session before redirecting, the next render
 * consumes the stash exactly once.
 */

const VIRTUSPHERE_FORM_SENSITIVE_FIELDS = ['_csrf', 'password', 'secret', 'pfx_password'];

function form_state(): array
{
    static $state = null;
    if ($state === null) {
        $state = $_SESSION['_form_state'] ?? [];
        unset($_SESSION['_form_state']);
        if (!is_array($state)) {
            $state = [];
        }
    }

    return $state;
}

/**
 * @param array<string, mixed> $old
 * @param array<string, string> $errors
 */
function form_remember(string $form, array $old, array $errors): void
{
    foreach (VIRTUSPHERE_FORM_SENSITIVE_FIELDS as $field) {
        unset($old[$field]);
    }

    $_SESSION['_form_state'][$form] = ['old' => $old, 'errors' => $errors];
}

/**
 * Whether a redirect stashed sticky state for this form. Pages with collapsed
 * row editors use this to reopen the editor whose submit failed validation.
 */
function form_has_state(string $form): bool
{
    return isset(form_state()[$form]);
}

/**
 * The whole remembered payload of a form. A page whose fields can come from
 * more than one source (the deploy queue form: a POST it answers directly, this
 * stash, or a query string) picks its source once and then reads every field
 * from it, instead of asking per field. Per-field precedence would let an
 * absent checkbox fall through to an older source that still carried it, and an
 * absent key is exactly how a checkbox says "off".
 *
 * @return array<string, mixed>
 */
function form_old_all(string $form): array
{
    $state = form_state();

    return is_array($state[$form]['old'] ?? null) ? $state[$form]['old'] : [];
}

function form_old(string $form, string $field, string $default = ''): string
{
    $state = form_state();
    if (!isset($state[$form]['old']) || !array_key_exists($field, $state[$form]['old'])) {
        return $default;
    }

    $value = $state[$form]['old'][$field];

    return is_scalar($value) ? (string) $value : $default;
}

/**
 * Sticky array field (a checkbox list). Returns the posted values as strings, so
 * a caller can test membership after a validation failure; [] when no state was
 * remembered or the field was not an array. Non-scalar members are dropped, as
 * form_old() does for a scalar field.
 *
 * @return string[]
 */
function form_old_array(string $form, string $field): array
{
    $state = form_state();
    if (!isset($state[$form]['old']) || !array_key_exists($field, $state[$form]['old'])) {
        return [];
    }

    $value = $state[$form]['old'][$field];
    if (!is_array($value)) {
        return [];
    }

    $out = [];
    foreach ($value as $item) {
        if (is_scalar($item)) {
            $out[] = (string) $item;
        }
    }

    return $out;
}

function form_error(string $form, string $field): string
{
    $state = form_state();

    return (string) ($state[$form]['errors'][$field] ?? '');
}

function form_error_html(string $form, string $field): string
{
    $error = form_error($form, $field);

    return $error === '' ? '' : '<span class="field-error">' . h($error) . '</span>';
}

function form_input_class(string $form, string $field): string
{
    return form_error($form, $field) === '' ? '' : ' class="is-invalid" aria-invalid="true"';
}

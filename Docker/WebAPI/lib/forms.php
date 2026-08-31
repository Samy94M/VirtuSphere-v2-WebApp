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

/** Normalize one trusted form/field/scope segment for a stable HTML id. */
function form_id_segment(string|int $value): string
{
    $raw = trim((string) $value);
    // The only non-runtime scope is the repeat-row template marker. forms.js
    // replaces it together with the matching name index before insertion.
    if ($raw === '__INDEX__') {
        return $raw;
    }

    $normalized = strtolower((string) preg_replace('/[^A-Za-z0-9_-]+/', '-', $raw));
    $normalized = trim($normalized, '-_');

    return $normalized !== '' ? $normalized : 'field';
}

function form_element_id(string $form, string $field, string|int|null $scope = null): string
{
    $segments = ['form', form_id_segment($form)];
    if ($scope !== null && (string) $scope !== '') {
        $segments[] = form_id_segment($scope);
    }
    $segments[] = form_id_segment($field);

    return implode('-', $segments);
}

function form_hint_id(string $form, string $field, string|int|null $scope = null): string
{
    return form_element_id($form, $field, $scope) . '-hint';
}

function form_error_id(string $form, string $field, string|int|null $scope = null): string
{
    return form_element_id($form, $field, $scope) . '-error';
}

/**
 * Complete attributes owned by the form contract: stable id, invalid state,
 * error class and the IDs that describe this control or control group.
 *
 * `$hints = true` selects the standard hint ID. An explicit list is used only
 * when one control has multiple real hints (for example start-wait's permanent
 * explanation plus its mode lock). `$error = null` reads sticky form state;
 * direct editors pass their ValidationException message (or an empty string).
 *
 * @param bool|list<string> $hints
 */
function form_control_attrs(
    string $form,
    string $field,
    string|int|null $scope = null,
    bool|array $hints = false,
    ?string $error = null
): string {
    $error = $error ?? form_error($form, $field);
    $hintIds = $hints === true ? [form_hint_id($form, $field, $scope)] : ($hints === false ? [] : $hints);
    $describedBy = array_values(array_unique(array_filter(
        array_map('trim', $hintIds),
        static fn (string $id): bool => $id !== ''
    )));
    if ($error !== '') {
        $describedBy[] = form_error_id($form, $field, $scope);
    }

    $attrs = ' id="' . h(form_element_id($form, $field, $scope)) . '"';
    if ($error !== '') {
        $attrs .= ' class="is-invalid" aria-invalid="true"';
    }
    if ($describedBy !== []) {
        $attrs .= ' aria-describedby="' . h(implode(' ', $describedBy)) . '"';
    }

    return $attrs;
}

function form_error_html(
    string $form,
    string $field,
    string|int|null $scope = null,
    ?string $error = null
): string {
    $error = $error ?? form_error($form, $field);

    return $error === ''
        ? ''
        : '<span class="field-error" id="' . h(form_error_id($form, $field, $scope)) . '">' . h($error) . '</span>';
}

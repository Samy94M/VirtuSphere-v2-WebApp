<?php

declare(strict_types=1);

/**
 * MECM console language of this environment (PR-R3M, i18n R15).
 *
 * The setting picks which term set (lib/mecm_terms/<language>.php) names the
 * MECM objects in portal texts; Lang fills the `{mecm:<name>}` placeholders.
 * Like the portal timezone it is display-only: it never drives auth, RBAC,
 * deploy decisions, status transitions, the machine API or stored texts, and it
 * is independent of the portal locale (an English portal next to a German
 * console shows the German console words).
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/constants.php';
require_once __DIR__ . '/lang.php';
require_once __DIR__ . '/repo/settings.php';

/**
 * Configured console language (cached per request). A missing row, an unknown
 * value or a database error falls back to Lang::MECM_CONSOLE_DEFAULT silently,
 * so a bad setting can never break a page.
 */
function mecm_console_language(): string
{
    static $language = null;
    if ($language !== null) {
        return $language;
    }

    $language = Lang::MECM_CONSOLE_DEFAULT;
    try {
        $value = repo_setting_value(db(), VIRTUSPHERE_SETTING_MECM_CONSOLE_LANGUAGE, Lang::MECM_CONSOLE_DEFAULT);
        if (mecm_console_language_is_valid($value)) {
            $language = $value;
        }
    } catch (Throwable) {
        // Keep the default; display must survive a DB hiccup.
    }

    return $language;
}

/** True for a language that has a term set in lib/mecm_terms/. */
function mecm_console_language_is_valid(string $language): bool
{
    return in_array($language, Lang::mecmConsoleLanguages(), true);
}

/**
 * Choices for the settings select: language => label in the portal locale.
 * The label key is settings.mecm_console_language_<language>; a term set
 * without one shows its key (R7) and fails LangMecmTermsTest.
 *
 * @return array<string, string>
 */
function mecm_console_language_choices(): array
{
    $choices = [];
    foreach (Lang::mecmConsoleLanguages() as $language) {
        $choices[$language] = __t('settings.mecm_console_language_' . $language);
    }

    return $choices;
}

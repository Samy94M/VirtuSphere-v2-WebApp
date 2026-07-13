# ADR-0014: Portal i18n and User-facing Error Messages

Date: 2026-07-06
Status: Accepted

## Context

The portal is moving from an English-first prototype to an admin tool that must be usable in German and English. Recent portal work also showed raw database errors in the UI, for example duplicate user-name constraint messages. AI agents need a small, stable pattern for adding user-facing text without drifting across pages, JavaScript and machine API contracts.

## Decision

Portal UI strings use `Lang` and `__t('module.key')`. The catalog lives under `Docker/WebAPI/lang/{de,en}`, German is the default locale, and the portal accepts `?lang=de|en|auto` once the language helper is installed. DE/EN key parity is checked by `scripts/lang-audit.php`.

Portal pages must not render raw SQL, PHP or infrastructure exceptions to users. POST handlers should map exceptions through `portal_error_message()` for flash and HTML output, while structured validation keeps per-field messages through `ValidationException` and the sticky-form helpers.

JavaScript must not carry hardcoded translatable fallback labels. If JavaScript needs portal labels, PHP renders them through a CSP-nonced JSON island and JavaScript reads the JSON.

Machine API, MECM, Ansible and legacy token wire contracts are not localized. Their field names, status strings and response semantics stay stable until an explicit migration or retirement ADR changes them.

## Consequences

New or changed portal text requires DE and EN catalog keys. Missing language parity is a local/CI warning target before it becomes a hard gate.

The portal can become bilingual without changing machine-facing integrations. Agents must distinguish portal UX text from API contracts before translating or renaming anything.

Existing ADRs remain historically stable. This ADR adds the i18n/error-message contract and is referenced by `AGENTS.md`, `GROK.md`, `CLAUDE.md` and the path-scoped `.claude/rules`.
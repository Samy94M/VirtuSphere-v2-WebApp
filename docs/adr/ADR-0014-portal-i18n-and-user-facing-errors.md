# ADR-0014: Portal i18n and User-facing Error Messages

Date: 2026-07-06
Status: Accepted

## Context

The portal is moving from an English-first prototype to an admin tool that must be usable in German and English. Recent portal work also showed raw database errors in the UI, for example duplicate user-name constraint messages. AI agents need a small, stable pattern for adding user-facing text without drifting across pages, JavaScript and machine API contracts.

## Decision

Portal UI strings use `Lang` and `__t('module.key')`. The catalog lives under `Docker/WebAPI/lang/{de,en}`, German is the default locale, and the portal accepts `?lang=de|en|auto` once the language helper is installed. DE/EN key parity is checked by `scripts/lang-audit.php`.

Portal pages must not render raw SQL, PHP or infrastructure exceptions to users. POST handlers should map exceptions through `portal_error_message()` for flash and HTML output, while structured validation keeps per-field messages through `ValidationException` and the sticky-form helpers.

JavaScript must not carry hardcoded translatable fallback labels. If JavaScript needs portal labels, PHP renders them through a CSP-nonced JSON island and JavaScript reads the JSON.

A message that states a prerequisite or an instruction carries the link that satisfies it. Naming the fix without the route is the same defect as not naming it, and the label names the destination as this portal names it, never the foreign system it configures. The link is gated on the *target's* permission while the sentence is not, so a user who cannot fix it still learns why; where a set of prerequisites also gates a control, the message list and the gate are one predicate, so a blocked control cannot exist without a message explaining it. Links into a tabbed page go through its builder (`settings_url()`, `log_category_url()`), because a link missing its tab opens a page that does not contain the field the message just named, silently.

Machine API, MECM, Ansible and legacy token wire contracts are not localized. Their field names, status strings and response semantics stay stable until an explicit migration or retirement ADR changes them.

## Consequences

New or changed portal text requires DE and EN catalog keys. Missing language parity is a local/CI warning target before it becomes a hard gate.

The portal can become bilingual without changing machine-facing integrations. Agents must distinguish portal UX text from API contracts before translating or renaming anything.

Existing ADRs remain historically stable. This ADR adds the i18n/error-message contract and is referenced by `AGENTS.md`, `GROK.md`, `CLAUDE.md` and the path-scoped `.claude/rules`.
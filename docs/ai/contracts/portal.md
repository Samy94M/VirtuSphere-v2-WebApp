# VirtuSphere portal contracts

Read the sections relevant to the current change. Paths below are relative to Docker/WebAPI unless they already name a repository directory. Forbidden patterns remain in GROK.md section 1; these are the constructive implementation contracts.

## A26 Portal-only state labels live in lib/portal_status_display.php (lifecycle, MECM) and lib/deploy

- Portal-only state labels live in `lib/portal_status_display.php` (lifecycle, MECM) and `lib/deploy_display.php` (job status, deploy modes, visible payload); the stored value stays technical everywhere it is persisted or transported, and an unknown value renders a neutral localized sentence rather than its raw token. `deploy_service_health_snapshot()` (`lib/deploy_service_health.php`) is the single source of the deploy service state for dashboard, deploy page, System status and the anonymous health endpoint; its three axes stay separate in every detail view and the claim gate lives inside the claim transaction.

## A27 Render display-only timestamps through portal_format_timestamp() in lib/layout.php (d.m.Y H:i:s

- Render display-only timestamps through `portal_format_timestamp()` in `lib/layout.php` (`d.m.Y H:i:s`); do not echo raw MySQL datetime strings in portal views. VM and mission editors round-trip the raw `edit_version` counter, independently of display timestamps; portal writes require it (`docs/operations/edit-concurrency.md`).

## A28 Solid buttons take their fill/text from the --btn-bg/--btn-fg theme tokens (base.css); do not h

- Solid buttons take their fill/text from the `--btn-bg`/`--btn-fg` theme tokens (`base.css`); do not hardcode `#fff` or an accent color on `.button`. Follow the ADR-0013 design baseline (fill hierarchy, `:focus-visible`, `min-width:0` overflow containment, collapsible mobile nav, system-font stack) for portal UI work.

## A29 A wrapping heading/action row's gap spaces only its own children; a following table, grid or ca

- A wrapping heading/action row's `gap` spaces only its own children; a following table, grid or card list still needs the shared inter-block gap from a parent stack or a reusable sibling/component rule. For new or changed responsive layout, exercise a viewport that forces the wrap and add Playwright geometry or screenshot coverage for the boundary (ADR-0013).

## A30 Every class the markup renders must have a rule in portal/assets/css, pinned by tests/Static/Cs

- Every class the markup renders must have a rule in `portal/assets/css`, pinned by `tests/Static/CssClassContractTest.php`. Renaming a block keeps it rendering, just unstyled, and nothing else notices: an `<ul class="ampel-legend">` shipped on two pages with browser-default bullets while its predecessor's rules sat unused a few hundred lines away. A dynamically built name (`'badge badge-' . $variant`, `phase-step-<state>`) is checked by its static prefix, so the guard needs no list of its members. A class that is only a JS or test hook goes in `UNSTYLED_HOOKS` with its reason; prefer a `data-` attribute, which is what every hook here already uses. The opposite direction (a rule nothing renders) stays a periodic cleanup, because a guard for it needs a hand-maintained exception list, which is the drift it would claim to prevent.

## A31 Every portal form action is either confirmed with data-confirm="<__t() question>" on its submit

- Every portal form action is either confirmed with `data-confirm="<__t() question>"` on its submit button, or declared in `SAFE_ACTIONS` (`tests/Static/PortalConfirmContractTest.php`) with the reason it cannot lose anything; a new action fails the build until classified. Add `.button-danger` when the action destroys data. That attribute is all a page adds: the shared `<dialog>` in `lib/layout_modals.php` (rendered by `layout_footer()`) and its `assets/core.js` handler render the prompt, pick the danger variant, validate the form, restore focus and replay the submit via `form.requestSubmit(trigger)` (ADR-0013). Confirm the destructive *branch* of a toggle and omit the attribute otherwise, never render it empty. Any new modal goes into `lib/layout_modals.php` on the `.modal`/`.modal-box` base instead of a second implementation, and inherits its alignment from the shared `.modal-msg`/`.modal-actions` rules rather than restating it (`tests/Static/ModalAxisContractTest.php`).

## A32 A control that reloads the page to re-render server-side data must carry the form it sits in, n

- A control that reloads the page to re-render server-side data must carry the form it sits in, not only the parameter it changed. The deploy mission select and the job filter both write `mission_id`, and carrying only that emptied every other field of the queue form, because each one re-rendered from its constant default. `deploy_form.js` builds the query from the live controls (never a field list, and via `form.elements`, since `FormData` drops the disabled-but-filled wait time), and `lib/deploy_form_state.php` picks **one** source per render (the POST it answers, the sticky stash, the query string): a per-field fallback would re-check a box the operator had just cleared, because an absent key is exactly how a checkbox says "off". Pinned by `tests/Static/DeployFormStateContractTest.php` and `deploy-form-state.spec.js`.

## A33 Portal control, hint and field-error IDs come from lib/forms.php: use form_control_attrs() with

- Portal control, hint and field-error IDs come from `lib/forms.php`: use `form_control_attrs()` with `form_hint_id()`/`form_error_id()` and render errors through `form_error_html()`. `aria-invalid` belongs on the failing control; a shared hint describes a `fieldset` or `role="group"`. Repeated rows pass a stable row scope, templates keep `__INDEX__` in name and ID until `forms.js` replaces both with one monotonic value, and dynamic hints update `aria-describedby` exactly with visibility. `FormAccessibilityContractTest` and the page-wide browser DOM check reject the retired `form_input_class()`, dead references, duplicate IDs and visible unowned hints/errors.

## A34 Portal pages that 404 on a missing record should flash_set() a localized __t() message and redi

- Portal pages that 404 on a missing record should `flash_set()` a localized `__t()` message and `redirect_to()` the parent list, not `exit()` a bare string without layout. JSON/machine endpoints keep their `http_response_code()` + JSON envelope.

## A35 Sortable portal list tables use the lib/portal_sort.php helpers (portal_sort_apply() + portal_s

- Sortable portal list tables use the `lib/portal_sort.php` helpers (`portal_sort_apply()` + `portal_sort_header()`); do not hand-roll per-page `usort`/sort links. Sort keys are whitelisted, sorting is display-only (no locale, auth or wire impact), and any CSV export link carries the active `sort`/`dir`.

## A36 Deep links into the log viewer go through log_category_url() (lib/repo/log.php), which derives

- Deep links into the log viewer go through `log_category_url()` (`lib/repo/log.php`), which derives the tab from `VIRTUSPHERE_LOG_TABS`; do not hand-write `logs.php?category=<x>`. `logs.php` scopes the category filter to the active tab and discards a category the tab does not contain, so a tab-less link lands on the default `security` tab and shows unrelated rows without an error. Pinned by `tests/Static/LogDeepLinkContractTest.php` and `tests/Unit/LogTaxonomyTest.php`.
- Audit-log table navigation is keyset-only: `before` and `after` are mutually exclusive positive ids parsed by `log_cursor_from_query()`, while repository queries keep global `id DESC` display order. Filter/tab changes omit the cursor, invalid or exhausted positions are explicit and link to the newest matching window, and the CSV export uses its separate bounded snapshot cursor. Do not restore OFFSET or numeric page claims.

## A37 Deploy-job log links go through deploy_job_log_url() (lib/deploy_urls.php); do not hand-write d

- Deploy-job log links go through `deploy_job_log_url()` (`lib/deploy_urls.php`); do not hand-write `deploy_log.php?id=<x>`. Back navigation uses `deploy_job_origin_url()`: mission jobs return to their filtered deploy list, mission-less ESXi inventory jobs to the exact System-status card. Pinned by `tests/Static/DeployLogDeepLinkContractTest.php` and `tests/Unit/DeployUrlsTest.php`.

## A38 Mission navigation uses deploy_mission_url() and mission_details_url() (lib/deploy_urls.php). A

- Mission navigation uses `deploy_mission_url()` and `mission_details_url()` (`lib/deploy_urls.php`). A system/inventory job has no mission and therefore never receives an invented mission link. Help links use `help_url()` (`lib/help_page.php`); its panel/section registries match the rendered partials and ids in both directions, so never hand-write `help.php#...`.
- Mission/template list to details/VM list/editor navigation carries only the six closed display fields normalized by `portal_work_context()` (`lib/portal_work_context.php`). It is URL-bound per tab, never a session/global return URL, and never contains form data, selections, secrets or CSRF. `mission_details_url()`, `portal_work_context_vm_list_url()` and `vm_edit_url()` own the destinations; successful VM saves return with a stable `#vm-<id>` fragment, details return with `#mission-<id>`, and a deleted/missing row receives no false focus. Destinations always recheck identity, permission and existence. Direct/invalid context degrades to the normal parent and works without JavaScript.

## A39 The same for the tabbed settings page: build links with settings_url() (lib/settings_page.php),

- The same for the tabbed settings page: build links with `settings_url()` (`lib/settings_page.php`), which validates the anchor against `VIRTUSPHERE_SETTINGS_TABS`/`_SECTIONS`; do not hand-write `settings.php#panel-<x>`. A missing or misspelled fragment opens the first tab, which does not hold the field the message just named, and nothing errors. This includes the POST redirect on the page itself. Pinned by `tests/Static/SettingsDeepLinkContractTest.php` and `SettingsTabRedirectContractTest.php`.

## A40 A message that states a prerequisite, an instruction or another page carries the link that sati

- A message that states a prerequisite, an instruction or another page carries the link that satisfies it. `deploy_queue_blockers()` is the complete queue decision for one normalized form state: server render, read-only live endpoint, preview and the immediate pre-write recheck all consume it; `$canQueue`, count, jump target and visible blocker list are derived only from its discriminated union. The browser serializes all `form.elements`, including disabled-but-filled values, and the endpoint is QoL rather than a security boundary. Say what is actually missing, one box per blocker. Gate a structured action on the *target's* permission and leave the sentence ungated, so a user who cannot fix it still learns why. The label names the destination as this portal names it, never the foreign system it configures, and the sentence drops its own pointer once the link carries it.

## A41 Opt-in editors track unsaved changes in memory and reuse the shared confirmation dialog

- Mission settings and the VM editor opt in with `form_unsaved_attrs()` and load `assets/unsaved_changes.js` through the page registry. The baseline is the last confirmed server state and remains in memory only: hidden controls and buttons are excluded, password/file values contribute only empty/changed markers, and no form value enters URL, session or browser storage. Dynamic rows and disabled visible values remain part of the comparison.
- A failed POST is dirty by default. The module may fetch the same-origin GET page and compare with its matching confirmed form, but a failed or ambiguous recovery never claims the values were saved. Link/form navigation uses the sole shared dialog through `virtusphere:confirm-request`/`virtusphere:confirm-result`; reload, Back and tab close use the browser-native `beforeunload` guard. The prompt is an aid only: ordinary server validation, CSRF and authorization remain authoritative, and no-JavaScript submission/navigation retains the existing server behavior without an early-warning claim.

## A42 Effective-value presentation delegates to the deploy owners and distinguishes intent from observation

- `lib/portal_effective_values.php` describes VM Datastore, Datacenter and autostart by calling the existing `ansible_effective_*()`, `ansible_*_autostart()` and `repo_vm_delay_value()` owners. Its closed sources distinguish VM override, mission inheritance, VM setting gated by the mission, target-host resolution and unavailable. A stored or drafted value is intended configuration, never an assertion about current ESXi state.
- `assets/effective_values.js` only projects the server-declared field/source relation onto live controls; it does not fetch, normalize, persist or submit. “Use mission value” clears only a control whose existing field contract already defines empty as inheritance and emits normal input/change events, so validation, optimistic locking, UX04 and the deliberate save remain intact. No-JavaScript keeps the complete server text and hides the inert accelerator.

## A43 Action outcomes state only the committed local transition and point to its evidence owner

- Queue, schedule, cancel and retry flashes describe the stored job transition, not remote execution. A single job links through `deploy_job_log_url()` to that exact job; a stagger names the exact number of independently materialized jobs and links through `deploy_mission_url()` to the focused `#deploy-jobs` list. A confirmed cancellation does not claim rollback, and a retry names the new job rather than rewriting the old outcome.
- VM bulk outcomes name processed and selected counts. Any closed skip reason changes the flash from success to warning and remains visible by grouped reason. Portal-row deletion explicitly claims no hypervisor deletion; MECM reset explicitly claims neither deletion of the old MECM device nor completed device-sync and links to the MECM status. These are server-rendered facts and links; JavaScript supplies only the existing pre-action confirmation.

## A44 Copy controls accelerate visible values and never become their owner

- `lib/copy_control.php` renders the one accessible copy button and bounded status region. Static displays keep the exact value as selectable text and pass that same value through `data-copy-value`; editor buttons carry only `data-copy-source` and `assets/core.js` reads the control's current value when activated. Buttons are `type="button"`, retain focus and announce success only after the Clipboard promise resolves.
- Empty values and a configured-IP field disabled by DHCP expose no copy action. Clipboard absence, a synchronous refusal or a rejected promise produces the localized manual-selection message while the visible value remains intact. Multiple IP/MAC controls name their network-adapter position and update that accessible name when repeat rows change.
- A caller may offer only a value already rendered to the current authorized reader. It must not add hidden secrets or a second value snapshot merely to make copying possible. “Configured IP address” is intended portal configuration, never an observed runtime address.

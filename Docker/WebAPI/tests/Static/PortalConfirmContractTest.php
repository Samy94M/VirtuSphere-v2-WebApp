<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../Support/PortalActionInventory.php';

/**
 * The confirmation contract (ADR-0013) is an attribute agreement between portal
 * markup, lib/layout_modals.php and assets/app.js, so php -l, node --check, the
 * lang audit and the rest of the unit suite all stay green while it is broken.
 *
 * Two failures this pins down, both silent:
 *  - A destructive action that simply forgets data-confirm. Nothing warns; the
 *    row is gone on the first stray click.
 *  - Replaying the confirmed submit with form.submit() instead of
 *    requestSubmit(trigger). That drops the form's submitter, which strips a
 *    name="action" button's value, and the handler falls through to its default
 *    branch: the page redirects, the flash appears, nothing is deleted.
 *
 * The classification below is deliberately *closed*. An earlier version guessed
 * which actions were dangerous from their name (delete, clear, reset, ...) and
 * therefore never looked at `generate_token`, which silently invalidates the
 * token deployed on the MECM server, nor at `set_role`, which lets an admin
 * demote themselves. Guessing loses. Every action a portal form can POST must
 * now be either confirmed in markup or listed in SAFE_ACTIONS with its reason,
 * so a new action fails the build until somebody decides which it is.
 */
final class PortalConfirmContractTest extends TestCase
{
    private const LAYOUT = 'lib/layout.php';
    private const MODALS = 'lib/layout_modals.php';

    /**
     * Actions that may POST without a confirmation, each with the reason.
     * Keyed "page.php:action". Adding an entry is a decision, not a formality:
     * write down why the action cannot lose anything the user wanted to keep.
     *
     * @var array<string, string>
     */
    private const SAFE_ACTIONS = [
        // Pure creation: nothing existing is touched.
        'credentials.php:create' => 'creates a new credential',
        'missions.php:create' => 'creates a new mission',
        'users.php:create' => 'creates a new user',
        'settings.php:allow_create' => 'adds an allowlist entry',
        'mission_details.php:clone_template' => 'creates a new mission from a template',
        'mission_details.php:save_as_template' => 'creates a new template',
        'missions.php:import_preview' => 'renders a preview, writes nothing',
        'missions.php:import_confirm' => 'creates a mission; the preview step is the confirmation',

        // Read-only or idempotent refreshes.
        'credentials.php:test' => 'runs a diagnostic or queues a read-only inventory pull; no managed object is deleted',
        'mission_details.php:export' => 'downloads JSON, writes nothing',
        'system_status.php:refresh_inventory' => 're-reads the ESXi inventory into the cache',
        'system_status.php:run_mecm_probe' => 'runs a read-only TCP reachability check',

        // Edits of the record the user is already looking at, with its own form.
        'credentials.php:update' => 'edits the credential the form belongs to',
        'mission_details.php:update' => 'edits the mission the form belongs to',

        // Settings writes: the previous value is visible in the field being replaced.
        'settings.php:save_api' => 'overwrites a value shown in the same input',
        'settings.php:save_https_hsts' => 'toggles a response header; state is visible on the same badge and reversible with the same button',
        'settings.php:save_esxi_inventory' => 'overwrites a value shown in the same input',
        'settings.php:save_password_policy' => 'overwrites a value shown in the same input',
        'settings.php:save_session' => 'overwrites a value shown in the same input',
        'settings.php:save_probe' => 'overwrites a value shown in the same input',
        'settings.php:save_retire_threshold' => 'overwrites a value shown in the same input',
        'settings.php:save_timezone' => 'overwrites a value shown in the same select',

        // Deliberate, documented exceptions.
        'users.php:clear_lock' => 'unlocking a locked-out account is a reversible remediation, not a loss',
        'deploy.php:start' => 'queueing a deploy is the purpose of the page; scheduled jobs get their own preview/confirm step (B3.3)',
    ];

    private function root(): string
    {
        return str_replace('\\', '/', dirname(__DIR__, 2));
    }

    private function read(string $relative): string
    {
        $path = $this->root() . '/' . $relative;
        self::assertFileExists($path, $relative . ' must exist');

        return (string) file_get_contents($path);
    }

    /**
     * The portal client scripts, concatenated. app.js was split into
     * core.js/forms.js/deploy.js; the confirm logic lives in core.js, but
     * scanning all of them keeps this contract insensitive to which file holds
     * what.
     */
    private function appJs(): string
    {
        $files = glob($this->root() . '/portal/assets/*.js') ?: [];
        self::assertNotSame([], $files, 'no portal assets/*.js scripts were found');

        $source = '';
        foreach ($files as $path) {
            $source .= (string) file_get_contents($path) . "\n";
        }

        return $source;
    }

    /**
     * Drop whole-line comments. The comments in app.js deliberately name the
     * patterns these tests forbid, so scanning them raw finds the prose, not a
     * regression.
     */
    private function stripComments(string $source): string
    {
        return preg_replace('#^\s*(//|\*|/\*).*$#m', '', $source) ?? $source;
    }

    /** @return array<string, string> relative path => contents */
    private function portalPages(): array
    {
        $pages = PortalActionInventory::portalPages($this->root());
        self::assertNotSame([], $pages, 'no portal pages were scanned');

        return $pages;
    }

    /**
     * The shared scan (tests/Support/PortalActionInventory.php): the E2E
     * coverage contract consumes the same inventory, so the definition of
     * "postable action" cannot drift between the two.
     *
     * @return array<string, bool> "page.php:action" => confirmed
     */
    private function postableActions(): array
    {
        $actions = PortalActionInventory::postableActions($this->portalPages());
        self::assertNotSame([], $actions, 'the action scan matched nothing; the markup or the regex changed');

        return $actions;
    }

    public function testEveryPostableActionIsEitherConfirmedOrDeclaredSafe(): void
    {
        $unclassified = [];
        foreach ($this->postableActions() as $key => $confirmed) {
            if (!$confirmed && !array_key_exists($key, self::SAFE_ACTIONS)) {
                $unclassified[] = $key;
            }
        }

        sort($unclassified);
        self::assertSame(
            [],
            $unclassified,
            "action without data-confirm and without a SAFE_ACTIONS entry.\n"
            . "Add the prompt, or declare it safe with the reason it cannot lose anything."
        );
    }

    public function testSafeActionsListHasNoStaleOrContradictoryEntries(): void
    {
        $actions = $this->postableActions();

        $stale = array_diff(array_keys(self::SAFE_ACTIONS), array_keys($actions));
        sort($stale);
        self::assertSame([], $stale, 'SAFE_ACTIONS names an action no portal form posts any more; delete the entry');

        $contradictory = [];
        foreach (self::SAFE_ACTIONS as $key => $reason) {
            self::assertNotSame('', trim($reason), $key . ' must carry the reason it is safe');
            if (($actions[$key] ?? false) === true) {
                $contradictory[] = $key;
            }
        }

        sort($contradictory);
        self::assertSame([], $contradictory, 'declared safe but the markup confirms it; drop the SAFE_ACTIONS entry');
    }

    public function testEveryDangerSubmitButtonCarriesAConfirm(): void
    {
        // The danger fill is reserved for destructive actions (ADR-0013), so a
        // red submit button without a prompt is either a missing confirm or a
        // misused colour. type="button" is exempt: it edits unsaved rows only.
        $offenders = [];
        foreach ($this->portalPages() as $page => $contents) {
            preg_match_all('/<button\b[^>]*button-danger[^>]*>/', $contents, $matches);
            foreach ($matches[0] as $tag) {
                if (!str_contains($tag, 'type="submit"')) {
                    continue;
                }
                if (!str_contains($tag, 'data-confirm=')) {
                    $offenders[] = $page . ': ' . substr($tag, 0, 70);
                }
            }
        }

        self::assertSame([], $offenders, 'a .button-danger submit must ask before it destroys');
    }

    public function testConfirmPromptsAreLocalized(): void
    {
        // The dialog prints data-confirm verbatim, so a literal here would ship
        // untranslated prose straight into the modal (ADR-0014). The prompt may
        // reach the attribute through a local variable (missions.php picks one of
        // three wordings), so resolve exactly that one indirection.
        $offenders = [];
        foreach ($this->portalPages() as $page => $contents) {
            preg_match_all('/data-confirm(?:-action)?="([^"]*)"/', $contents, $matches);
            foreach ($matches[1] as $value) {
                if (str_contains($value, '__t(')) {
                    continue;
                }
                if (preg_match('/\$([a-zA-Z_][a-zA-Z0-9_]*)/', $value, $variable) === 1
                    && preg_match('/\$' . preg_quote($variable[1], '/') . '\s*=[^;]*__t\(/', $contents) === 1) {
                    continue;
                }
                $offenders[] = $page . ': ' . $value;
            }
        }

        self::assertSame([], $offenders, 'data-confirm/-action must render a __t() key, not a literal string');
    }

    public function testTheConfirmDialogIsRenderedExactlyOnce(): void
    {
        // One dialog for the whole portal. A second one would fight for the top
        // layer and only the first would be wired.
        $modals = $this->read(self::MODALS);
        self::assertSame(1, substr_count($modals, 'data-confirm-dialog'), 'layout_modals.php holds the one confirm dialog');

        foreach ($this->portalPages() as $page => $contents) {
            self::assertStringNotContainsString('data-confirm-dialog', $contents, $page . ' must not build its own confirm dialog');
        }
        self::assertStringNotContainsString('data-confirm-dialog', $this->read(self::LAYOUT), 'the dialog markup lives in layout_modals.php');
    }

    public function testLayoutFooterActuallyRendersBothModals(): void
    {
        // Extracting the markup into layout_modals.php introduced a new silent
        // failure: a footer that stops calling the renderer ships a portal where
        // every data-confirm button submits straight through, with no prompt and
        // no error. Pin the calls, not just the markup.
        $layout = $this->read(self::LAYOUT);

        self::assertStringContainsString("require_once __DIR__ . '/layout_modals.php'", $layout, 'layout.php must load the modal module');
        self::assertStringContainsString('layout_confirm_dialog();', $layout, 'layout_footer() must render the confirm dialog');
        self::assertStringContainsString('layout_session_modal();', $layout, 'layout_footer() must render the session modal');

        // Both calls belong to layout_footer(), not to some other function.
        $footer = strstr($layout, 'function layout_footer(): void');
        self::assertIsString($footer, 'layout_footer() must exist');
        $footer = strstr($footer, 'function status_badge', true) ?: $footer;
        self::assertStringContainsString('layout_confirm_dialog();', $footer, 'the confirm dialog is rendered by layout_footer()');
        self::assertStringContainsString('layout_session_modal();', $footer, 'the session modal is rendered by layout_footer()');
    }

    public function testNoPageHandRollsABrowserPrompt(): void
    {
        $scan = $this->portalPages() + [
            'portal/assets/*.js' => $this->appJs(),
            self::MODALS => $this->read(self::MODALS),
        ];

        $offenders = [];
        foreach ($scan as $file => $contents) {
            $code = $this->stripComments($contents);
            if (preg_match('/(?<![\w.])(?:window\.)?(?:confirm|alert)\s*\(/', $code) === 1) {
                $offenders[] = $file;
            }
        }

        self::assertSame([], $offenders, 'confirmations use the shared <dialog>, never window.confirm()/alert()');
    }

    public function testTheReplayPreservesTheFormSubmitter(): void
    {
        $code = $this->stripComments($this->appJs());

        self::assertStringContainsString('form.requestSubmit(trigger)', $code, 'the accepted click must be replayed through requestSubmit()');
        // logoutForm.submit() is a different form and stays legal; only the
        // confirm replay's own `form` variable is forbidden from using it.
        self::assertSame(0, preg_match('/(?<![\w$])form\s*\.\s*submit\s*\(/', $code), 'form.submit() drops the submitter and strips name="action"');
    }

    public function testAppJsReadsEveryConfirmAttributeTheMarkupRenders(): void
    {
        $appJs = $this->appJs();
        $markup = $this->read(self::MODALS);
        foreach ($this->portalPages() as $contents) {
            $markup .= $contents;
        }

        $used = [];
        foreach (['data-confirm', 'data-confirm-action', 'data-confirm-dialog', 'data-confirm-msg', 'data-confirm-accept'] as $attribute) {
            if (str_contains($markup, $attribute)) {
                $used[] = $attribute;
            }
        }

        self::assertNotSame([], $used, 'the confirmation markup disappeared entirely');
        foreach ($used as $attribute) {
            self::assertStringContainsString($attribute, $appJs, $attribute . ' is rendered but never read');
        }
    }

    public function testBothModalsShareTheOneBaseComponent(): void
    {
        // Session warning and confirm dialog are both <dialog class="modal">; a
        // third hand-rolled modal would duplicate the focus trap and z-index.
        // Comments stripped: the module's own docblock quotes the markup it documents.
        $modals = $this->stripComments($this->read(self::MODALS));
        self::assertSame(2, preg_match_all('/<dialog\b[^>]*class="modal/', $modals), 'both portal modals use the shared .modal base');
        self::assertStringNotContainsString('class="session-modal"', $modals, 'the session modal was folded into .modal');

        // The modal module is the only place that opens a <dialog> in markup.
        foreach ($this->portalPages() as $page => $contents) {
            self::assertStringNotContainsString('<dialog', $contents, $page . ' must not hand-roll a modal');
        }
    }
}

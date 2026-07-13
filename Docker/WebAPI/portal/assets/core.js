// Portal core: theme, the shared <dialog> modals (confirm + session), tabs,
// the session timer and clock-drift warning. Loaded first; forms.js and
// deploy.js are independent IIFEs that follow. No cross-file calls: each file
// registers only the delegated listeners for its own concern, and several
// document-level listeners coexist without conflict.
(function () {
    function setTheme(theme) {
        document.documentElement.dataset.theme = theme;
        try {
            localStorage.setItem('virtusphere.theme', theme);
        } catch (error) {}
    }

    // Modal dialogs (SSoT: the .modal block in components.css). Both portal
    // modals are native <dialog> elements, so the browser owns the top layer,
    // the focus trap and Escape. These helpers only add the background scroll
    // lock and guard against calling showModal() on an already open dialog,
    // which throws. Browsers without <dialog> fall back to toggling [open],
    // which still shows the panel but without the modal semantics.
    function openDialog(dialog) {
        if (!dialog || dialog.hasAttribute('open')) {
            return;
        }
        if (typeof dialog.showModal === 'function') {
            dialog.showModal();
        } else {
            dialog.setAttribute('open', '');
        }
        document.documentElement.classList.add('is-modal-open');
    }

    function closeDialog(dialog, returnValue) {
        if (!dialog || !dialog.hasAttribute('open')) {
            return;
        }
        if (typeof dialog.close === 'function') {
            dialog.close(returnValue || '');
        } else {
            dialog.removeAttribute('open');
        }
        releaseScrollLock();
    }

    function releaseScrollLock() {
        if (!document.querySelector('.modal[open]')) {
            document.documentElement.classList.remove('is-modal-open');
        }
    }

    // Escape closes a <dialog> without passing through closeDialog(). The close
    // event does not bubble, so this listens in the capture phase.
    document.addEventListener('close', function () {
        releaseScrollLock();
    }, true);

    // Set by initConfirmDialog() once the dialog is present and usable. A browser
    // without <dialog> leaves it null and every submit proceeds unconfirmed,
    // exactly as it does with JS disabled: the POST handler is the real gate.
    // No such browser reaches this portal (components.css already relies on :has(),
    // which no engine shipped before <dialog>), so there is no window.confirm()
    // fallback to keep alive.
    var requestConfirm = null;

    // window.confirm() blocked, so the click continued natively and the browser
    // carried the button along as the form's submitter. A dialog cannot block:
    // the click is cancelled and replayed once the user accepts. requestSubmit()
    // preserves that submitter; a plain form.submit() would drop it and silently
    // strip name="action" (os.php, credentials.php, the VM bulk actions).
    // The click() path covers engines that have <dialog> but not requestSubmit
    // (Safari 15.4-15.6); a native click carries the submitter just the same.
    function replayTrigger(trigger) {
        var form = trigger.form;
        if (form && typeof form.requestSubmit === 'function') {
            form.requestSubmit(trigger);
            return;
        }
        trigger.setAttribute('data-confirm-bypass', '1');
        try {
            trigger.click();
        } finally {
            trigger.removeAttribute('data-confirm-bypass');
        }
    }

    function initConfirmDialog() {
        var dialog = document.querySelector('[data-confirm-dialog]');
        if (!dialog || typeof dialog.showModal !== 'function') {
            return;
        }
        var message = dialog.querySelector('[data-confirm-msg]');
        var accept = dialog.querySelector('[data-confirm-accept]');
        if (!message || !accept) {
            return;
        }

        // Server-rendered generic label, used when a trigger carries no text of
        // its own. Captured before the first open overwrites it.
        var defaultAcceptLabel = accept.textContent;
        var pending = null;
        var opener = null;

        dialog.addEventListener('click', function (event) {
            // The dialog element is the dim layer and .modal-box swallows its
            // own clicks, so a hit on the element itself is a backdrop click.
            if (event.target === dialog) {
                closeDialog(dialog, 'cancel');
            }
        });

        dialog.addEventListener('close', function () {
            var accepted = dialog.returnValue === 'confirm';
            var trigger = pending;
            pending = null;
            dialog.returnValue = '';

            // Escape, backdrop and dismiss all land here; only an accept replays.
            if (opener && opener.isConnected && typeof opener.focus === 'function') {
                opener.focus();
            }
            opener = null;

            if (accepted && trigger && trigger.isConnected) {
                replayTrigger(trigger);
            }
        });

        requestConfirm = function (trigger) {
            if (dialog.hasAttribute('open')) {
                return;
            }

            // Cancelling the click also skipped the browser's constraint check,
            // so run it here. Asking "reset this password?" and only then
            // rejecting an empty field would waste the answer (users.php).
            var form = trigger.form;
            if (form && !trigger.formNoValidate && typeof form.checkValidity === 'function' && !form.checkValidity()) {
                if (typeof form.reportValidity === 'function') {
                    form.reportValidity();
                }
                return;
            }

            pending = trigger;
            opener = trigger;
            message.textContent = (trigger.getAttribute('data-confirm') || '').trim();

            // The accept button names the action it performs instead of saying
            // "OK". A trigger's own label is already localized; data-confirm-action
            // overrides it where that label would collide with the dismiss button
            // ("Abbrechen" on a deploy job).
            var label = (trigger.getAttribute('data-confirm-action') || trigger.textContent || '').trim();
            accept.textContent = label !== '' ? label : defaultAcceptLabel;

            var danger = trigger.classList.contains('button-danger');
            accept.classList.toggle('button-danger', danger);
            dialog.classList.toggle('modal-danger', danger);

            openDialog(dialog);
        };
    }

    // Global chrome clicks: theme toggle, sidebar nav toggle, and the shared
    // confirmation. Form-row editing (add/remove/toggle) is handled by forms.js
    // in its own listener.
    document.addEventListener('click', function (event) {
        var toggle = event.target.closest('[data-theme-toggle]');
        if (toggle) {
            var next = document.documentElement.dataset.theme === 'dark' ? 'light' : 'dark';
            setTheme(next);
            return;
        }

        var navToggle = event.target.closest('[data-nav-toggle]');
        if (navToggle) {
            var sidebar = navToggle.closest('.sidebar');
            if (sidebar) {
                var open = sidebar.classList.toggle('nav-open');
                navToggle.setAttribute('aria-expanded', open ? 'true' : 'false');
            }
            return;
        }

        var confirmButton = event.target.closest('[data-confirm]');
        // Three ways a [data-confirm] click proceeds untouched, all deliberate:
        // the replayed click after an accept (data-confirm-bypass), a browser
        // without <dialog> (requestConfirm stays null), and a blank question. A
        // conditional prompt must omit the attribute rather than render it empty,
        // because this selector matches a blank value too; blank means "no
        // confirmation configured" and must not swallow the submit. The static
        // contract test rejects a blank value, so this only guards markup that
        // never reaches the repo.
        if (confirmButton
            && requestConfirm
            && !confirmButton.hasAttribute('data-confirm-bypass')
            && (confirmButton.getAttribute('data-confirm') || '').trim() !== '') {
            event.preventDefault();
            requestConfirm(confirmButton);
        }
    });

    function initTabs() {
        var root = document.querySelector('[data-tabs]');
        if (!root) {
            return;
        }

        var list = root.querySelector('[data-tab-list]');
        var tabs = Array.prototype.slice.call(root.querySelectorAll('[data-tab-target]'));
        var panels = Array.prototype.slice.call(root.querySelectorAll('[data-tab-panel]'));
        if (!list || tabs.length === 0) {
            return;
        }

        function activate(tab, focus, updateHash) {
            var target = tab.getAttribute('data-tab-target');
            tabs.forEach(function (other) {
                var selected = other === tab;
                other.setAttribute('aria-selected', selected ? 'true' : 'false');
                other.tabIndex = selected ? 0 : -1;
            });
            panels.forEach(function (panel) {
                panel.hidden = panel.id !== target;
            });
            if (focus) {
                tab.focus();
            }
            if (updateHash && window.history && window.history.replaceState) {
                window.history.replaceState(null, '', '#' + target);
            }
        }

        list.addEventListener('click', function (event) {
            var tab = event.target.closest('[data-tab-target]');
            if (tab) {
                activate(tab, false, true);
            }
        });

        list.addEventListener('keydown', function (event) {
            var index = tabs.indexOf(document.activeElement);
            if (index === -1) {
                return;
            }
            var next = -1;
            if (event.key === 'ArrowRight') {
                next = (index + 1) % tabs.length;
            } else if (event.key === 'ArrowLeft') {
                next = (index - 1 + tabs.length) % tabs.length;
            } else if (event.key === 'Home') {
                next = 0;
            } else if (event.key === 'End') {
                next = tabs.length - 1;
            }
            if (next !== -1) {
                event.preventDefault();
                activate(tabs[next], true, true);
            }
        });

        var initial = tabs[0];
        var hash = window.location.hash.replace('#', '');
        var scrollTarget = null;
        var matchedTab = null;
        tabs.forEach(function (tab) {
            if (hash && tab.getAttribute('data-tab-target') === hash) {
                matchedTab = tab;
            }
        });
        if (matchedTab) {
            initial = matchedTab;
        } else if (hash) {
            // Deep link to an element inside a panel (e.g. settings.php#panel-backup):
            // open the owning panel and scroll the element into view.
            var nested = document.getElementById(hash);
            if (nested) {
                panels.forEach(function (panel) {
                    if (panel.contains(nested)) {
                        tabs.forEach(function (tab) {
                            if (tab.getAttribute('data-tab-target') === panel.id) {
                                initial = tab;
                                scrollTarget = nested;
                            }
                        });
                    }
                });
            }
        }

        list.hidden = false;
        activate(initial, false, false);
        if (scrollTarget && scrollTarget.scrollIntoView) {
            scrollTarget.scrollIntoView();
        }
    }

    function initSessionTimer() {
        var root = document.querySelector('[data-session-timer]');
        if (!root) {
            return;
        }

        var clock = root.querySelector('[data-session-clock]');
        var modal = document.querySelector('[data-session-modal]');
        var modalMsg = modal ? modal.querySelector('[data-session-modal-msg]') : null;
        var i18n = {};
        var i18nIsland = document.querySelector('[data-i18n-session]');
        if (i18nIsland) {
            try {
                i18n = JSON.parse(i18nIsland.textContent);
            } catch (error) {
                i18n = {};
            }
        }
        var logoutForm = document.querySelector('[data-session-logout-form]');
        var csrfInput = root.querySelector('input[name="_csrf"]');
        var csrfToken = csrfInput ? csrfInput.value : '';

        var warnAt = parseInt(root.getAttribute('data-warn-at') || '300', 10);
        var expiresIn = parseInt(root.getAttribute('data-expires-in') || '0', 10);
        // Absolute deadline. Recomputed against the wall clock on every tick so an
        // inactive/throttled tab still logs out at the right real-world moment.
        var deadline = Date.now() + (expiresIn > 0 ? expiresIn : 0) * 1000;
        var loggingOut = false;
        var pingPending = false;

        function fmt(totalSeconds) {
            var s = Math.max(0, totalSeconds);
            var m = Math.floor(s / 60);
            var r = s % 60;
            return (m < 10 ? '0' : '') + m + ':' + (r < 10 ? '0' : '') + r;
        }

        if (modal) {
            // The expiry warning is not dismissable: Escape would only hide it
            // until the next tick reopened it a second later.
            modal.addEventListener('cancel', function (event) {
                event.preventDefault();
            });
        }

        function openModal() {
            openDialog(modal);
        }

        function closeModal() {
            closeDialog(modal);
        }

        function doLogout() {
            if (loggingOut) {
                return;
            }
            loggingOut = true;
            if (logoutForm) {
                logoutForm.submit();
            } else {
                window.location.href = 'login.php';
            }
        }

        function tick() {
            if (loggingOut) {
                return;
            }
            var remaining = Math.ceil((deadline - Date.now()) / 1000);

            if (remaining <= 0) {
                if (clock) {
                    clock.textContent = fmt(0);
                }
                closeModal();
                doLogout();
                return;
            }

            if (clock) {
                clock.textContent = fmt(remaining);
                if (remaining <= warnAt) {
                    clock.classList.add('is-warning');
                } else {
                    clock.classList.remove('is-warning');
                }
            }

            if (remaining <= warnAt) {
                openModal();
                if (modalMsg && i18n.countdown_html) {
                    // Localized template comes from PHP via the [data-i18n-session]
                    // JSON island (ADR-0014); no translatable strings live in JS.
                    modalMsg.innerHTML = i18n.countdown_html.replace(
                        '{n}',
                        '<strong>' + remaining + '</strong>'
                    );
                }
            } else {
                closeModal();
            }
        }

        function extend() {
            if (pingPending || loggingOut) {
                return;
            }
            pingPending = true;
            fetch('session_ping.php', {
                method: 'POST',
                credentials: 'same-origin',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: '_csrf=' + encodeURIComponent(csrfToken)
            }).then(function (response) {
                if (response.status === 401) {
                    doLogout();
                    return null;
                }
                if (!response.ok) {
                    throw new Error('session ping failed');
                }
                return response.json();
            }).then(function (payload) {
                pingPending = false;
                if (!payload || !payload.ok) {
                    return;
                }
                deadline = Date.now() + (parseInt(payload.expires_in, 10) || 0) * 1000;
                if (clock) {
                    clock.classList.remove('is-warning');
                }
                closeModal();
                tick();
            }).catch(function () {
                // Transient failure (e.g. LAN blip): keep the existing countdown
                // running so the user can retry rather than being logged out.
                pingPending = false;
            });
        }

        document.addEventListener('click', function (event) {
            if (event.target.closest('[data-session-extend]')) {
                extend();
                return;
            }
            if (event.target.closest('[data-session-logout-now]')) {
                doLogout();
            }
        });

        tick();
        window.setInterval(tick, 1000);
    }

    // Settings "time" card: warn when the browser clock drifts from the server.
    // Labels come from a CSP-nonced JSON island (no hardcoded strings in JS).
    function initTimeDrift() {
        var island = document.querySelector('[data-server-time]');
        var target = document.querySelector('[data-time-drift]');
        if (!island || !target) {
            return;
        }
        var data;
        try {
            data = JSON.parse(island.textContent);
        } catch (error) {
            return;
        }
        var serverEpoch = Number(data.epoch);
        var warnSeconds = Number(data.warn_seconds) || 120;
        if (!serverEpoch) {
            return;
        }
        var browserEpoch = Math.round(Date.now() / 1000);
        var drift = Math.abs(browserEpoch - serverEpoch);
        if (drift > warnSeconds) {
            var minutes = Math.round(drift / 60);
            target.textContent = String(data.drift_message || '').replace('{minutes}', String(minutes));
            target.hidden = false;
        }
    }

    initConfirmDialog();
    initTabs();
    initSessionTimer();
    initTimeDrift();
}());

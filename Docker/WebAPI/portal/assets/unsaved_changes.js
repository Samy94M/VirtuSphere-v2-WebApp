// Opt-in unsaved-change protection for real editor forms. The server renders
// labels and decides which forms participate; this module stores no draft and
// uses the one confirmation dialog owned by core.js through DOM events.
(function () {
    var forms = Array.prototype.slice.call(document.querySelectorAll('[data-unsaved-form]'));
    if (forms.length === 0) {
        return;
    }

    var states = new Map();
    var approvedSubmitters = new WeakSet();
    var approvedForms = new WeakSet();
    var allowUnload = false;

    function snapshot(form) {
        var values = [];
        Array.prototype.forEach.call(form.elements, function (field) {
            var name = field.getAttribute('name') || '';
            var type = (field.getAttribute('type') || '').toLowerCase();
            if (name === '' || ['button', 'submit', 'reset', 'image', 'hidden'].indexOf(type) !== -1) {
                return;
            }
            if (type === 'password') {
                values.push([name, 'secret', field.value === '' ? 'empty' : 'changed']);
                return;
            }
            if (type === 'file') {
                values.push([name, 'file', field.files && field.files.length > 0 ? 'changed' : 'empty']);
                return;
            }
            if (type === 'checkbox' || type === 'radio') {
                if (field.checked) {
                    values.push([name, type, field.value]);
                }
                return;
            }
            if (field.tagName === 'SELECT' && field.multiple) {
                Array.prototype.forEach.call(field.selectedOptions, function (option) {
                    values.push([name, 'select', option.value]);
                });
                return;
            }
            values.push([name, field.tagName.toLowerCase(), field.value]);
        });

        return JSON.stringify(values);
    }

    function refresh(form) {
        var state = states.get(form);
        if (!state) {
            return false;
        }
        var dirty = state.unresolved || snapshot(form) !== state.baseline;
        state.dirty = dirty;
        if (state.status) {
            var label = dirty ? form.dataset.unsavedDirtyLabel : form.dataset.unsavedCleanLabel;
            if (state.status.textContent !== label) {
                state.status.textContent = label;
            }
            state.status.hidden = false;
        }
        return dirty;
    }

    function refreshAll() {
        return forms.some(function (form) { return refresh(form); });
    }

    function resolveConfirmedBaseline(form) {
        var state = states.get(form);
        if (!state || !state.unresolved || typeof window.fetch !== 'function') {
            return;
        }
        var url = new URL(window.location.href);
        url.hash = '';
        window.fetch(url.href, {
            method: 'GET',
            credentials: 'same-origin',
            cache: 'no-store',
            headers: {'Accept': 'text/html'},
        }).then(function (response) {
            var contentType = response.headers.get('content-type') || '';
            if (!response.ok || contentType.toLowerCase().indexOf('text/html') === -1) {
                throw new Error('confirmed editor baseline unavailable');
            }
            return response.text();
        }).then(function (html) {
            var parsed = new DOMParser().parseFromString(html, 'text/html');
            var key = form.getAttribute('data-unsaved-key');
            var confirmed = Array.prototype.find.call(
                parsed.querySelectorAll('[data-unsaved-form]'),
                function (candidate) { return candidate.getAttribute('data-unsaved-key') === key; }
            );
            if (!confirmed) {
                throw new Error('confirmed editor form missing');
            }
            state.baseline = snapshot(confirmed);
            state.unresolved = false;
            refresh(form);
        }).catch(function () {
            // Fail safe: an unprovable POST outcome remains dirty. The page
            // never turns a session/network failure into a saved claim.
        });
    }

    function requestLeave(opener, callback) {
        var handled = function (event) {
            if (event.detail && event.detail.accepted === true) {
                callback();
            }
        };
        opener.addEventListener('virtusphere:confirm-result', handled, {once: true});
        var intercepted = !opener.dispatchEvent(new CustomEvent('virtusphere:confirm-request', {
            bubbles: true,
            cancelable: true,
            detail: {
                message: forms[0].dataset.unsavedMessage || '',
                label: forms[0].dataset.unsavedLeaveLabel || '',
                danger: false,
            },
        }));
        if (!intercepted) {
            opener.removeEventListener('virtusphere:confirm-result', handled);
            callback();
        }
    }

    function isPlainPrimaryClick(event) {
        return event.button === 0 && !event.ctrlKey && !event.metaKey && !event.shiftKey && !event.altKey;
    }

    function linkLeavesDocument(link) {
        if (link.hasAttribute('download')) {
            return false;
        }
        var target = (link.getAttribute('target') || '').toLowerCase();
        if (target !== '' && target !== '_self') {
            return false;
        }
        var targetUrl;
        try {
            targetUrl = new URL(link.href, window.location.href);
        } catch (error) {
            return true;
        }
        return targetUrl.origin !== window.location.origin
            || targetUrl.pathname !== window.location.pathname
            || targetUrl.search !== window.location.search;
    }

    forms.forEach(function (form) {
        var state = {
            baseline: snapshot(form),
            unresolved: form.hasAttribute('data-unsaved-restored'),
            dirty: false,
            status: form.querySelector('[data-unsaved-status]'),
        };
        states.set(form, state);
        refresh(form);
        resolveConfirmedBaseline(form);

        form.addEventListener('input', function () { refresh(form); });
        form.addEventListener('change', function () { refresh(form); });
        form.addEventListener('reset', function () {
            window.requestAnimationFrame(function () { refresh(form); });
        });
        form.querySelectorAll('[data-repeat-target]').forEach(function (target) {
            new MutationObserver(function () { refresh(form); }).observe(target, {childList: true});
        });
    });

    // Capture before core.js opens an action confirmation. If that action sits
    // outside the dirty editor, the loss warning comes first; accepting it marks
    // the eventual confirmed submit so no second loss prompt follows.
    document.addEventListener('click', function (event) {
        if (!isPlainPrimaryClick(event) || !refreshAll()) {
            return;
        }

        var link = event.target.closest('a[href]');
        if (link && linkLeavesDocument(link)) {
            event.preventDefault();
            event.stopImmediatePropagation();
            requestLeave(link, function () {
                allowUnload = true;
                window.location.assign(link.href);
            });
            return;
        }

        var submitter = event.target.closest('button, input[type="submit"], input[type="image"]');
        if (!submitter || !submitter.form || submitter.form.hasAttribute('data-unsaved-form')) {
            return;
        }
        if (approvedSubmitters.has(submitter)) {
            return;
        }
        event.preventDefault();
        event.stopImmediatePropagation();
        requestLeave(submitter, function () {
            approvedSubmitters.add(submitter);
            submitter.click();
            window.setTimeout(function () { approvedSubmitters.delete(submitter); }, 0);
        });
    }, true);

    document.addEventListener('submit', function (event) {
        var form = event.target;
        var submitter = event.submitter || null;
        if (form.hasAttribute('data-unsaved-form')
            || approvedForms.has(form)
            || (submitter && approvedSubmitters.has(submitter))) {
            approvedForms.delete(form);
            if (submitter) {
                approvedSubmitters.delete(submitter);
            }
            allowUnload = true;
            window.setTimeout(function () { allowUnload = false; }, 1000);
            return;
        }
        if (!refreshAll()) {
            return;
        }
        event.preventDefault();
        var opener = submitter || form;
        requestLeave(opener, function () {
            approvedForms.add(form);
            form.requestSubmit(submitter || undefined);
            window.setTimeout(function () { approvedForms.delete(form); }, 0);
        });
    }, true);

    window.addEventListener('beforeunload', function (event) {
        if (!allowUnload && refreshAll()) {
            event.preventDefault();
            event.returnValue = '';
        }
    });
}());

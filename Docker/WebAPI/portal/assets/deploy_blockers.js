// Live deploy blocker list. The server remains authoritative; this read-only
// mirror gives the same answer while the operator changes queue controls.
(function () {
    var root = document.querySelector('[data-deploy-blockers]');
    var form = document.querySelector('form:has([data-deploy-mission])');
    var button = document.querySelector('[data-deploy-queue-button]');
    if (!root || !form || !button) {
        return;
    }

    var list = root.querySelector('[data-deploy-blocker-list]');
    var summary = root.querySelector('[data-deploy-blocker-summary]');
    var warningList = root.querySelector('[data-deploy-warning-list]');
    var jump = root.querySelector('[data-deploy-blocker-jump]');
    var initialLimit = parseInt(root.getAttribute('data-initial-limit') || '0', 10) || 0;
    var timer = null;
    var controller = null;
    var requestSequence = 0;
    var stopped = false;
    var hardNetworkModes = [];
    try {
        hardNetworkModes = JSON.parse(root.getAttribute('data-hard-network-modes') || '[]');
    } catch (error) {
        hardNetworkModes = [];
    }

    function appendHidden(target, name, value) {
        var input = document.createElement('input');
        input.type = 'hidden';
        input.name = name;
        input.value = String(value);
        target.appendChild(input);
    }

    function queueParams() {
        var params = new URLSearchParams();
        Array.prototype.forEach.call(form.elements, function (field) {
            var name = field.name;
            if (!name || name === '_csrf' || name === 'action') {
                return;
            }
            if ((field.type === 'checkbox' || field.type === 'radio') && !field.checked) {
                return;
            }
            params.append(name === 'vm_ids[]' ? 'vm_ids[]' : name, field.value);
        });
        return params;
    }

    function blockerNode(blocker, index, prefix) {
        var box = document.createElement('div');
        box.className = 'alert alert-error';
        box.id = blocker.target_id || ('deploy-blocker-' + String(index + 1));
        box.setAttribute('data-deploy-blocker', '');

        var strong = document.createElement('strong');
        strong.textContent = prefix;
        box.appendChild(strong);
        box.appendChild(document.createTextNode(' ' + String(blocker.message || '')));

        var action = blocker.action;
        if (action && action.type === 'link') {
            box.appendChild(document.createTextNode(' '));
            var link = document.createElement('a');
            link.href = action.url;
            link.textContent = action.label;
            box.appendChild(link);
        }

        if (action && action.type === 'adopt') {
            var adoptForm = document.createElement('form');
            adoptForm.className = 'inline-form';
            adoptForm.method = 'post';
            adoptForm.action = action.url;
            var csrf = form.querySelector('input[name="_csrf"]');
            if (csrf) {
                appendHidden(adoptForm, '_csrf', csrf.value);
            }
            appendHidden(adoptForm, 'action', 'adopt_vm');
            Object.keys(action.fields || {}).forEach(function (name) {
                appendHidden(adoptForm, name, action.fields[name]);
            });
            var adopt = document.createElement('button');
            adopt.className = 'button button-secondary';
            adopt.type = 'submit';
            adopt.textContent = action.label;
            adopt.setAttribute('data-confirm', action.confirm);
            adoptForm.appendChild(adopt);
            box.appendChild(adoptForm);
        }

        return box;
    }

    // The rendered list is bounded exactly like the server render, and the
    // sentence naming what it leaves out comes from the response, because a
    // translated string may not be assembled here.
    function omittedNode(label, hook) {
        var note = document.createElement('p');
        note.className = 'muted';
        note.setAttribute(hook, '');
        note.textContent = label;
        return note;
    }

    function render(data) {
        list.replaceChildren();
        data.blockers.slice(0, initialLimit || data.blockers.length).forEach(function (blocker, index) {
            list.appendChild(blockerNode(blocker, index, data.labels.prefix));
        });
        if (String(data.labels.omitted || '') !== '') {
            list.appendChild(omittedNode(data.labels.omitted, 'data-deploy-blocker-omitted'));
        }
        if (warningList) {
            warningList.replaceChildren();
            var warnings = data.warnings || [];
            warnings.slice(0, initialLimit || warnings.length).forEach(function (warning) {
                var box = blockerNode(warning, 0, data.labels.warning_prefix || '');
                box.className = 'alert alert-warning';
                box.removeAttribute('id');
                box.removeAttribute('data-deploy-blocker');
                box.setAttribute('data-deploy-network-warning', '');
                warningList.appendChild(box);
            });
        }
        button.disabled = !data.can_queue;
        summary.hidden = data.count === 0;
        if (data.count > 0) {
            var count = summary.querySelector('strong');
            count.textContent = data.labels.count;
            jump.textContent = data.labels.jump;
            jump.href = '#' + String((data.blockers[0] || {}).target_id || 'deploy-blocker-1');
        }
    }

    // Session end, 403 or a network error. The submit stays blocked for the
    // modes whose network/WDS findings are hard blockers; for start/autostart
    // the client deliberately does NOT invent a global lock, because it has no
    // blocker data at all and the server re-checks the complete union at
    // submit time anyway. That asymmetry is a decision, not an oversight: a
    // client that disables the button on every hiccup teaches operators to
    // reload rather than to read. It is pinned by DeployBlockerContractTest.
    function renderFailure(message) {
        list.replaceChildren();
        var box = document.createElement('div');
        var mode = form.elements.mode ? String(form.elements.mode.value || '') : '';
        var hardMode = hardNetworkModes.indexOf(mode) !== -1;
        box.className = hardMode ? 'alert alert-error' : 'alert alert-warning';
        if (hardMode) {
            box.setAttribute('data-deploy-blocker', '');
        }
        box.textContent = String(message || root.getAttribute('data-error-message') || '');
        list.appendChild(box);
        summary.hidden = true;
        button.disabled = hardMode;
    }

    async function refresh() {
        requestSequence += 1;
        var sequence = requestSequence;
        if (controller) {
            controller.abort();
        }
        controller = new AbortController();
        try {
            var response = await fetch(root.getAttribute('data-endpoint') + '?' + queueParams().toString(), {
                credentials: 'same-origin',
                headers: {'Accept': 'application/json'},
                signal: controller.signal
            });
            var contentType = response.headers.get('content-type') || '';
            if (contentType.indexOf('application/json') === -1 || sequence !== requestSequence) {
                if (sequence === requestSequence) {
                    renderFailure(root.getAttribute('data-error-message'));
                }
                return;
            }
            var data = await response.json();
            if (sequence !== requestSequence) {
                return;
            }
            if (response.status === 401 || response.status === 403) {
                stopped = true;
                renderFailure(data.message);
                return;
            }
            if (response.ok && data.ok && Array.isArray(data.blockers)) {
                render(data);
            }
        } catch (error) {
            if (error.name !== 'AbortError') {
                renderFailure(root.getAttribute('data-error-message'));
            }
        }
    }

    function scheduleRefresh() {
        if (stopped) {
            return;
        }
        window.clearTimeout(timer);
        timer = window.setTimeout(refresh, 250);
    }

    form.addEventListener('input', scheduleRefresh);
    form.addEventListener('change', scheduleRefresh);
}());

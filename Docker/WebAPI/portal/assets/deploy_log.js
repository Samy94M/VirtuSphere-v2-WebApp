// Bounded deploy-job log window: initial tail comes from PHP; this module owns
// forward drain, stable older-page prepends, terminal catch-up and fetch faults.
(function () {
    var root = document.querySelector('[data-deploy-log]');
    if (!root) {
        return;
    }

    var body = root.querySelector('[data-deploy-log-body]');
    var status = root.querySelector('[data-deploy-status]');
    var olderButton = root.querySelector('[data-deploy-log-older]');
    var feedback = root.querySelector('[data-deploy-log-feedback]');
    var terminalBlocks = root.querySelector('[data-deploy-terminal-blocks]');
    var cancelForm = root.querySelector('[data-deploy-cancel-form]');
    var scroller = body ? body.closest('.table-wrap') : null;
    var island = document.querySelector('[data-i18n-deploy-log]');
    var i18n = {};
    if (island) {
        try { i18n = JSON.parse(island.textContent); } catch (error) { i18n = {}; }
    }

    var jobId = root.getAttribute('data-job-id');
    var afterSeq = parseInt(root.getAttribute('data-after-seq') || '0', 10);
    var beforeSeq = parseInt(root.getAttribute('data-before-seq') || '0', 10);
    var domLimit = Math.max(1, parseInt(root.getAttribute('data-dom-limit') || '1500', 10));
    var busy = false;
    var stopped = false;
    var historyMode = false;
    var retryDelay = 2000;
    if (!jobId || !body) {
        return;
    }

    function setFeedback(text) {
        if (feedback) { feedback.textContent = text || ''; }
    }

    function rowFor(entry) {
        var seqValue = String(entry.seq);
        if (body.querySelector('[data-log-seq="' + CSS.escape(seqValue) + '"]')) {
            return null;
        }
        var row = document.createElement('tr');
        row.setAttribute('data-log-seq', seqValue);
        [seqValue, entry.created_at || '', entry.stream_label || entry.stream || ''].forEach(function (text) {
            var cell = document.createElement('td');
            cell.textContent = text;
            row.appendChild(cell);
        });
        var lineCell = document.createElement('td');
        var code = document.createElement('code');
        code.className = 'log-line';
        code.textContent = entry.line || '';
        lineCell.appendChild(code);
        row.appendChild(lineCell);
        return row;
    }

    function removeEmpty() {
        var empty = body.querySelector('[data-empty-log]');
        if (empty) { empty.remove(); }
    }

    function trimOldest() {
        var rows = body.querySelectorAll('[data-log-seq]');
        while (rows.length > domLimit) {
            rows[0].remove();
            rows = body.querySelectorAll('[data-log-seq]');
            if (olderButton) { olderButton.hidden = false; }
        }
        var first = body.querySelector('[data-log-seq]');
        if (first) {
            beforeSeq = parseInt(first.getAttribute('data-log-seq') || '0', 10);
            root.setAttribute('data-before-seq', String(beforeSeq));
        }
    }

    function trimNewest() {
        var rows = body.querySelectorAll('[data-log-seq]');
        while (rows.length > domLimit) {
            rows[rows.length - 1].remove();
            rows = body.querySelectorAll('[data-log-seq]');
        }
    }

    function append(entries) {
        removeEmpty();
        entries.forEach(function (entry) {
            var row = rowFor(entry);
            if (row) { body.appendChild(row); }
            afterSeq = Math.max(afterSeq, parseInt(entry.seq || '0', 10));
        });
        root.setAttribute('data-after-seq', String(afterSeq));
        trimOldest();
    }

    function prepend(entries) {
        removeEmpty();
        var oldHeight = scroller ? scroller.scrollHeight : 0;
        var oldTop = scroller ? scroller.scrollTop : 0;
        var marker = body.firstChild;
        entries.forEach(function (entry) {
            var row = rowFor(entry);
            if (row) { body.insertBefore(row, marker); }
        });
        trimNewest();
        var first = body.querySelector('[data-log-seq]');
        if (first) {
            beforeSeq = parseInt(first.getAttribute('data-log-seq') || '0', 10);
            root.setAttribute('data-before-seq', String(beforeSeq));
        }
        if (scroller) {
            scroller.scrollTop = oldTop + Math.max(0, scroller.scrollHeight - oldHeight);
        }
    }

    function request(url) {
        return fetch(url, {
            headers: {Accept: 'application/json'},
            credentials: 'same-origin'
        }).then(function (response) {
            if (response.status === 401) {
                stopped = true;
                setFeedback(i18n.session_expired || '');
                return null;
            }
            if (response.status === 403) {
                stopped = true;
                setFeedback(i18n.forbidden || '');
                return null;
            }
            if (!response.ok) {
                throw new Error('deploy log request failed');
            }
            return response.json();
        });
    }

    function schedule(delay) {
        if (!stopped && !historyMode) {
            window.setTimeout(poll, delay);
        }
    }

    function poll() {
        if (busy || stopped || historyMode) { return; }
        busy = true;
        var url = 'deploy_log.php?id=' + encodeURIComponent(jobId)
            + '&format=json&after_seq=' + encodeURIComponent(String(afterSeq));
        request(url).then(function (payload) {
            busy = false;
            if (!payload || !payload.ok) { return; }
            retryDelay = 2000;
            setFeedback('');
            if (payload.job && status) {
                status.textContent = payload.job.status || '';
                status.className = 'badge badge-' + (payload.job.badge || 'neutral');
            }
            if (typeof payload.terminal_html === 'string' && terminalBlocks) {
                terminalBlocks.innerHTML = payload.terminal_html;
            }
            if (payload.actions && payload.actions.can_cancel === false && cancelForm) {
                cancelForm.remove();
                cancelForm = null;
            }
            if (Array.isArray(payload.logs)) { append(payload.logs); }
            if (payload.has_older && olderButton) { olderButton.hidden = false; }
            root.setAttribute('data-caught-up', payload.caught_up ? '1' : '0');
            if (payload.job && payload.job.terminal) {
                root.setAttribute('data-terminal', '1');
            }
            if (payload.job && payload.job.terminal && payload.caught_up) {
                stopped = true;
                return;
            }
            schedule(payload.has_more ? 0 : 2000);
        }).catch(function () {
            busy = false;
            setFeedback(i18n.failed || '');
            retryDelay = Math.min(5000, retryDelay * 2);
            schedule(retryDelay);
        });
    }

    function loadOlder() {
        if (busy || stopped || beforeSeq <= 0) { return; }
        busy = true;
        historyMode = true;
        setFeedback(i18n.loading || '');
        var url = 'deploy_log.php?id=' + encodeURIComponent(jobId)
            + '&format=json&before_seq=' + encodeURIComponent(String(beforeSeq));
        request(url).then(function (payload) {
            busy = false;
            if (!payload || !payload.ok) { return; }
            if (Array.isArray(payload.logs)) { prepend(payload.logs); }
            if (olderButton) { olderButton.hidden = !payload.has_older; }
            setFeedback(i18n.history_mode || '');
        }).catch(function () {
            busy = false;
            setFeedback(i18n.failed || '');
            if (olderButton) { olderButton.hidden = false; }
        });
    }

    if (olderButton) { olderButton.addEventListener('click', loadOlder); }
    if (!(root.getAttribute('data-terminal') === '1' && root.getAttribute('data-caught-up') === '1')) {
        schedule(2000);
    }
}());

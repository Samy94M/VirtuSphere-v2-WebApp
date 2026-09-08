// Bounded deploy-job log window: initial tail comes from PHP; this module owns
// forward drain, stable older-page prepends, terminal catch-up and fetch faults,
// plus the pausable follow mode, the visibility catch-up and the connection
// state (Etappe 13).
//
// Two rules run through the whole file. Nothing scrolls the reader against
// their will: following happens only while they are already at the end, and the
// moment they scroll up the view stops moving and tells them how much they have
// not seen. And nothing speaks per line: the log region is role="log" but with
// aria-live off, and one throttled status sentence summarises a batch, because
// an Ansible run emits thousands of lines and a polite live region would read
// all of them out loud.
(function () {
    var root = document.querySelector('[data-deploy-log]');
    if (!root) {
        return;
    }

    var body = root.querySelector('[data-deploy-log-body]');
    var status = root.querySelector('[data-deploy-status]');
    var olderButton = root.querySelector('[data-deploy-log-older]');
    var feedback = root.querySelector('[data-deploy-log-feedback]');
    var connection = root.querySelector('[data-deploy-log-connection]');
    var followToggle = root.querySelector('[data-deploy-log-follow]');
    var wrapToggle = root.querySelector('[data-deploy-log-wrap]');
    var jumpButton = root.querySelector('[data-deploy-log-jump]');
    var retryButton = root.querySelector('[data-deploy-log-retry]');
    var terminalBlocks = root.querySelector('[data-deploy-terminal-blocks]');
    var cancelForm = root.querySelector('[data-deploy-cancel-form]');
    var scroller = root.querySelector('[data-deploy-log-scroller]');
    var progressCard = document.querySelector('[data-deploy-create-progress]');
    var island = document.querySelector('[data-i18n-deploy-log]');
    var i18n = {};
    if (island) {
        try { i18n = JSON.parse(island.textContent); } catch (error) { i18n = {}; }
    }

    var jobId = root.getAttribute('data-job-id');
    var afterSeq = parseInt(root.getAttribute('data-after-seq') || '0', 10);
    var beforeSeq = parseInt(root.getAttribute('data-before-seq') || '0', 10);
    var domLimit = Math.max(1, parseInt(root.getAttribute('data-dom-limit') || '1500', 10));
    // Both numbers belong to lib/deploy_constants.php; reading them from the
    // attribute keeps the browser from carrying a second copy that can drift.
    var bottomTolerance = Math.max(0, parseInt(root.getAttribute('data-bottom-tolerance') || '4', 10));
    var statusThrottle = Math.max(0, parseInt(root.getAttribute('data-status-throttle') || '4000', 10));
    var followStorageKey = 'virtusphere.deploy_log.follow';
    var busy = false;
    var stopped = false;
    var accessDenied = false;
    var historyMode = false;
    var retryDelay = 2000;
    var timer = null;
    var unseenLines = 0;
    var lastSpokenAt = 0;
    var lastUpdatedAt = '';
    var scrollPaused = false;
    if (!jobId || !body) {
        return;
    }

    // Only this one UI preference is remembered, and only in this browser. A
    // private window or blocked site data must not break the page, so every
    // access is guarded and an unreadable store simply means the default.
    function readFollowPreference() {
        try {
            var stored = window.localStorage.getItem(followStorageKey);
            return stored === null ? true : stored === '1';
        } catch (error) {
            return true;
        }
    }

    function writeFollowPreference(enabled) {
        try {
            window.localStorage.setItem(followStorageKey, enabled ? '1' : '0');
        } catch (error) {
            // A viewer who blocked site data keeps the switch for this visit.
        }
    }

    // A filtered view has no cursor, so there is nothing to follow and nothing
    // to drain: the rows on screen are matches, not the next lines of the run.
    // Polling here would append full-log lines under a filtered list and, worse,
    // would advance a cursor the reader never saw. Only the wrap switch stays.
    var filtered = root.getAttribute('data-filtered') === '1';
    if (filtered) {
        if (wrapToggle) {
            wrapToggle.addEventListener('change', function () {
                root.classList.toggle('log-nowrap', !wrapToggle.checked);
            });
        }
        return;
    }

    var followEnabled = readFollowPreference();
    if (followToggle) {
        followToggle.checked = followEnabled;
    }

    function setFeedback(text) {
        if (feedback) { feedback.textContent = text || ''; }
    }

    function setConnection(text) {
        if (connection) { connection.textContent = text || ''; }
    }

    function translate(key, replacements) {
        var text = i18n[key] || '';
        if (!replacements) { return text; }
        Object.keys(replacements).forEach(function (name) {
            text = text.split(':' + name).join(String(replacements[name]));
        });
        return text;
    }

    // The connection sentence answers one question: is what I am looking at
    // current, and if not, why not. The states are distinguishable on purpose;
    // a session that ended and a network hiccup need different reactions.
    function renderConnection() {
        if (stopped) { return; }
        if (historyMode) { return; }
        if (root.getAttribute('data-terminal') === '1' && root.getAttribute('data-caught-up') === '1') {
            setConnection(translate('live_finished'));
            return;
        }
        if (!followEnabled) {
            setConnection(translate('live_off'));
            return;
        }
        if (document.hidden) {
            setConnection(translate('live_paused'));
            return;
        }
        setConnection(translate('live'));
    }

    // Visibility and href in ONE place. before_seq=0 is rejected by the
    // endpoint with a 400, so a control revealed without its link updated
    // would be a button that navigates to an error page; the server omits the
    // href for the same reason while nothing older exists.
    function setOlderState(hasOlder) {
        if (!olderButton) { return; }
        olderButton.hidden = !hasOlder;
        if (hasOlder && beforeSeq > 0) {
            olderButton.setAttribute('href', 'deploy_log.php?id=' + encodeURIComponent(jobId) + '&before_seq=' + encodeURIComponent(String(beforeSeq)));
        } else {
            olderButton.removeAttribute('href');
        }
    }
    function atBottom() {
        if (!scroller) { return true; }
        return (scroller.scrollHeight - scroller.scrollTop - scroller.clientHeight) <= bottomTolerance;
    }

    function scrollToEnd() {
        if (!scroller) { return; }
        // No smooth scroll: a log that keeps arriving would animate forever and
        // the reader could never catch a line.
        scroller.scrollTop = scroller.scrollHeight;
    }

    function renderUnseen() {
        if (!jumpButton) { return; }
        if (unseenLines <= 0) {
            jumpButton.hidden = true;
            jumpButton.textContent = '';
            return;
        }
        var counted = translate(unseenLines === 1 ? 'new_lines_one' : 'new_lines_many', {count: unseenLines});
        jumpButton.hidden = false;
        // The middle dot is the separator the status renderers already use for
        // two adjacent facts.
        jumpButton.textContent = counted + ' · ' + translate('jump_to_end');
    }

    // The throttled summary: a screen reader hears one sentence per window, not
    // one per Ansible line.
    function speakBatch(count) {
        if (count <= 0) { return; }
        var now = Date.now();
        if (now - lastSpokenAt < statusThrottle) { return; }
        lastSpokenAt = now;
        setFeedback(translate(count === 1 ? 'new_lines_one' : 'new_lines_many', {count: count}));
    }

    // The create progress card. Every value arrives finished from the server,
    // including the sentence about the current unit: a translated string is
    // never assembled in the browser, and the unit status is a token a reader
    // must not see raw. Only numbers and already-localized text are written.
    function renderCreateProgress(progress) {
        if (!progressCard) { return; }
        if (!progress) {
            // A job with no create section at all. Removing the card is right;
            // leaving it at zero would claim the job creates nothing.
            progressCard.remove();
            progressCard = null;
            return;
        }
        var position = progressCard.querySelector('[data-create-position]');
        if (position && typeof progress.position_label === 'string') {
            position.textContent = progress.position_label;
        }
        // The label and the "since" are separate nodes, so replacing one
        // cannot silently drop the other. No unit in flight empties the label
        // rather than freezing on the VM that was last worked on.
        var label = progressCard.querySelector('[data-create-current-label]');
        if (label) {
            label.textContent = progress.current && typeof progress.current.label === 'string'
                ? progress.current.label
                : '';
        }
        var since = progressCard.querySelector('[data-create-current-since]');
        if (since) {
            var sinceText = progress.current && typeof progress.current.since_label === 'string'
                ? progress.current.since_label
                : '';
            // The separator belongs to the fragment and is hidden with it; a
            // lone middle dot after an empty line reads as a rendering fault.
            since.hidden = sinceText === '';
            var sinceValue = since.querySelector('[data-create-since-text]');
            if (sinceValue) { sinceValue.textContent = sinceText; }
        }
        var counters = progress.counters || {};
        Object.keys(counters).forEach(function (name) {
            var cell = progressCard.querySelector('[data-create-count="' + CSS.escape(name) + '"]');
            if (cell) { cell.textContent = String(counters[name]); }
        });
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
        var trimmed = false;
        while (rows.length > domLimit) {
            rows[0].remove();
            rows = body.querySelectorAll('[data-log-seq]');
            trimmed = true;
        }
        var first = body.querySelector('[data-log-seq]');
        if (first) {
            beforeSeq = parseInt(first.getAttribute('data-log-seq') || '0', 10);
            root.setAttribute('data-before-seq', String(beforeSeq));
        }
        // Only now, with the cursor recomputed: trimming made older lines
        // reachable again, and revealing the control inside the loop above
        // would have pointed it at the cursor from before the trim.
        if (trimmed) { setOlderState(true); }
    }

    function trimNewest(lastVisible) {
        var rows = body.querySelectorAll('[data-log-seq]');
        while (rows.length > domLimit) {
            // Keep the visible range, even when history is loaded from near
            // the tail. Once it is reached, discard the farthest older rows.
            (rows[rows.length - 1] === lastVisible ? rows[0] : rows[rows.length - 1]).remove();
            rows = body.querySelectorAll('[data-log-seq]');
        }
    }

    function append(entries) {
        removeEmpty();
        // Whether the reader was at the end is decided BEFORE the rows land:
        // afterwards the scroll height has already grown and every position
        // reads as "scrolled up".
        var wasAtBottom = atBottom();
        // One DOM mutation for the whole batch. Appending row by row would let
        // assistive technology and the layout engine observe every intermediate
        // state of a five-hundred-line drain.
        var fragment = document.createDocumentFragment();
        var added = 0;
        entries.forEach(function (entry) {
            var row = rowFor(entry);
            if (row) {
                fragment.appendChild(row);
                added += 1;
            }
            afterSeq = Math.max(afterSeq, parseInt(entry.seq || '0', 10));
        });
        if (added > 0) {
            body.appendChild(fragment);
        }
        root.setAttribute('data-after-seq', String(afterSeq));
        trimOldest();

        if (added === 0) { return; }
        if (followEnabled && wasAtBottom && !scrollPaused) {
            scrollToEnd();
            unseenLines = 0;
        } else {
            unseenLines += added;
        }
        renderUnseen();
        speakBatch(added);
    }

    function prepend(entries) {
        removeEmpty();
        var anchor = null;
        var lastVisible = null;
        var anchorTop = 0;
        if (scroller) {
            var top = scroller.getBoundingClientRect().top;
            anchor = Array.prototype.find.call(body.querySelectorAll('[data-log-seq]'), function (row) {
                return row.getBoundingClientRect().bottom > top;
            });
            if (anchor) { anchorTop = anchor.getBoundingClientRect().top; }
            Array.prototype.forEach.call(body.querySelectorAll('[data-log-seq]'), function (row) {
                if (row.getBoundingClientRect().top < scroller.getBoundingClientRect().bottom) { lastVisible = row; }
            });
        }
        var marker = body.firstChild;
        var fragment = document.createDocumentFragment();
        entries.forEach(function (entry) {
            var row = rowFor(entry);
            if (row) { fragment.appendChild(row); }
        });
        body.insertBefore(fragment, marker);
        trimNewest(lastVisible);
        var first = body.querySelector('[data-log-seq]');
        if (first) {
            beforeSeq = parseInt(first.getAttribute('data-log-seq') || '0', 10);
            root.setAttribute('data-before-seq', String(beforeSeq));
        }
        if (scroller && anchor && anchor.isConnected) {
            scroller.scrollTop += anchor.getBoundingClientRect().top - anchorTop;
        }
    }

    function request(url) {
        return fetch(url, {
            headers: {Accept: 'application/json'},
            credentials: 'same-origin'
        }).then(function (response) {
            if (response.status === 401) {
                accessDenied = true;
                stopped = true;
                setConnection(i18n.session_expired || '');
                return null;
            }
            if (response.status === 403) {
                accessDenied = true;
                stopped = true;
                setConnection(i18n.forbidden || '');
                return null;
            }
            if (!response.ok) {
                throw new Error('deploy log request failed');
            }
            // A login page answering 200 is not this endpoint; treating it as
            // JSON would fail parsing and retry forever.
            var type = response.headers.get('Content-Type') || '';
            if (type.indexOf('application/json') === -1) {
                throw new Error('deploy log answered a non-JSON body');
            }
            return response.json();
        });
    }

    function schedule(delay) {
        if (stopped || historyMode) { return; }
        if (timer !== null) {
            window.clearTimeout(timer);
            timer = null;
        }
        // A background tab keeps its cursor but stops asking. The catch-up on
        // return is a single run, not a burst of the polls it missed.
        if (document.hidden) { return; }
        timer = window.setTimeout(poll, delay);
    }

    function poll() {
        timer = null;
        if (busy || stopped || historyMode) { return; }
        busy = true;
        var url = 'deploy_log.php?id=' + encodeURIComponent(jobId)
            + '&format=json&after_seq=' + encodeURIComponent(String(afterSeq));
        request(url).then(function (payload) {
            busy = false;
            if (!payload || !payload.ok) { return; }
            retryDelay = 2000;
            if (retryButton) { retryButton.hidden = true; }
            if (payload.job && status) {
                // Only `label`. `status` is still in the payload because the
                // wire field is older than this view and other readers use it,
                // but printing it would put a raw token back in front of a
                // person, and a fallback to it would do the same on exactly the
                // day the server sends something new.
                status.textContent = payload.job.label || '';
                status.className = 'badge badge-' + (payload.job.badge || 'neutral');
                lastUpdatedAt = payload.job.updated_at || lastUpdatedAt;
            }
            if (typeof payload.terminal_html === 'string' && terminalBlocks) {
                terminalBlocks.innerHTML = payload.terminal_html;
            }
            // A second tab that cancelled this job must be able to take the
            // button away here, on the same poll that brought the new status.
            if (payload.actions && payload.actions.can_cancel === false && cancelForm) {
                cancelForm.remove();
                cancelForm = null;
            }
            renderCreateProgress(payload.create_progress || null);
            if (Array.isArray(payload.logs)) { append(payload.logs); }
            if (payload.has_older) { setOlderState(true); }
            root.setAttribute('data-caught-up', payload.caught_up ? '1' : '0');
            if (payload.job && payload.job.terminal) {
                root.setAttribute('data-terminal', '1');
            }
            renderConnection();
            if (payload.job && payload.job.terminal && payload.caught_up) {
                stopped = true;
                setConnection(translate('live_finished'));
                return;
            }
            schedule(payload.has_more ? 0 : 2000);
        }).catch(function () {
            busy = false;
            // A pure network fault: say what is on screen is no longer current,
            // offer the manual retry and keep backing off in the meantime.
            setConnection(lastUpdatedAt
                ? translate('live_interrupted', {time: lastUpdatedAt})
                : (i18n.failed || ''));
            if (retryButton) { retryButton.hidden = false; }
            retryDelay = Math.min(5000, retryDelay * 2);
            schedule(retryDelay);
        });
    }

    function loadOlder() {
        if (busy || accessDenied || beforeSeq <= 0) { return; }
        busy = true;
        historyMode = true;
        setFeedback(i18n.loading || '');
        var url = 'deploy_log.php?id=' + encodeURIComponent(jobId)
            + '&format=json&before_seq=' + encodeURIComponent(String(beforeSeq));
        request(url).then(function (payload) {
            busy = false;
            if (!payload || !payload.ok) { return; }
            if (Array.isArray(payload.logs)) { prepend(payload.logs); }
            setOlderState(!!payload.has_older);
            setFeedback(i18n.history_mode || '');
            setConnection(i18n.history_mode || '');
        }).catch(function () {
            busy = false;
            setFeedback(i18n.failed || '');
            setOlderState(true);
        });
    }

    if (olderButton) {
        // The control is a real link now, so the default navigation is the
        // no-JavaScript path and must be suppressed here, not relied on: a
        // click that both fetched and navigated would throw the reader out of
        // the live view they are standing in.
        olderButton.addEventListener('click', function (event) {
            event.preventDefault();
            loadOlder();
        });
    }

    if (followToggle) {
        followToggle.addEventListener('change', function () {
            followEnabled = followToggle.checked;
            writeFollowPreference(followEnabled);
            if (followEnabled) {
                scrollPaused = false;
                scrollToEnd();
                unseenLines = 0;
                renderUnseen();
                setFeedback('');
            }
            renderConnection();
        });
    }

    if (wrapToggle) {
        wrapToggle.addEventListener('change', function () {
            root.classList.toggle('log-nowrap', !wrapToggle.checked);
        });
    }

    if (jumpButton) {
        jumpButton.addEventListener('click', function () {
            scrollPaused = false;
            scrollToEnd();
            unseenLines = 0;
            renderUnseen();
            setFeedback('');
            renderConnection();
        });
    }

    if (retryButton) {
        retryButton.addEventListener('click', function () {
            retryButton.hidden = true;
            retryDelay = 2000;
            schedule(0);
        });
    }

    if (scroller) {
        scroller.addEventListener('scroll', function () {
            if (atBottom()) {
                // Returning to the end is the only thing that clears the
                // counter: a batch that arrived while the reader was up there
                // stays counted until they have actually come back.
                if (scrollPaused) {
                    scrollPaused = false;
                    setFeedback('');
                }
                if (unseenLines > 0) {
                    unseenLines = 0;
                    renderUnseen();
                }
                return;
            }
            if (followEnabled && !scrollPaused) {
                scrollPaused = true;
                setFeedback(translate('follow_paused'));
            }
        });
    }

    document.addEventListener('visibilitychange', function () {
        if (document.hidden) {
            if (timer !== null) {
                window.clearTimeout(timer);
                timer = null;
            }
            renderConnection();
            return;
        }
        renderConnection();
        // Exactly one immediate catch-up. The cursor and the single-flight flag
        // are what keep this from producing parallel requests or duplicate rows.
        schedule(0);
    });

    renderConnection();
    // The initial tail is the NEWEST lines, and the reader is looking at its
    // first row. Without this the follow switch is on and yet nothing follows:
    // atBottom() is false at scrollTop 0, so the very first batch is counted as
    // unseen and the view never moves, which reads as a broken live mode rather
    // than as a deliberately paused one. A reader who turned following off keeps
    // the top, because then the start of the window is where they asked to be.
    if (followEnabled) {
        scrollToEnd();
    }
    if (!(root.getAttribute('data-terminal') === '1' && root.getAttribute('data-caught-up') === '1')) {
        schedule(2000);
    }
}());

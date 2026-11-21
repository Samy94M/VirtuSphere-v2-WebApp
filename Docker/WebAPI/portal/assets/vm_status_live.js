// Live status of the VM list, the VM overview, the VM editor and the dashboard
// VM tiles (VB2). The page renders the line [data-vm-live] with the poll address
// and the interval; the answer carries the same cells and regions the page
// rendered, from the same server renderers, each with a signature. This module
// only moves markup: it holds no wording of its own, every sentence comes from
// the page or the answer. Without it the page is what the server rendered.
(function () {
    'use strict';

    var root = document.querySelector('[data-vm-live]');
    if (!root || typeof window.fetch !== 'function') {
        return;
    }

    function number(name) {
        return parseInt(root.getAttribute(name) || '0', 10) || 0;
    }

    var baseUrl = root.getAttribute('data-vm-live-url') || '';
    var intervalMs = number('data-vm-live-interval') * 1000;
    var announceMs = number('data-vm-live-announce') * 1000;
    var maxIds = number('data-vm-live-max-ids');
    if (baseUrl === '' || intervalMs <= 0 || maxIds <= 0) {
        return;
    }

    var asOf = root.querySelector('[data-vm-live-as-of]');
    var status = root.querySelector('[data-vm-live-status]');
    var reload = root.querySelector('[data-vm-live-reload]');
    var notice = document.querySelector('[data-vm-live-notice]');
    var staleText = root.getAttribute('data-vm-live-stale-text') || '';
    var stoppedText = root.getAttribute('data-vm-live-stopped-text') || '';
    // A page with live table cells asks for its rows; the editor and the
    // dashboard ask for their regions and send no ids.
    var tableView = document.querySelector('[data-vm-live-cell]') !== null;

    var timer = null;
    var controller = null;
    var sequence = 0;
    var stopped = false;
    var stale = false;
    var lastTime = root.getAttribute('data-vm-live-time') || '';
    var changedText = '';
    var wanted = '';
    var lastSaid = 0;
    var sayTimer = null;

    function writeStatus() {
        sayTimer = null;
        if (status && status.textContent !== wanted) {
            status.textContent = wanted;
            lastSaid = Date.now();
        }
    }

    // The role="status" sentence (R37). A change of the page is spoken at most
    // once per announce window, with the newest sentence; a state the reader
    // must know now (not current, stopped, current again) is not held back.
    function say(text, urgent) {
        wanted = text;
        if (sayTimer !== null) {
            if (!urgent) { return; }
            window.clearTimeout(sayTimer);
            sayTimer = null;
        }
        var wait = urgent ? 0 : Math.max(0, lastSaid + announceMs - Date.now());
        if (wait === 0) {
            writeStatus();
            return;
        }
        sayTimer = window.setTimeout(writeStatus, wait);
    }

    function liveRows() {
        var ids = [];
        var rows = document.querySelectorAll('tr[id^="vm-"]');
        for (var i = 0; i < rows.length; i += 1) {
            var id = rows[i].id.slice(3);
            if (/^[1-9][0-9]*$/.test(id) && rows[i].querySelector('[data-vm-live-cell]')) {
                ids.push(id);
            }
        }
        return ids;
    }

    function requestUrl(ids, count) {
        if (ids === null) { return baseUrl; }
        return baseUrl + '&ids=' + encodeURIComponent(ids.join(',')) + '&count=' + encodeURIComponent(String(count));
    }

    function stopError(message) {
        var error = new Error('vm status live stopped');
        error.vmLiveStop = true;
        error.vmLiveMessage = message;
        return error;
    }

    function request(url, signal) {
        return fetch(url, {
            headers: {Accept: 'application/json'},
            credentials: 'same-origin',
            signal: signal
        }).then(function (response) {
            var type = response.headers.get('Content-Type') || '';
            var isJson = type.indexOf('application/json') !== -1;
            // A session that ended or a missing permission ends the polling;
            // asking again would only repeat the refusal.
            if (response.status === 401 || response.status === 403) {
                if (!isJson) { throw stopError(''); }
                return response.json().then(function (body) {
                    throw stopError(body && typeof body.message === 'string' ? body.message : '');
                }, function () {
                    throw stopError('');
                });
            }
            // A sign-in page answering 200 is not this endpoint.
            if (!response.ok || !isJson) {
                throw new Error('vm status live: no answer');
            }
            return response.json().then(function (data) {
                if (!data || data.ok !== true) {
                    throw new Error('vm status live: no verdict');
                }
                return data;
            });
        });
    }

    // Nothing is replaced under the reader's hands: not the element that holds
    // the focus, and nothing while the confirmation dialog is open, whose accept
    // replays the button it was opened from. The signature stays the old one,
    // so the next poll brings the change.
    function inUse(element, dialogOpen) {
        return dialogOpen || element.contains(document.activeElement);
    }

    function applyRows(rows, dialogOpen) {
        var changed = 0;
        Object.keys(rows).forEach(function (id) {
            var row = document.getElementById('vm-' + id);
            if (!row) { return; }
            var cells = row.querySelectorAll('[data-vm-live-cell]');
            for (var i = 0; i < cells.length; i += 1) {
                var cell = cells[i];
                var fresh = rows[id][cell.getAttribute('data-vm-live-cell')];
                if (!fresh || typeof fresh.html !== 'string'
                    || cell.getAttribute('data-vm-live-sig') === fresh.sig
                    || inUse(cell, dialogOpen)) {
                    continue;
                }
                cell.innerHTML = fresh.html;
                cell.setAttribute('data-vm-live-sig', fresh.sig);
                changed += 1;
            }
        });
        return changed;
    }

    function findRegion(key) {
        var regions = document.querySelectorAll('[data-vm-live-region]');
        for (var i = 0; i < regions.length; i += 1) {
            if (regions[i].getAttribute('data-vm-live-region') === key) { return regions[i]; }
        }
        return null;
    }

    // A region the poll rendered carries an empty CSRF field: the endpoint has
    // released the session. The page's own token goes in before the form is used.
    function buildRegion(html) {
        var holder = document.createElement('template');
        holder.innerHTML = html;
        var element = holder.content.firstElementChild;
        if (!element) { return null; }
        var source = document.querySelector('input[name="_csrf"]:not([data-vm-live-csrf])');
        var blanks = element.querySelectorAll('input[data-vm-live-csrf]');
        for (var i = 0; i < blanks.length; i += 1) {
            if (source) { blanks[i].value = source.value; }
            blanks[i].removeAttribute('data-vm-live-csrf');
        }
        return element;
    }

    // Regions arrive in page order. One that is absent on the page is placed
    // after the region before it, or before the first one that follows it.
    function applyRegions(regions, dialogOpen) {
        var changed = 0;
        var previous = null;
        regions.forEach(function (region, index) {
            var current = findRegion(String(region.key));
            var fresh;
            if (region.html === '') {
                if (current && !inUse(current, dialogOpen)) {
                    current.parentNode.removeChild(current);
                    changed += 1;
                } else if (current) {
                    previous = current;
                }
                return;
            }
            if (current) {
                if (current.getAttribute('data-vm-live-sig') !== region.sig && !inUse(current, dialogOpen)) {
                    fresh = buildRegion(region.html);
                    if (fresh) {
                        current.parentNode.replaceChild(fresh, current);
                        current = fresh;
                        changed += 1;
                    }
                }
                previous = current;
                return;
            }
            fresh = buildRegion(region.html);
            if (!fresh) { return; }
            if (previous) {
                previous.parentNode.insertBefore(fresh, previous.nextSibling);
            } else {
                var next = null;
                for (var i = index + 1; i < regions.length && next === null; i += 1) {
                    next = findRegion(String(regions[i].key));
                }
                if (next === null) { return; }
                next.parentNode.insertBefore(fresh, next);
            }
            previous = fresh;
            changed += 1;
        });
        return changed;
    }

    function apply(answers) {
        var dialogOpen = document.querySelector('dialog[open]') !== null;
        var changed = 0;
        var noticeText = '';
        var last = answers[answers.length - 1];
        answers.forEach(function (data) {
            changed += applyRows(data.rows || {}, dialogOpen);
            changed += applyRegions(Array.isArray(data.regions) ? data.regions : [], dialogOpen);
            // No row is added or removed here. A list that no longer matches
            // says so once, with the link that shows it; the status line
            // speaks its sentence, because a block that appears is not read out.
            if (data.list_changed && typeof data.notice === 'string' && data.notice !== '' && notice && notice.hidden) {
                notice.innerHTML = data.notice;
                notice.hidden = false;
                var sentence = notice.querySelector('[data-vm-live-notice-text]');
                noticeText = sentence ? String(sentence.textContent || '') : '';
            }
        });
        lastTime = String(last.time || lastTime);
        if (asOf) {
            asOf.textContent = String(last.as_of || '');
            asOf.hidden = false;
        }
        if (changed > 0) {
            changedText = String(last.changed || '');
        }
        if (noticeText !== '') {
            stale = false;
            say(noticeText, true);
        } else if (stale) {
            stale = false;
            say(changedText, true);
        } else if (changed > 0) {
            say(changedText, false);
        }
    }

    // Any answer that is not a verdict: the page no longer claims a state.
    function markStale() {
        stale = true;
        if (asOf) { asOf.hidden = true; }
        say(staleText.split(':time').join(lastTime), true);
    }

    function stop(message) {
        stopped = true;
        if (timer !== null) {
            window.clearTimeout(timer);
            timer = null;
        }
        if (asOf) { asOf.hidden = true; }
        say(message !== '' ? message : stoppedText, true);
        if (reload) { reload.hidden = false; }
    }

    function schedule(delay) {
        if (stopped) { return; }
        if (timer !== null) {
            window.clearTimeout(timer);
            timer = null;
        }
        // A background tab stops asking; it comes back with one poll (R36).
        if (document.hidden) { return; }
        timer = window.setTimeout(poll, delay);
    }

    function poll() {
        timer = null;
        if (stopped) { return; }
        // One round at a time. A round still in flight is given up, and its
        // answer, should it arrive, is dropped by the sequence check below.
        if (controller) { controller.abort(); }
        controller = typeof window.AbortController === 'function' ? new window.AbortController() : null;
        sequence += 1;
        var mine = sequence;
        var groups = [null];
        var ids = [];
        if (tableView) {
            ids = liveRows();
            groups = [];
            for (var i = 0; i < ids.length; i += maxIds) {
                groups.push(ids.slice(i, i + maxIds));
            }
            if (groups.length === 0) {
                schedule(intervalMs);
                return;
            }
        }
        var answers = [];
        var signal = controller ? controller.signal : undefined;
        var chain = Promise.resolve();
        groups.forEach(function (group) {
            chain = chain.then(function () {
                // An overtaken round sends nothing more.
                if (mine !== sequence || stopped) { throw new Error('vm status live: overtaken'); }
                return request(requestUrl(group, ids.length), signal);
            }).then(function (data) {
                answers.push(data);
            });
        });
        chain.then(function () {
            if (mine !== sequence || stopped) { return; }
            apply(answers);
            schedule(intervalMs);
        }).catch(function (error) {
            if (mine !== sequence || stopped) { return; }
            if (error && error.vmLiveStop) {
                stop(String(error.vmLiveMessage || ''));
                return;
            }
            markStale();
            schedule(intervalMs);
        });
    }

    document.addEventListener('visibilitychange', function () {
        if (document.hidden) {
            if (timer !== null) {
                window.clearTimeout(timer);
                timer = null;
            }
            return;
        }
        // Exactly one catch-up, never the polls the tab missed.
        schedule(0);
    });

    root.hidden = false;
    schedule(intervalMs);
}());

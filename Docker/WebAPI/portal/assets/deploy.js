// Portal deploy page: live job-log polling, the per-host/capability credential
// warnings, the schedule/stagger/power-cycle locks, the ESXi capacity bars and
// the live storage-requirement table. Independent of core.js and forms.js.
//
// Load order matters within this file: the mission-nav / select-all change
// listener is registered at load, before initDeployStorage() registers its own,
// so the checkboxes select-all flips are already in their new state when the
// storage table recomputes.
(function () {
    function appendDeployLogRow(body, entry) {
        var empty = body.querySelector('[data-empty-log]');
        if (empty) {
            empty.remove();
        }

        var row = document.createElement('tr');
        row.setAttribute('data-log-seq', String(entry.seq));

        var seq = document.createElement('td');
        seq.textContent = String(entry.seq);
        row.appendChild(seq);

        var time = document.createElement('td');
        time.textContent = entry.created_at || '';
        row.appendChild(time);

        var stream = document.createElement('td');
        stream.textContent = entry.stream || '';
        row.appendChild(stream);

        var lineCell = document.createElement('td');
        var code = document.createElement('code');
        code.className = 'log-line';
        code.textContent = entry.line || '';
        lineCell.appendChild(code);
        row.appendChild(lineCell);

        body.appendChild(row);
    }

    function initDeployLogPolling() {
        var root = document.querySelector('[data-deploy-log]');
        if (!root || root.getAttribute('data-terminal') === '1') {
            return;
        }

        var jobId = root.getAttribute('data-job-id');
        var afterSeq = parseInt(root.getAttribute('data-after-seq') || '0', 10);
        var body = document.querySelector('[data-deploy-log-body]');
        var status = document.querySelector('[data-deploy-status]');
        if (!jobId || !body) {
            return;
        }

        function poll() {
            fetch('deploy_log.php?id=' + encodeURIComponent(jobId) + '&format=json&after_seq=' + encodeURIComponent(String(afterSeq)), {
                headers: {Accept: 'application/json'},
                credentials: 'same-origin'
            }).then(function (response) {
                if (!response.ok) {
                    throw new Error('deploy log request failed');
                }
                return response.json();
            }).then(function (payload) {
                if (!payload.ok) {
                    return;
                }
                if (payload.job && status) {
                    status.textContent = payload.job.status || '';
                    status.className = 'badge badge-' + (payload.job.badge || 'neutral');
                }
                if (Array.isArray(payload.logs)) {
                    payload.logs.forEach(function (entry) {
                        appendDeployLogRow(body, entry);
                        afterSeq = Math.max(afterSeq, parseInt(entry.seq || '0', 10));
                    });
                    root.setAttribute('data-after-seq', String(afterSeq));
                }
                if (payload.job && payload.job.terminal) {
                    root.setAttribute('data-terminal', '1');
                    return;
                }
                window.setTimeout(poll, 2000);
            }).catch(function () {
                window.setTimeout(poll, 5000);
            });
        }

        window.setTimeout(poll, 2000);
    }

    // Mission select navigates; select-all flips the VM checkboxes. Registered
    // before initDeployStorage() so its recompute sees the flipped state.
    document.addEventListener('change', function (event) {
        var deployMission = event.target.closest('[data-deploy-mission]');
        if (deployMission) {
            var missionId = deployMission.value;
            window.location = 'deploy.php' + (missionId ? '?mission_id=' + encodeURIComponent(missionId) : '');
            return;
        }

        var vmSelectAll = event.target.closest('[data-vm-select-all]');
        if (vmSelectAll) {
            var boxes = document.querySelectorAll('input[name="vm_ids[]"]');
            for (var i = 0; i < boxes.length; i++) {
                boxes[i].checked = vmSelectAll.checked;
            }
        }
    });

    // Binds one credential-keyed warning island to one alert paragraph. The PHP
    // island maps ESXi credential ids to pre-localized texts; picking a listed
    // credential shows its warning before anything is submitted. Runs once at
    // init because a failed validation can re-render the form with a credential
    // preselected.
    function bindDeployCredentialWarning(islandSelector, targetSelector) {
        var island = document.querySelector(islandSelector);
        var select = document.querySelector('[data-deploy-esxi]');
        var target = document.querySelector(targetSelector);
        if (!island || !select || !target) {
            return;
        }
        var warnings;
        try {
            warnings = JSON.parse(island.textContent);
        } catch (error) {
            return;
        }
        if (!warnings || typeof warnings !== 'object') {
            return;
        }
        var update = function () {
            var text = warnings[select.value] || '';
            target.textContent = text;
            target.hidden = text === '';
        };
        select.addEventListener('change', update);
        update();
    }

    // Two disjoint boxes by construction: the host warning names mission values
    // the chosen host does not have, the capability warning names what the host
    // itself cannot do (free licence, HA cluster).
    function initDeployHostWarning() {
        bindDeployCredentialWarning('[data-deploy-host-warnings]', '[data-deploy-host-warning]');
        bindDeployCredentialWarning('[data-deploy-capability-warnings]', '[data-deploy-capability-warning]');
    }

    // Deploy schedule block: toggle the datetime field with the start-mode radio,
    // and keep mode and staggering consistent both ways (non-power modes cannot
    // stagger; a set stagger interval locks the non-staggerable mode options).
    function initDeploySchedule() {
        var scheduleAt = document.querySelector('[data-schedule-at]');
        var modeRadios = document.querySelectorAll('[data-schedule-mode]');
        if (scheduleAt && modeRadios.length) {
            var syncStart = function () {
                var scheduled = document.querySelector('[data-schedule-mode][value="scheduled"]');
                scheduleAt.hidden = !(scheduled && scheduled.checked);
            };
            modeRadios.forEach(function (radio) { radio.addEventListener('change', syncStart); });
            syncStart();
        }
        var modeSelect = document.querySelector('[data-stagger-modes]');
        var staggerInput = document.querySelector('[data-stagger-input]');
        var staggerLock = document.querySelector('[data-stagger-lock]');
        if (modeSelect && staggerInput) {
            var allowed = (modeSelect.getAttribute('data-stagger-modes') || '').split(',');
            var staggerActive = function () {
                return staggerInput.value !== '' && Number(staggerInput.value) > 0;
            };
            var syncStagger = function () {
                // Direction mode -> stagger: only the power-on modes can stagger.
                var modeOk = allowed.indexOf(modeSelect.value) !== -1;
                staggerInput.disabled = !modeOk;
                if (!modeOk) { staggerInput.value = ''; }
                // Direction stagger -> mode: while a stagger interval is set, lock the
                // non-staggerable options so a mode switch cannot silently drop it.
                var lock = staggerActive();
                Array.prototype.forEach.call(modeSelect.options, function (opt) {
                    if (allowed.indexOf(opt.value) === -1) { opt.disabled = lock && !opt.selected; }
                });
                if (staggerLock) { staggerLock.hidden = !lock; }
            };
            modeSelect.addEventListener('change', syncStagger);
            staggerInput.addEventListener('input', syncStagger);
            syncStagger();
        }
        // Mode -> wait time, one direction only. Unlike staggering the wait time
        // carries no intent that could lock a mode, so there is no reverse lock and
        // the value is left untouched: the server ignores it in a non-powercycle
        // mode anyway (warn-only, matches the JS-less path).
        var powercycleModeSelect = document.querySelector('[data-powercycle-modes]');
        var powercycleInput = document.querySelector('[data-powercycle-input]');
        var powercycleLock = document.querySelector('[data-powercycle-lock]');
        if (powercycleModeSelect && powercycleInput) {
            var powercycleAllowed = (powercycleModeSelect.getAttribute('data-powercycle-modes') || '').split(',');
            var syncPowercycle = function () {
                var modeOk = powercycleAllowed.indexOf(powercycleModeSelect.value) !== -1;
                powercycleInput.disabled = !modeOk;
                if (powercycleLock) { powercycleLock.hidden = modeOk; }
            };
            powercycleModeSelect.addEventListener('change', syncPowercycle);
            syncPowercycle();
        }
    }

    // ESXi inventory datastore usage bar: width + colour (kept out of inline styles
    // for CSP; >85% warning, >95% danger). Shared by the static bars and the live
    // deploy storage table, which recomputes its percentage on every change.
    function applyCapacityFill(fill, pct) {
        var value = Math.max(0, Math.min(100, Number(pct) || 0));
        fill.style.width = value + '%';
        fill.classList.remove('warning', 'danger');
        if (value > 95) {
            fill.classList.add('danger');
        } else if (value > 85) {
            fill.classList.add('warning');
        }
    }

    function initCapacityBars() {
        document.querySelectorAll('[data-capacity-pct]').forEach(function (fill) {
            applyCapacityFill(fill, fill.getAttribute('data-capacity-pct'));
        });
    }

    // Mirror of virtusphere_human_bytes() in lib/format.php. Both must produce the
    // same string: the queue table is rendered by PHP and then kept live by JS.
    function humanBytes(bytes) {
        var units = ['B', 'KB', 'MB', 'GB', 'TB'];
        var value = Number(bytes);
        if (!isFinite(value) || value < 0) {
            return '';
        }
        var unit = 0;
        while (value >= 1024 && unit < units.length - 1) {
            value /= 1024;
            unit++;
        }

        return (unit === 0 ? String(Math.floor(value)) : value.toFixed(1)) + ' ' + units[unit];
    }

    // Deploy queue form: live storage requirement per target datastore. The island
    // carries the provisioned bytes of every VM (keyed by its target datastore) and
    // the free/total bytes of every ESXi credential, both pre-computed by PHP. The
    // requirement follows the VM checkboxes, the free space and the verdict follow
    // the credential select. Warn-only: nothing here disables the queue button.
    function initDeployStorage() {
        var root = document.querySelector('[data-storage-live]');
        var island = root ? root.querySelector('[data-deploy-storage]') : null;
        var select = document.querySelector('[data-deploy-esxi]');
        if (!root || !island || !select) {
            return;
        }
        var data;
        try {
            data = JSON.parse(island.textContent);
        } catch (error) {
            return;
        }
        if (!data || !data.perVm) {
            return;
        }

        // The em dash is the portal's empty-value placeholder in table cells; PHP
        // writes the same glyph as &mdash;.
        var EMPTY_CELL = '—';
        var badges = {ok: 'success', insufficient: 'warning', unknown: 'neutral'};
        var boxes = Array.prototype.slice.call(document.querySelectorAll('input[name="vm_ids[]"]'));
        var rows = Array.prototype.slice.call(root.querySelectorAll('[data-storage-row]'));
        var totalRow = root.querySelector('[data-storage-total]');

        var setText = function (row, selector, text) {
            var cell = row.querySelector(selector);
            if (cell) {
                cell.textContent = text;
            }
        };

        var update = function () {
            // No checkbox checked means the whole mission, exactly as the server
            // reads an empty vm_ids list (repo_deploy_group_vm_list).
            var checked = boxes.filter(function (box) { return box.checked; });
            var selected = checked.length ? checked : boxes;
            var bytesByKey = {};
            var countByKey = {};
            selected.forEach(function (box) {
                var entry = data.perVm[box.value];
                if (!entry) {
                    return;
                }
                bytesByKey[entry.key] = (bytesByKey[entry.key] || 0) + Number(entry.bytes);
                countByKey[entry.key] = (countByKey[entry.key] || 0) + 1;
            });
            var free = (data.free && data.free[select.value]) || null;
            var totalBytes = 0;
            var totalVms = 0;

            rows.forEach(function (row) {
                var key = row.getAttribute('data-storage-row');
                var bytes = bytesByKey[key] || 0;
                var count = countByKey[key] || 0;
                totalBytes += bytes;
                totalVms += count;
                // A datastore none of the selected VMs targets is not a target.
                row.hidden = count === 0;
                setText(row, '[data-storage-vms]', String(count));
                setText(row, '[data-storage-required]', humanBytes(bytes));

                var freeText = row.querySelector('[data-storage-free-text]');
                var bar = row.querySelector('[data-storage-bar]');
                var verdict = row.querySelector('[data-storage-verdict]');
                // An empty key means the VM has no datastore at all, and without a
                // chosen credential there is nothing to compare against either.
                var info = key !== '' && free ? free[key] : null;
                var freeBytes = info && info.free !== null && info.free !== undefined ? Number(info.free) : null;
                var capacity = info && info.capacity !== null && info.capacity !== undefined ? Number(info.capacity) : null;

                if (freeText) {
                    freeText.textContent = freeBytes !== null ? humanBytes(freeBytes) : EMPTY_CELL;
                }
                if (bar) {
                    var showBar = freeBytes !== null && capacity !== null && capacity > 0;
                    bar.hidden = !showBar;
                    if (showBar) {
                        applyCapacityFill(bar.querySelector('.capacity-fill'), (capacity - freeBytes + bytes) / capacity * 100);
                    }
                }
                if (verdict) {
                    verdict.textContent = '';
                    if (key === '' || !free) {
                        verdict.textContent = EMPTY_CELL;
                        return;
                    }
                    var state = freeBytes === null ? 'unknown' : (freeBytes >= bytes ? 'ok' : 'insufficient');
                    var badge = document.createElement('span');
                    badge.className = 'badge badge-' + badges[state];
                    badge.textContent = data.labels[state] || '';
                    verdict.appendChild(badge);
                }
            });

            if (totalRow) {
                setText(totalRow, '[data-storage-vms]', String(totalVms));
                setText(totalRow, '[data-storage-required]', humanBytes(totalBytes));
            }
        };

        // Delegated on document and registered after the select-all handler above,
        // so the checkboxes it flips programmatically are already in their new
        // state by the time this runs.
        document.addEventListener('change', function (event) {
            var target = event.target;
            if (!target || typeof target.closest !== 'function') {
                return;
            }
            if (target.closest('[data-deploy-esxi], [data-vm-select-all]') || target.name === 'vm_ids[]') {
                update();
            }
        });
        update();
    }

    initDeployLogPolling();
    initDeployHostWarning();
    initDeploySchedule();
    initCapacityBars();
    initDeployStorage();
}());

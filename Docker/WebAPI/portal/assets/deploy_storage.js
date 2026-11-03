// Deploy capacity bars and the live storage requirement table.
(function () {    // ESXi inventory datastore usage bar: width + colour (kept out of inline styles
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
                        var pct = Math.max(0, Math.min(100, Math.round((capacity - freeBytes + bytes) / capacity * 100)));
                        applyCapacityFill(bar.querySelector('.capacity-fill'), pct);
                        // Same accessible name the server-rendered bar carries: the
                        // colour is the whole warning otherwise, and a bar without a
                        // name is nothing at all to a screen reader. The sentence
                        // comes from the island, only the number is filled in here.
                        var usage = (data.labels.usage_aria || '').replace(':pct', String(pct));
                        bar.setAttribute('aria-label', usage);
                        bar.setAttribute('title', usage);
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


    initCapacityBars();
    initDeployStorage();
}());

(function () {
    var group = document.querySelector('[data-vm-network-editor]');
    if (!group) {
        return;
    }
    var target = group.querySelector('[data-repeat-target="interfaces"]');
    var status = group.querySelector('[data-vm-network-status]');
    var undo = group.querySelector('[data-vm-network-undo]');
    var removedRow = null;
    var removedNext = null;
    var knownRows = target ? target.querySelectorAll('[data-repeat-row]').length : 0;
    var suppressAddedAnnouncement = false;
    var initialFocusApplied = false;

    function announce(message) {
        if (status) {
            status.textContent = message;
        }
    }

    function describedBy(select, errorId, active) {
        var ids = String(select.getAttribute('aria-describedby') || '').split(/\s+/).filter(Boolean);
        ids = ids.filter(function (id) { return id !== errorId; });
        if (active) {
            ids.push(errorId);
        }
        if (ids.length) {
            select.setAttribute('aria-describedby', ids.join(' '));
        } else {
            select.removeAttribute('aria-describedby');
        }
    }

    function refresh() {
        var rows = Array.prototype.slice.call(group.querySelectorAll('[data-repeat-row]'));
        var byVlan = Object.create(null);
        var byCaseKey = Object.create(null);
        rows.forEach(function (row, index) {
            var label = row.querySelector('[data-vm-network-row-label]');
            if (label) {
                var rowLabel = String(group.getAttribute('data-row-label') || '').replace(':number', String(index + 1));
                if (label.textContent !== rowLabel) {
                    label.textContent = rowLabel;
                }
            }
            var select = row.querySelector('[data-vm-network-vlan]');
            if (!select) {
                return;
            }
            var vlan = String(select.value || '').trim();
            if (vlan !== '') {
                (byVlan[vlan] || (byVlan[vlan] = [])).push(select);
                var caseKey = vlan.toLowerCase();
                (byCaseKey[caseKey] || (byCaseKey[caseKey] = [])).push(select);
            }
        });

        rows.forEach(function (row, index) {
            var select = row.querySelector('[data-vm-network-vlan]');
            if (!select) {
                return;
            }
            var vlan = String(select.value || '').trim();
            var duplicate = vlan !== '' && (byVlan[vlan] || []).length > 1;
            var message = vlan === ''
                ? String(group.getAttribute('data-required-message') || '')
                : (duplicate ? String(group.getAttribute('data-duplicate-message') || '').replace(':vlan', vlan) : '');
            select.setCustomValidity(message);
            if (message) {
                select.setAttribute('aria-invalid', 'true');
            } else {
                select.removeAttribute('aria-invalid');
            }
            var error = row.querySelector('[data-vm-network-error]') || row.querySelector('.field-error');
            var errorId = error && error.id ? error.id : 'vm-edit-interface-vlan-' + String(index) + '-network-error';
            if (!error) {
                error = document.createElement('span');
                error.className = 'field-error';
                error.id = errorId;
                error.setAttribute('data-vm-network-error', '');
                select.parentElement.appendChild(error);
            }
            error.setAttribute('data-vm-network-error', '');
            error.id = errorId;
            if (error.textContent !== message) {
                error.textContent = message;
            }
            error.hidden = message === '';
            describedBy(select, errorId, message !== '');
            var caseKey = vlan.toLowerCase();
            var caseSimilar = !duplicate && vlan !== '' && (byCaseKey[caseKey] || []).length > 1;
            var caseHintId = 'vm-edit-interface-vlan-' + String(index) + '-case-hint';
            var caseHint = row.querySelector('[data-vm-network-case-hint]');
            if (!caseHint) {
                caseHint = document.createElement('span');
                caseHint.className = 'muted';
                caseHint.setAttribute('data-vm-network-case-hint', '');
                select.parentElement.appendChild(caseHint);
            }
            caseHint.id = caseHintId;
            var caseMessage = caseSimilar ? String(group.getAttribute('data-case-message') || '') : '';
            if (caseHint.textContent !== caseMessage) {
                caseHint.textContent = caseMessage;
            }
            caseHint.hidden = !caseSimilar;
            describedBy(select, caseHintId, caseSimilar);
            var remove = row.querySelector('[data-remove-row]');
            if (remove) {
                remove.disabled = rows.length <= 1;
            }
        });
        if (!initialFocusApplied) {
            initialFocusApplied = true;
            var firstInvalid = group.querySelector('[data-vm-network-vlan][aria-invalid="true"]');
            if (firstInvalid) {
                firstInvalid.focus();
            }
        }
    }

    group.addEventListener('click', function (event) {
        var remove = event.target.closest('[data-remove-row]');
        if (remove && target) {
            event.preventDefault();
            event.stopPropagation();
            var rows = Array.prototype.slice.call(target.querySelectorAll('[data-repeat-row]'));
            if (rows.length <= 1) {
                return;
            }
            removedRow = remove.closest('[data-repeat-row]');
            removedNext = removedRow ? removedRow.nextElementSibling : null;
            var focusRow = removedNext || (removedRow ? removedRow.previousElementSibling : null);
            if (removedRow) {
                removedRow.remove();
                if (undo) {
                    undo.hidden = false;
                }
                announce(String(group.getAttribute('data-removed-message') || ''));
                var focusSelect = focusRow ? focusRow.querySelector('[data-vm-network-vlan]') : null;
                if (focusSelect) {
                    focusSelect.focus();
                }
                refresh();
            }
            return;
        }
        if (undo && event.target.closest('[data-vm-network-undo]') && target && removedRow) {
            event.preventDefault();
            suppressAddedAnnouncement = true;
            if (removedNext && removedNext.parentElement === target) {
                target.insertBefore(removedRow, removedNext);
            } else {
                target.appendChild(removedRow);
            }
            var restored = removedRow;
            removedRow = null;
            removedNext = null;
            undo.hidden = true;
            announce(String(group.getAttribute('data-restored-message') || ''));
            refresh();
            var restoredSelect = restored.querySelector('[data-vm-network-vlan]');
            if (restoredSelect) {
                restoredSelect.focus();
            }
        }
    });

    group.addEventListener('input', refresh);
    group.addEventListener('change', refresh);
    new MutationObserver(function () {
        refresh();
        if (!target) {
            return;
        }
        var rows = target.querySelectorAll('[data-repeat-row]');
        if (rows.length > knownRows && removedRow === null && !suppressAddedAnnouncement) {
            announce(String(group.getAttribute('data-added-message') || ''));
            var addedSelect = rows[rows.length - 1].querySelector('[data-vm-network-vlan]');
            if (addedSelect) {
                addedSelect.focus();
            }
        }
        suppressAddedAnnouncement = false;
        knownRows = rows.length;
    }).observe(group, {childList: true, subtree: true});
    refresh();
}());

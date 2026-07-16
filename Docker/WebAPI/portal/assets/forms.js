// Portal forms: repeat rows (add/remove/toggle), the subnet CIDR helper, the
// compound RAM/combo field, the DHCP interface-mode disabling and the VM bulk
// selection. Independent of core.js and deploy.js; registers its own delegated
// click/input/change listeners for the form hooks only.
(function () {
    function selectHasValue(select, value) {
        for (var index = 0; index < select.options.length; index += 1) {
            if (select.options[index].value === value) {
                return true;
            }
        }

        return false;
    }

    function cidrToSubnetMask(prefix) {
        var parts = [];
        var remaining = prefix;
        for (var index = 0; index < 4; index += 1) {
            if (remaining >= 8) {
                parts.push('255');
            } else if (remaining <= 0) {
                parts.push('0');
            } else {
                parts.push(String(256 - Math.pow(2, 8 - remaining)));
            }
            remaining -= 8;
        }

        return parts.join('.');
    }

    function subnetMaskForValue(value) {
        var normalized = String(value || '').trim();
        var match = normalized.match(/^\/(?:[0-9]|[12][0-9]|30)$/);
        if (match) {
            return cidrToSubnetMask(parseInt(normalized.substring(1), 10));
        }

        return normalized;
    }

    function subnetPickerValueForInput(value) {
        var normalized = subnetMaskForValue(value);
        for (var prefix = 0; prefix <= 30; prefix += 1) {
            var mask = cidrToSubnetMask(prefix);
            if (normalized === mask) {
                return mask;
            }
        }

        return '';
    }

    function syncSubnetPicker(picker) {
        if (!picker || picker.value === '') {
            return;
        }

        var row = picker.closest('[data-repeat-row]');
        var input = row ? row.querySelector('[data-subnet-input]') : null;
        if (input && !input.readOnly) {
            input.value = picker.value;
            input.dispatchEvent(new Event('input', {bubbles: true}));
        }
    }

    function syncSubnetInput(input, normalize) {
        if (!input) {
            return;
        }

        var value = input.value.trim();
        if (normalize) {
            var normalized = subnetMaskForValue(value);
            if (normalized !== value) {
                input.value = normalized;
                value = normalized;
            }
        }

        var row = input.closest('[data-repeat-row]');
        var picker = row ? row.querySelector('[data-subnet-picker]') : null;
        if (picker) {
            picker.value = subnetPickerValueForInput(value);
        }
    }

    // Compound field: a convenience <select> next to the real <input> (RAM presets).
    // Picking an option fills the input; the empty option means "keep the free text".
    function syncComboPicker(picker) {
        if (!picker) {
            return;
        }

        var root = picker.closest('.compound-field');
        var input = root ? root.querySelector('[data-combo-input]') : null;
        if (!input || input.readOnly) {
            return;
        }
        if (picker.value === '') {
            return;
        }

        input.value = picker.value;
        input.dispatchEvent(new Event('input', {bubbles: true}));
    }

    function syncComboInput(input) {
        if (!input) {
            return;
        }

        var root = input.closest('.compound-field');
        var picker = root ? root.querySelector('[data-combo-picker]') : null;
        if (picker) {
            var value = input.value.trim();
            picker.value = selectHasValue(picker, value) ? value : '';
        }
    }

    function syncInterfaceMode(select) {
        if (!select || select.disabled) {
            return;
        }
        var row = select.closest('[data-repeat-row]');
        if (!row) {
            return;
        }
        var dhcpValue = (select.getAttribute('data-mode-select') || 'dhcp').trim().toLowerCase();
        var isDhcp = select.value.trim().toLowerCase() === dhcpValue;
        row.querySelectorAll('[data-dhcp-disable]').forEach(function (field) {
            field.disabled = isDhcp;
        });
    }

    // VM bulk selection (vms.php): the row checkboxes sit in the table body and
    // associate with the bulk form via form="..."; they are not descendants, so
    // form.elements is the one collection that sees them. The submit buttons are
    // rendered disabled and only a non-empty selection enables them; the header
    // checkbox mirrors the selection (checked = all, indeterminate = some).
    function bulkItems(form) {
        var items = [];
        for (var index = 0; index < form.elements.length; index += 1) {
            var element = form.elements[index];
            if (element.hasAttribute('data-bulk-item')) {
                items.push(element);
            }
        }

        return items;
    }

    function syncBulkForm(form) {
        var items = bulkItems(form);
        var checkedCount = items.filter(function (item) { return item.checked; }).length;

        var counter = form.querySelector('[data-bulk-count]');
        if (counter) {
            counter.textContent = String(checkedCount);
        }
        form.querySelectorAll('[data-bulk-submit]').forEach(function (button) {
            button.disabled = checkedCount === 0;
        });

        var all = document.querySelector('[data-bulk-all]');
        if (all) {
            all.checked = items.length > 0 && checkedCount === items.length;
            all.indeterminate = checkedCount > 0 && checkedCount < items.length;
        }
    }

    function initDynamicControls(root) {
        root.querySelectorAll('[data-mode-select]').forEach(function (select) {
            syncInterfaceMode(select);
        });
        root.querySelectorAll('[data-subnet-picker]').forEach(function (picker) {
            syncSubnetPicker(picker);
        });
        root.querySelectorAll('[data-subnet-input]').forEach(function (input) {
            syncSubnetInput(input, false);
        });
        // Only input -> picker on init. The other direction could overwrite a
        // stored value with the picker's empty option before the user touched
        // anything.
        root.querySelectorAll('[data-combo-input]').forEach(function (input) {
            syncComboInput(input);
        });
    }

    document.addEventListener('click', function (event) {
        var add = event.target.closest('[data-add-row]');
        if (add) {
            var key = add.getAttribute('data-add-row');
            var template = document.querySelector('template[data-template="' + key + '"]');
            var target = document.querySelector('[data-repeat-target="' + key + '"]');
            if (template && target) {
                var html = template.innerHTML.replaceAll('__INDEX__', String(Date.now()));
                var holder = document.createElement('div');
                holder.innerHTML = html.trim();
                var row = holder.firstElementChild;
                target.appendChild(row);
                initDynamicControls(row);
            }
            return;
        }

        var remove = event.target.closest('[data-remove-row]');
        if (remove) {
            var removeRow = remove.closest('[data-repeat-row]');
            if (removeRow) {
                removeRow.remove();
            }
            return;
        }

        var rowToggle = event.target.closest('[data-row-toggle]');
        if (rowToggle) {
            var editor = document.getElementById(rowToggle.getAttribute('data-row-toggle'));
            if (editor) {
                editor.hidden = !editor.hidden;
                rowToggle.setAttribute('aria-expanded', editor.hidden ? 'false' : 'true');
            }
        }
    });

    document.addEventListener('input', function (event) {
        var subnetInput = event.target.closest('[data-subnet-input]');
        if (subnetInput) {
            syncSubnetInput(subnetInput, false);
            return;
        }

        var comboInput = event.target.closest('[data-combo-input]');
        if (comboInput) {
            syncComboInput(comboInput);
        }
    });

    document.addEventListener('change', function (event) {
        if (event.target.closest('[data-bulk-all]')) {
            var bulkForm = document.querySelector('[data-bulk-form]');
            if (bulkForm) {
                var selectAll = event.target.checked;
                bulkItems(bulkForm).forEach(function (item) { item.checked = selectAll; });
                syncBulkForm(bulkForm);
            }
            return;
        }

        var bulkItem = event.target.closest('[data-bulk-item]');
        if (bulkItem && bulkItem.form) {
            syncBulkForm(bulkItem.form);
            return;
        }

        var modeSelect = event.target.closest('[data-mode-select]');
        if (modeSelect) {
            syncInterfaceMode(modeSelect);
            return;
        }

        var subnetPicker = event.target.closest('[data-subnet-picker]');
        if (subnetPicker) {
            syncSubnetPicker(subnetPicker);
            return;
        }

        var comboPicker = event.target.closest('[data-combo-picker]');
        if (comboPicker) {
            syncComboPicker(comboPicker);
            return;
        }

        var subnetInput = event.target.closest('[data-subnet-input]');
        if (subnetInput) {
            syncSubnetInput(subnetInput, true);
            return;
        }

        var comboInput = event.target.closest('[data-combo-input]');
        if (comboInput) {
            syncComboInput(comboInput);
        }
    });

    initDynamicControls(document);
    // Back/forward navigation restores checkbox state without change events, so
    // the counter and buttons must be derived from the DOM once at load.
    document.querySelectorAll('[data-bulk-form]').forEach(syncBulkForm);
}());

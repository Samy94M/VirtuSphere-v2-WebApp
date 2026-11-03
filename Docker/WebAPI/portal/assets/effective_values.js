// Live projection for server-declared effective-value rows. The server owns
// field/source semantics and localized templates; this module only reflects
// the current controls and never writes configuration by itself.
(function () {
    var root = document.querySelector('[data-effective-values]');
    if (!root) {
        return;
    }
    var form = root.closest('form');
    if (!form) {
        return;
    }

    function fill(template, values) {
        return Object.keys(values).reduce(function (text, key) {
            return text.replaceAll(':' + key, String(values[key]));
        }, template || '');
    }

    function controls(name) {
        return Array.prototype.slice.call(form.elements).filter(function (control) {
            return control.getAttribute('name') === name;
        });
    }

    function controlValue(name) {
        var candidates = controls(name);
        var checkbox = candidates.find(function (control) { return control.type === 'checkbox'; });
        if (checkbox) {
            return checkbox.checked ? checkbox.value : '0';
        }
        return candidates.length > 0 ? candidates[candidates.length - 1].value : '';
    }

    function displayValue(raw, format) {
        if (format === 'seconds') {
            return fill(root.dataset.templateSeconds, {seconds: raw});
        }
        if (format === 'toggle') {
            return raw === '1' ? root.dataset.valueOn : root.dataset.valueOff;
        }
        return raw;
    }

    function updateRow(row) {
        var raw = String(controlValue(row.dataset.effectiveControl || '')).trim();
        var parentRaw = String(row.dataset.effectiveParent || '').trim();
        var format = row.dataset.effectiveFormat || 'plain';
        var output = row.querySelector('[data-effective-output]');
        var reset = row.querySelector('[data-effective-reset]');
        var text = '';

        if (format === 'toggle') {
            var vmSetting = displayValue(raw, format);
            text = parentRaw === '1'
                ? fill(root.dataset.templateVm, {value: vmSetting})
                : fill(root.dataset.templateBlocked, {
                    value: root.dataset.valueOff,
                    vm: vmSetting,
                });
        } else if (raw !== '') {
            var value = displayValue(raw, format);
            var parent = displayValue(parentRaw, format);
            text = fill(
                parentRaw !== '' ? root.dataset.templateOverride : root.dataset.templateOverrideEmpty,
                {value: value, mission: parent}
            );
            if (reset) {
                reset.hidden = false;
            }
        } else if (parentRaw !== '') {
            text = fill(root.dataset.templateMission, {value: displayValue(parentRaw, format)});
            if (reset) {
                reset.hidden = true;
            }
        } else {
            text = row.dataset.effectiveEmptySource === 'target_host'
                ? root.dataset.templateHost
                : root.dataset.templateUnavailable;
            if (reset) {
                reset.hidden = true;
            }
        }

        if (output && output.textContent !== text) {
            output.textContent = text;
        }
    }

    function updateAll() {
        root.querySelectorAll('[data-effective-row]').forEach(updateRow);
    }

    form.addEventListener('input', updateAll);
    form.addEventListener('change', updateAll);
    root.addEventListener('click', function (event) {
        var reset = event.target.closest('[data-effective-reset]');
        if (!reset) {
            return;
        }
        var target = controls(reset.dataset.effectiveReset || '')[0];
        if (!target) {
            return;
        }
        target.value = '';
        target.dispatchEvent(new Event('input', {bubbles: true}));
        target.dispatchEvent(new Event('change', {bubbles: true}));
        target.focus();
    });
    updateAll();
}());

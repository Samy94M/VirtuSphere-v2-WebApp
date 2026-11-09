// The Windows label next to a disk name field in the VM editor (NZ1).
// lib/vm_disk_layout.php owns the rule (vm_disk_volume_label) and the notice
// (vm_disk_label_notice); this read-only mirror only updates the sentence while
// the operator types. Both run tests/fixtures/disk-label-vectors.json, so a
// change on one side without the other fails. Texts and the limit come from the
// JSON island render_disk_label_island() writes, the default names from the
// field's data-autoname-* attributes; the file holds none of its own. DN2: the
// system disk (first row) shows the task sequence placeholder from the island,
// and a default name there (empty, the legacy default, '<prefix> <n>') is no
// name of its own (isDefaultName mirrors vm_disk_name_is_default()).
(function (root) {
    'use strict';

    // The submitted form encodes a lone surrogate as U+FFFD, so the server sees that.
    function wellFormed(value) {
        return String(value).replace(/[\uD800-\uDFFF]/gu, '�');
    }

    // PHP trim(): ASCII white space and NUL only, not U+00A0.
    function asciiTrim(value) {
        return value.replace(/^[ \t\n\r\0\x0B]+|[ \t\n\r\0\x0B]+$/g, '');
    }

    function volumeLabel(name, max) {
        var clean = wellFormed(name).replace(/[\p{Cc}\p{Cf}\\/:*?"<>|]+/gu, ' ');
        clean = clean.replace(/\s+/gu, ' ').replace(/^ +| +$/g, '');
        var label = '';
        var units = 0;
        for (var char of clean) {
            var width = char.codePointAt(0) > 0xFFFF ? 2 : 1;
            if (units + width > max) {
                break;
            }
            label += char;
            units += width;
        }
        label = label.replace(/ +$/, '');
        return label !== '' ? label : null;
    }

    function defaultName(position, first, prefix) {
        return position <= 1 ? first : prefix + ' ' + position;
    }

    function escapeRegExp(value) {
        return String(value).replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
    }

    // Mirror of vm_disk_name_is_default() (DN2): on the system disk empty, the
    // legacy default and the auto pattern '<prefix> <n>' are no name of its own.
    function isDefaultName(name, position, first, prefix, legacy) {
        var saved = asciiTrim(wellFormed(name));
        if (saved === '') {
            return true;
        }
        if (position > 1) {
            return saved === defaultName(position, first, prefix);
        }
        return saved === legacy || new RegExp('^' + escapeRegExp(prefix) + ' [0-9]+$').test(saved);
    }

    function labelNotice(name, position, max, first, prefix, legacy) {
        var saved = asciiTrim(wellFormed(name));
        if (position <= 1 && isDefaultName(saved, 1, first, prefix, legacy)) {
            return {kind: 'none', label: null};
        }
        if (saved === '') {
            return {kind: 'default', label: volumeLabel(defaultName(position, first, prefix), max)};
        }
        var label = volumeLabel(saved, max);
        if (label === null) {
            return {kind: position <= 1 ? 'system_fallback' : 'fallback', label: null};
        }
        return {kind: label === saved ? 'none' : 'label', label: label};
    }

    if (typeof module === 'object' && module && module.exports) {
        module.exports = {volumeLabel: volumeLabel, labelNotice: labelNotice, isDefaultName: isDefaultName};
    }

    var doc = root && root.document;
    var target = doc ? doc.querySelector('[data-repeat-target="disks"]') : null;
    var island = doc ? doc.querySelector('script[data-disk-label-texts]') : null;
    if (!target || !island) {
        return;
    }
    var texts = {};
    try {
        texts = JSON.parse(island.textContent || '{}') || {};
    } catch (error) {
        return;
    }
    var max = parseInt(texts.max, 10);
    if (!(max > 0)) {
        return;
    }

    function describedBy(input, hintId, active) {
        var ids = String(input.getAttribute('aria-describedby') || '').split(/\s+/).filter(Boolean);
        ids = ids.filter(function (id) { return id !== hintId; });
        if (active) {
            ids.push(hintId);
        }
        if (ids.length) {
            input.setAttribute('aria-describedby', ids.join(' '));
        } else {
            input.removeAttribute('aria-describedby');
        }
    }

    function names(input) {
        return {
            first: input.getAttribute('data-autoname-first') || '',
            prefix: input.getAttribute('data-autoname-prefix') || '',
            legacy: input.getAttribute('data-autoname-legacy') || ''
        };
    }

    // DN2: when the first row was removed, the row that moved up is the system
    // disk now. Its proposed 'Disk 2' would become the label of the Windows
    // drive, so it is cleared the way the server saves it; a name of the
    // operator's own stays. Only on a change of the first row, never while typing.
    var firstRow = target.querySelector('[data-repeat-row]');
    function normaliseMovedFirstRow() {
        var current = target.querySelector('[data-repeat-row]');
        if (current === firstRow) {
            return;
        }
        firstRow = current;
        var input = current ? current.querySelector('[data-disk-label-input]') : null;
        if (!input || input.value === '') {
            return;
        }
        var n = names(input);
        if (isDefaultName(input.value, 1, n.first, n.prefix, n.legacy)) {
            input.value = '';
        }
    }

    function refresh() {
        var rows = target.querySelectorAll('[data-repeat-row]');
        Array.prototype.forEach.call(rows, function (row, index) {
            var input = row.querySelector('[data-disk-label-input]');
            var hint = row.querySelector('[data-disk-label-hint]');
            if (!input || !hint) {
                return;
            }
            // The placeholder belongs to the system disk only and is never a value.
            if (index === 0 && texts.placeholder) {
                input.setAttribute('placeholder', String(texts.placeholder));
            } else {
                input.removeAttribute('placeholder');
            }
            var n = names(input);
            var notice = labelNotice(input.value, index + 1, max, n.first, n.prefix, n.legacy);
            var text = notice.kind === 'none' ? '' : String(texts[notice.kind] || '');
            text = text.replace(':label', function () { return notice.label || ''; });
            if (hint.textContent !== text) {
                hint.textContent = text;
            }
            hint.hidden = text === '';
            describedBy(input, hint.id, text !== '');
        });
    }

    target.addEventListener('input', refresh);
    target.addEventListener('change', refresh);
    new MutationObserver(function () {
        normaliseMovedFirstRow();
        refresh();
    }).observe(target, {childList: true});
    refresh();
}(typeof window !== 'undefined' ? window : null));

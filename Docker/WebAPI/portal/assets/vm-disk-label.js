// The Windows label next to a disk name field in the VM editor (NZ1).
// lib/vm_disk_layout.php owns the rule (vm_disk_volume_label) and the notice
// (vm_disk_label_notice); this read-only mirror only updates the sentence while
// the operator types. Both run tests/fixtures/disk-label-vectors.json, so a
// change on one side without the other fails. Texts and the limit come from the
// JSON island render_disk_label_island() writes, the default names from the
// field's data-autoname-* attributes; the file holds none of its own.
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

    function labelNotice(name, position, max, first, prefix) {
        if (position <= 1) {
            return {kind: 'none', label: null};
        }
        var saved = asciiTrim(wellFormed(name));
        if (saved === '') {
            return {kind: 'default', label: volumeLabel(defaultName(position, first, prefix), max)};
        }
        var label = volumeLabel(saved, max);
        if (label === null) {
            return {kind: 'fallback', label: null};
        }
        return {kind: label === saved ? 'none' : 'label', label: label};
    }

    if (typeof module === 'object' && module && module.exports) {
        module.exports = {volumeLabel: volumeLabel, labelNotice: labelNotice};
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

    function refresh() {
        var rows = target.querySelectorAll('[data-repeat-row]');
        Array.prototype.forEach.call(rows, function (row, index) {
            var input = row.querySelector('[data-disk-label-input]');
            var hint = row.querySelector('[data-disk-label-hint]');
            if (!input || !hint) {
                return;
            }
            var notice = labelNotice(
                input.value,
                index + 1,
                max,
                input.getAttribute('data-autoname-first') || '',
                input.getAttribute('data-autoname-prefix') || ''
            );
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
    new MutationObserver(refresh).observe(target, {childList: true});
    refresh();
}(typeof window !== 'undefined' ? window : null));

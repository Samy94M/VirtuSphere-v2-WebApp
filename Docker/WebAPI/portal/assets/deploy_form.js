// Deploy queue form navigation and schedule locks. Loaded before the storage module.
(function () {
    // The queue form's own values as a query string, so changing the mission can
    // stay a full page load (the VM list, the storage table and the per-host
    // warnings only exist server-side, per mission) without emptying the form the
    // operator already filled in. lib/deploy_form_state.php reads them back.
    //
    // Taken from the live controls, never from a field list: a field added to
    // deploy.php travels without a change here. form.elements rather than
    // FormData, because FormData drops disabled controls and the power-cycle wait
    // time deliberately keeps its typed value while a non-power mode disables it.
    // The checkbox list travels only within the mission that rendered it. Its
    // provenance marker distinguishes an explicitly empty same-mission list
    // from a real mission change, where the new mission starts with its own VMs.
    function deployQueueQuery(form, missionId, sourceMissionId) {
        var params = new URLSearchParams();
        if (missionId) {
            params.set('mission_id', missionId);
        }
        Array.prototype.forEach.call(form.elements, function (field) {
            var name = field.name;
            if (!name || name === '_csrf' || name === 'action' || name === 'mission_id' || name === 'vm_selection_mission_id') {
                return;
            }
            if (name === 'vm_ids[]') {
                if (missionId === sourceMissionId && field.checked) {
                    params.append(name, field.value);
                }
                return;
            }
            if ((field.type === 'checkbox' || field.type === 'radio') && !field.checked) {
                return;
            }
            if (field.value === '') {
                return;
            }
            params.set(name, field.value);
        });
        if (missionId && missionId === sourceMissionId) {
            params.set('vm_selection_mission_id', sourceMissionId);
        }

        return params.toString();
    }

    function deployNavigate(form, missionId, sourceMissionId) {
        var query = deployQueueQuery(form, missionId, sourceMissionId);
        window.location = 'deploy.php' + (query ? '?' + query : '');
    }

    // Keep the mission that produced the current checkbox rows. Reading the
    // select inside its change handler is too late: it already holds the target.
    var renderedMission = document.querySelector('[data-deploy-mission]');
    var renderedMissionId = renderedMission ? renderedMission.value : '';

    // Mission select navigates; select-all flips the VM checkboxes. Registered
    // before initDeployStorage() so its recompute sees the flipped state.
    document.addEventListener('change', function (event) {
        var deployMission = event.target.closest('[data-deploy-mission]');
        if (deployMission) {
            deployNavigate(deployMission.form, deployMission.value, renderedMissionId);
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

    // The job filter submits the same mission_id as the queue form's select, so
    // it re-renders that form as well. Without carrying its values, looking at
    // another mission's job history empties the form above it, which is the same
    // loss the mission select used to cause.
    function initDeployJobFilter() {
        var filter = document.querySelector('[data-deploy-filter]');
        var mission = document.querySelector('[data-deploy-mission]');
        if (!filter || !mission || !mission.form) {
            return;
        }
        filter.addEventListener('submit', function (event) {
            var picked = filter.querySelector('select[name="mission_id"]');
            // "All missions" is 0 here, not an empty value.
            var missionId = picked && picked.value !== '0' ? picked.value : '';
            event.preventDefault();
            deployNavigate(mission.form, missionId, renderedMissionId);
        });
    }

    // Deploy schedule block: toggle the datetime field with the start-mode radio,
    // and keep mode and staggering consistent both ways (non-power modes cannot
    // stagger; a set stagger interval locks the non-staggerable mode options).
    function initDeploySchedule() {
        var syncDescribedBy = function (control, hint, active) {
            if (!control || !hint || !hint.id) {
                return;
            }
            var ids = (control.getAttribute('aria-describedby') || '').split(/\s+/).filter(Boolean);
            var position = ids.indexOf(hint.id);
            if (active && position === -1) {
                ids.push(hint.id);
            } else if (!active && position !== -1) {
                ids.splice(position, 1);
            }
            if (ids.length) {
                control.setAttribute('aria-describedby', ids.join(' '));
            } else {
                control.removeAttribute('aria-describedby');
            }
        };
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
                if (staggerLock) {
                    staggerLock.hidden = !lock;
                    syncDescribedBy(modeSelect, staggerLock, lock);
                }
            };
            modeSelect.addEventListener('change', syncStagger);
            staggerInput.addEventListener('input', syncStagger);
            syncStagger();
        }
        // Mode -> wait time, one direction only. Unlike staggering a wait time
        // carries no intent that could lock a mode, so there is no reverse lock and
        // the value is left untouched: the server ignores it in a mode that does not
        // run the matching playbook anyway (warn-only, matches the JS-less path).
        // Both wait fields work this way, so the rule lives here once; the mode list
        // is derived server-side from the real playbook sequence.
        var lockWaitByMode = function (modesAttribute, inputSelector, lockSelector) {
            var select = document.querySelector('[' + modesAttribute + ']');
            var input = document.querySelector(inputSelector);
            var lock = document.querySelector(lockSelector);
            if (!select || !input) { return; }
            var allowed = (select.getAttribute(modesAttribute) || '').split(',');
            var sync = function () {
                var modeOk = allowed.indexOf(select.value) !== -1;
                input.disabled = !modeOk;
                if (lock) {
                    lock.hidden = modeOk;
                    syncDescribedBy(input, lock, !modeOk);
                }
            };
            select.addEventListener('change', sync);
            sync();
        };
        lockWaitByMode('data-powercycle-modes', '[data-powercycle-input]', '[data-powercycle-lock]');
        lockWaitByMode('data-start-wait-modes', '[data-start-wait-input]', '[data-start-wait-lock]');
    }


    initDeployJobFilter();
    initDeploySchedule();
}());

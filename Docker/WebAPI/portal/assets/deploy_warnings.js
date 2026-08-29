// Deploy credential warning islands.
(function () {    // Binds one credential-keyed warning island to one alert paragraph. The PHP
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
            var textTarget = target.querySelector('[data-deploy-warning-text]');
            if (textTarget) {
                textTarget.textContent = text;
            }
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


    initDeployHostWarning();
}());

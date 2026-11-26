<?php

declare(strict_types=1);

/**
 * Exemptions of the MECM term rule in scripts/lang-audit.php (i18n R15, PR-R3M).
 *
 * Every entry names its reason. An entry that matches nothing any more is a
 * finding ([lang-audit.mecm-term-stale]), so this file only shrinks.
 *
 * - phrases: a proper name or a verbatim quote that contains a term word but is
 *   not the MECM object in the console's words (space and hyphen alike,
 *   case-insensitive); removed before the word check.
 * - keys: `<locale>:<module>.<key>` whose word means something else.
 * - modules: whole catalogs outside the rule.
 * - term_parity: keys whose DE and EN values name different term placeholders.
 * - pending: `<module>.<key>` (both locales) not converted yet. PR-R3M2
 *   converted the last of them; the list stays empty. A new or changed value
 *   must use the placeholders.
 */
return [
    'phrases' => [
        'Site Health' => 'Name of the VirtuSphere component on the MECM server (scheduled task "VirtuSphere MECM Site Health", mecm_site-health.ps1) and of its reporter, log and data; not the MECM site',
        'Device Collections' => 'Folder path of the English MECM console ("Assets and Compliance → Device Collections"); console paths stay verbatim until the user decides whether they follow the console language',
        'Deployment Type' => 'Own MECM object (German console: Bereitstellungstyp), not one of the six term words; whether it joins the term sets is a question for the user',
        'collection=' => 'Field name of the cause line the MECM scripts report ("collection=<name>"), quoted verbatim',
        'collection_missing target=WEB01 collection=Firefox-115.0' => 'Verbatim example of a cause line of the device sync',
    ],
    'keys' => [
        'de:vms.th_location' => 'Column of the VM list: where the VM runs on ESXi, not the MECM site; its name is a question for VB3',
        'de:help_missions.location_datacenter_optional' => 'Standort here is where the VM lives in ESXi (datacenter, datastore), not the MECM site',
        // Ansible collections (community.vmware and friends), not the MECM object.
        'de:common.conn_ansible_config' => 'Ansible collection, not the MECM object',
        'en:common.conn_ansible_config' => 'Ansible collection, not the MECM object',
        'de:credentials.test_checks' => 'Ansible collection, not the MECM object',
        'en:credentials.test_checks' => 'Ansible collection, not the MECM object',
        'de:help_deploy.autostart_limit_order' => 'Ansible collection, not the MECM object',
        'en:help_deploy.autostart_limit_order' => 'Ansible collection, not the MECM object',
        'de:help_deploy.deploy_preflight_output_p1' => 'Ansible collection, not the MECM object',
        'en:help_deploy.deploy_preflight_output_p1' => 'Ansible collection, not the MECM object',
        'de:help_settings.settings_version_p2' => 'Ansible collection, not the MECM object',
        'en:help_settings.settings_version_p2' => 'Ansible collection, not the MECM object',
        'de:help_system_status.esxi_cause_fix_ansible_config' => 'Ansible collection, not the MECM object',
        'en:help_system_status.esxi_cause_fix_ansible_config' => 'Ansible collection, not the MECM object',
        'de:help_system_status.esxi_test_ansible_p1' => 'Ansible collection, not the MECM object',
        'en:help_system_status.esxi_test_ansible_p1' => 'Ansible collection, not the MECM object',
        'de:settings.version_ansible_action_deviates' => 'Ansible collection, not the MECM object',
        'en:settings.version_ansible_action_deviates' => 'Ansible collection, not the MECM object',
        // The portal's own deploy (glossary word "deploy", docs/ai/reference/i18n.md).
        // R3b rewords these; the stale rule then forces their removal.
        'de:help_system_status.logs_p2' => 'Portal-Bereitstellung (Name des Audit-Reiters logs.tab_deploy), Glossarwort kommt mit R3b',
        'de:help_system_status.logs_p2b' => 'Portal-Bereitstellung (Name des Audit-Reiters logs.tab_deploy), Glossarwort kommt mit R3b',
        'de:logs.category_deploy' => 'Portal-Bereitstellung (Name des Audit-Reiters), Glossarwort kommt mit R3b',
        'de:logs.tab_deploy' => 'Portal-Bereitstellung (Name des Audit-Reiters), Glossarwort kommt mit R3b',
        'en:ansible_test.behaviour' => 'Portal-Bereitstellung, Glossarwort kommt mit R3b',
        'en:ansible_test.help' => 'Portal-Bereitstellung, Glossarwort kommt mit R3b',
        'en:deploy.blocker_create_credential_target' => 'Portal-Bereitstellung, Glossarwort kommt mit R3b',
        'en:deploy.preparation_heading' => 'Portal-Bereitstellung, Glossarwort kommt mit R3b',
        'en:deploy_history.started_hint' => 'Portal-Bereitstellung, Glossarwort kommt mit R3b',
        'en:help.tab_deploy' => 'Portal-Bereitstellung, Glossarwort kommt mit R3b',
        'en:help_deploy.deploy_blockers_p1' => 'Portal-Bereitstellung, Glossarwort kommt mit R3b',
        'en:help_deploy.deploy_blockers_p2' => 'Portal-Bereitstellung, Glossarwort kommt mit R3b',
        'en:help_deploy.deploy_cancel_p2' => 'Portal-Bereitstellung, Glossarwort kommt mit R3b',
        'en:help_missions.effective_p2' => 'Portal-Bereitstellung, Glossarwort kommt mit R3b',
        'en:help_missions.location_datacenter_optional' => 'Portal-Bereitstellung, Glossarwort kommt mit R3b',
        'en:help_missions.mission_p3' => 'Portal-Bereitstellung, Glossarwort kommt mit R3b',
        'en:help_missions.status_axes_lifecycle' => 'Portal-Bereitstellung, Glossarwort kommt mit R3b',
        'en:help_missions.vm_steps_note' => 'Portal-Bereitstellung, Glossarwort kommt mit R3b',
        'en:help_overview.glossary_heading' => 'Portal-Bereitstellung, Glossarwort kommt mit R3b',
        'en:help_overview.workflow_step3' => 'Portal-Bereitstellung, Glossarwort kommt mit R3b',
        'en:help_settings.settings_p1' => 'Portal-Bereitstellung, Glossarwort kommt mit R3b',
        'en:help_settings.settings_runtime_p1' => 'Portal-Bereitstellung, Glossarwort kommt mit R3b',
        'en:help_stack.stack_a11_li1' => 'Portal-Bereitstellung, Glossarwort kommt mit R3b',
        'en:help_stack.stack_a11_li4' => 'Portal-Bereitstellung, Glossarwort kommt mit R3b',
        'en:help_stack.stack_a14_p1' => 'Portal-Bereitstellung, Glossarwort kommt mit R3b',
        'en:help_stack.stack_a2_li4' => 'Portal-Bereitstellung, Glossarwort kommt mit R3b',
        'en:help_stack.stack_a9_li5' => 'Portal-Bereitstellung, Glossarwort kommt mit R3b',
        'en:help_system_status.esxi_cause_p1' => 'Portal-Bereitstellung, Glossarwort kommt mit R3b',
        'en:help_system_status.firstaid_step2' => 'Portal-Bereitstellung, Glossarwort kommt mit R3b',
        'en:help_system_status.logs_p1' => 'Portal-Bereitstellung, Glossarwort kommt mit R3b',
        'en:help_system_status.logs_p2' => 'Portal-Bereitstellung, Glossarwort kommt mit R3b',
        'en:help_system_status.logs_p2b' => 'Portal-Bereitstellung, Glossarwort kommt mit R3b',
        'en:logs.category_deploy' => 'Portal-Bereitstellung, Glossarwort kommt mit R3b',
        'en:logs.tab_deploy' => 'Portal-Bereitstellung, Glossarwort kommt mit R3b',
        'en:settings.tab_deploy' => 'Portal-Bereitstellung, Glossarwort kommt mit R3b',
        'en:system_status.cap_legend_in_ha_cluster' => 'Portal-Bereitstellung, Glossarwort kommt mit R3b',
        'en:system_status.dev_hint' => 'Portal-Bereitstellung, Glossarwort kommt mit R3b',
        'en:system_status.service_snapshot_failed' => 'Portal-Bereitstellung, Glossarwort kommt mit R3b',
        'en:vm_edit.location_datacenter_host_hint' => 'Portal-Bereitstellung, Glossarwort kommt mit R3b',
    ],
    'modules' => [
    ],
    // `<module>.<key>` whose DE and EN values name different term placeholders on
    // purpose (an article form only one language needs). Case is never a
    // difference: {mecm:Site} and {mecm:site} are the same term.
    'term_parity' => [
    ],
    'pending' => [
    ],
];

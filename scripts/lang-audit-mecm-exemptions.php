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
        'VirtuSphere_Applications' => 'Name of the VirtuSphere console folder for packages (collections and applications), the same in every console language; not the MECM object word',
        'collection=' => 'Field name of the cause line the MECM scripts report ("collection=<name>"), quoted verbatim',
        'collection_missing target=WEB01 collection=Firefox-115.0' => 'Verbatim example of a cause line of the device sync',
    ],
    'keys' => [
        'de:vms.th_location' => 'Column of the VM list: where the VM runs on ESXi, not the MECM site; its name is a question for VB3',
        'de:help_missions.location_datacenter_optional' => 'Standort here is where the VM lives in ESXi (datacenter, datastore), not the MECM site',
        'de:help_stack.stack_a1_li1' => 'Describes the portal itself as a PHP application, not the MECM object',
        'en:help_stack.stack_a1_li1' => 'Describes the portal itself as a PHP application, not the MECM object',
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

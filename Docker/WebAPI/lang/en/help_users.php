<?php

declare(strict_types=1);

return [
    'roles_heading' => 'Administrator vs. User',
    'roles_p1' => 'There are exactly two roles. The role only controls which actions are visible and allowed in the portal. It has no effect on language or display.',
    'roles_matrix_th_feature' => 'Feature',
    'roles_matrix_view' => 'View the portal: dashboard, missions, VMs, packages, system status, help',
    'perm_missions_write' => 'Create, edit and clone missions and templates',
    'perm_vms_write' => 'Create, edit and delete VMs (incl. network adapters and package assignment)',
    'perm_deploy_run' => 'Queue deploy jobs and view their logs',
    'perm_catalog_write' => 'Maintain VLANs (operating systems and packages come from MECM and are read-only)',
    'perm_credentials_manage' => 'Create and manage credentials (ESXi/Ansible)',
    'perm_system_config' => 'Change system settings (API base URL, IP allowlist, report channel token, protection thresholds)',
    'perm_users_manage' => 'Manage user accounts and view audit logs',
    'usersmgmt_heading' => 'User management: the "Users" page',
    'usersmgmt_p1' => 'The "Users" page is visible to administrators only. It lists every account with its role, active state, password-change requirement, current lockout and last sign-in. New accounts are created here and existing ones are managed here; there is deliberately no self-registration.',
    'usersmgmt_create_heading' => 'Creating a new account',
    'usersmgmt_create_p1' => 'A new account needs a name, an initial password (at least :min characters, per the current password policy) and a role; the email address is optional and serves as a note only. Hand the initial password to the person through a secure channel.',
    'usersmgmt_create_p2' => 'New accounts always start with a mandatory password change: on first sign-in the person must replace the initial password with their own. After the handover, nobody but the account holder knows the valid password.',
    'usersmgmt_actions_heading' => 'Managing existing accounts',
    'usersmgmt_action_role' => 'Change role: pick the new role in the account row and save. The change takes effect with the person\'s next page action; no re-login is required.',
    'usersmgmt_action_active' => 'Deactivate instead of delete: a deactivated account can no longer sign in but is kept with its history and can be reactivated at any time. Accounts are deliberately never deleted so audit entries stay attributable.',
    'usersmgmt_action_reset' => 'Reset password: enter a new password (at least :min characters) in the row field and reset. The mandatory change on next sign-in applies here as well.',
    'usersmgmt_safety_heading' => 'Built-in safeguards',
    'usersmgmt_safety_1' => 'You cannot deactivate your own account, so nobody locks themselves out by accident.',
    'usersmgmt_safety_2' => 'The last active administrator can neither be deactivated nor demoted to the "User" role. There is always at least one admin left.',
    'usersmgmt_safety_3' => 'After several failed sign-in attempts an account is locked automatically for :minutes minutes; the "Active" column then shows a "Locked" badge. The lock expires on its own; while a lock is active an administrator can also clear it immediately with "Clear lock".',
    'usersmgmt_safety_4' => 'One exception remains: you can demote your own role as long as another administrator exists. The portal asks first, because user management is closed to you afterwards; only the other administrator can undo it.',
    'usersmgmt_audit_p1' => 'Every account change (creation, role change, active state, password reset, own password change) is recorded in the audit log under "Security" with user and timestamp. Sign-ins, sign-outs, failed sign-in attempts and automatic account locks appear there as well.',
];

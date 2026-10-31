<?php

declare(strict_types=1);

/**
 * The closed words an audit row carries in its context (a reason, an outcome, a
 * state, a blocker), and where each list comes from.
 *
 * A writer stores the word exactly as defined here, so the stored English sentence
 * stays the one it always was: search, the CSV export and saved filters match it
 * (HB-08 A). The logs page shows the word through audit_code_label()
 * (lib/audit_presenter_parts.php), whose catalog key is code_<field>_<slug> in
 * lang/{de,en}/audit_log.php; the English label is the word itself.
 *
 * Words that had no constant before live here. Lists that already had an owner
 * are referenced, never copied, in audit_code_vocabularies() (lib/audit_vocabulary_lists.php).
 */

// Why a sign-in was refused (auth.login).
const VIRTUSPHERE_AUDIT_LOGIN_REASON_INVALID_CREDENTIALS = 'invalid credentials';
const VIRTUSPHERE_AUDIT_LOGIN_REASON_ACCOUNT_LOCKED = 'account is locked';
const VIRTUSPHERE_AUDIT_LOGIN_REASON_ACCOUNT_DEACTIVATED = 'account is deactivated';

// Why a session ended (auth.session_ended). A failed directory recheck also
// stores its directory outcome (VIRTUSPHERE_DIRECTORY_OUTCOMES).
const VIRTUSPHERE_AUDIT_SESSION_REASON_EXPIRED = 'expired';
const VIRTUSPHERE_AUDIT_SESSION_REASON_DIRECTORY_INTEGRATION_DISABLED = 'directory integration disabled';
const VIRTUSPHERE_AUDIT_SESSION_REASON_DIRECTORY_DISABLED = 'directory_disabled';
const VIRTUSPHERE_AUDIT_SESSION_REASON_DIRECTORY_ACCOUNT_DISABLED = 'directory_account_disabled';
const VIRTUSPHERE_AUDIT_SESSION_REASON_DIRECTORY_UNAVAILABLE = 'directory_unavailable';

// Why a person's own password change was refused.
const VIRTUSPHERE_AUDIT_PASSWORD_REASON_CURRENT_WRONG = 'current password is wrong';

// A credential test whose evidence row was not stored (a newer test won).
const VIRTUSPHERE_AUDIT_CREDENTIAL_TEST_DISCARDED = 'discarded';

// Why the automatic ESXi inventory pull of a credential paused or resumed.
const VIRTUSPHERE_AUDIT_INVENTORY_AUTOMATION_REASON_CREDENTIAL_SAVED = 'credential saved';
const VIRTUSPHERE_AUDIT_INVENTORY_AUTOMATION_REASON_AUTH_FAILURE = 'authentication failure; save the credential to resume';

// The answer to a requested ESXi inventory pull (esxi_inventory_enqueue_for_credential()).
const VIRTUSPHERE_AUDIT_INVENTORY_REQUEST_QUEUED = 'queued';
const VIRTUSPHERE_AUDIT_INVENTORY_REQUEST_ALREADY_PENDING = 'already_pending';
const VIRTUSPHERE_AUDIT_INVENTORY_REQUEST_ERROR = 'error';
const VIRTUSPHERE_AUDIT_INVENTORY_REQUEST_AMBIGUOUS_ANSIBLE = 'ambiguous_ansible_credential';
const VIRTUSPHERE_AUDIT_INVENTORY_REQUEST_INVALID_ANSIBLE = 'invalid_ansible_credential';
const VIRTUSPHERE_AUDIT_INVENTORY_REQUEST_NO_ANSIBLE = 'no_ansible_credential';
const VIRTUSPHERE_AUDIT_INVENTORY_REQUEST_REASONS = [
    VIRTUSPHERE_AUDIT_INVENTORY_REQUEST_QUEUED,
    VIRTUSPHERE_AUDIT_INVENTORY_REQUEST_ALREADY_PENDING,
    VIRTUSPHERE_AUDIT_INVENTORY_REQUEST_ERROR,
    VIRTUSPHERE_AUDIT_INVENTORY_REQUEST_AMBIGUOUS_ANSIBLE,
    VIRTUSPHERE_AUDIT_INVENTORY_REQUEST_INVALID_ANSIBLE,
    VIRTUSPHERE_AUDIT_INVENTORY_REQUEST_NO_ANSIBLE,
];

// Why the convergence sweep marked VMs as failed.
const VIRTUSPHERE_AUDIT_CONVERGENCE_REASON_STUCK = 'stuck in deploying without an active deploy job';

// What a Windows hostname change did to the waiting rollout (Etappe 14D).
const VIRTUSPHERE_AUDIT_ROLLOUT_EFFECT_CURRENT_PENDING = 'current_pending';
const VIRTUSPHERE_AUDIT_ROLLOUT_EFFECT_NEXT_ROLLOUT = 'next_rollout';

// The states lib/status.php gives an integration source (virtusphere_heartbeat_state_rank(),
// virtusphere_integration_row_state()); the maintenance worker audits their transitions.
const VIRTUSPHERE_AUDIT_INTEGRATION_STATES = ['ok', 'legacy', 'warning', 'danger', 'missing', 'unknown', 'stale'];


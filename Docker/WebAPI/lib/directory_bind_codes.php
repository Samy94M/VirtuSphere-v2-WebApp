<?php

declare(strict_types=1);

require_once __DIR__ . '/directory_constants.php';

/**
 * AD answers a rejected simple bind with LDAP result 49 and a diagnostic such
 * as "80090308: LdapErr: DSID-..., comment: AcceptSecurityContext error,
 * data 52e, v4563". N1a A7/F5 take exactly one thing from it: the hex value
 * after "data ". The rest names DSIDs, DNs and directory internals; it is
 * never stored, logged, audited or shown. The meanings of the sub-codes are
 * secondary sources only (Broadcom KB, ldapwiki); Gate 0B checks them against
 * the target AD. Pure functions, no ext-ldap, so a unit test pins them.
 */
function directory_bind_code_from_diagnostic(string $diagnostic): string
{
    return preg_match('/(?:^|[\s,])data ([0-9a-f]{1,8})(?=[,\s]|$)/i', $diagnostic, $match) === 1
        ? strtolower($match[1])
        : '';
}

/** N1a A7: the typed outcome of a rejected search-account bind. */
function directory_service_rejection_outcome(string $bindCode): string
{
    return match ($bindCode) {
        '532', '773' => VIRTUSPHERE_DIRECTORY_OUTCOME_SERVICE_PASSWORD_EXPIRED,
        '533', '701' => VIRTUSPHERE_DIRECTORY_OUTCOME_SERVICE_ACCOUNT_DISABLED,
        '775' => VIRTUSPHERE_DIRECTORY_OUTCOME_SERVICE_ACCOUNT_LOCKED,
        default => VIRTUSPHERE_DIRECTORY_OUTCOME_SERVICE_BIND_REJECTED,
    };
}

/** N1a F5: the typed reason of a rejected user bind (audit only). */
function directory_user_bind_reason(string $bindCode): string
{
    return VIRTUSPHERE_DIRECTORY_BIND_CODE_REASONS[$bindCode] ?? VIRTUSPHERE_DIRECTORY_BIND_REASON_INVALID;
}

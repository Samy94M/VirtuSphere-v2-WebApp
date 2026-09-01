<?php

declare(strict_types=1);

require_once __DIR__ . '/esxi_datacenter_resolution.php';

const VIRTUSPHERE_ESXI_DATACENTER_RESOLUTIONS = [
    'resolved',
    'never_confirmed',
    'semantics_unverified',
    'answered_empty',
    'ambiguous',
    'unsupported',
    'timestamp_invalid',
    'expired',
];

/** Closed reason projection for every human-facing datacenter verdict. */
function esxi_datacenter_resolution_reason(array $resolution): string
{
    $reason = (string) ($resolution['resolution'] ?? 'never_confirmed');
    return in_array($reason, VIRTUSPHERE_ESXI_DATACENTER_RESOLUTIONS, true)
        ? $reason
        : 'never_confirmed';
}

/** @return array{code:string,message:string} */
function esxi_datacenter_compact_presentation(array $resolution): array
{
    $code = esxi_datacenter_blocker_code([
        'resolution' => esxi_datacenter_resolution_reason($resolution),
    ]);
    return [
        'code' => $code,
        'message' => __t('deploy.err_' . $code, [
            'hours' => intdiv(VIRTUSPHERE_ESXI_DATACENTER_DERIVATION_MAX_AGE_SECONDS, 3600),
        ]),
    ];
}

function esxi_datacenter_detail_message(array $resolution): string
{
    return __t(
        'system_status.inv_datacenter_resolution_' . esxi_datacenter_resolution_reason($resolution),
        ['hours' => intdiv(VIRTUSPHERE_ESXI_DATACENTER_DERIVATION_MAX_AGE_SECONDS, 3600)]
    );
}

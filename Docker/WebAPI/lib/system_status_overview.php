<?php

declare(strict_types=1);

// The overview cards of the System status page and the headline over them
// (SY2). Pure presenters over values the page has already read: every card
// carries the tone its badge renders (lib/status_vocabulary.php), the headline
// reads those tones, so the headline, the card and the section below say the
// same (docs/ai/reference/portal.md R58). The strip markup itself is
// system_status_render_overview() in lib/system_status_panels.php.
require_once __DIR__ . '/layout_presenters.php';
require_once __DIR__ . '/system_status.php';
require_once __DIR__ . '/system_status_urls.php';
require_once __DIR__ . '/system_status_backup_panels.php';
require_once __DIR__ . '/system_status_service_panel.php';
require_once __DIR__ . '/system_status_mecm_site_panel.php';

/**
 * The cards of the overview strip, in the order of the sections below them:
 * the deploy service, Active Directory when it is configured, MECM, Ansible,
 * ESXi, deviations, host and backup, internal services. A card never moves with
 * its state, so an operator finds an area at the same place every time; the
 * headline names the areas that need attention, worst first.
 *
 * The deploy service card reads the snapshot's compact badge, "Host und Backup"
 * the worst of system_status_host_checks(), the deviation card the one count
 * with system_status_deviation_evidence_current(), MECM the data flow and the
 * site as two rows.
 *
 * @param array<string,mixed> $snapshot
 * @param array<string,mixed>|null $serviceSnapshot
 * @param array<string,mixed>|null $directoryData
 * @param array<string, array<string,mixed>|null>|null $hostData from system_status_backup_panel_data()
 * @param array<string,mixed>|null $deviationReport the report the count came from
 * @return list<array{anchor: string, label: string, tone: string, html: string}>
 */
function system_status_overview_cards(array $snapshot, ?int $deviationCount = null, ?array $serviceSnapshot = null, ?array $directoryData = null, ?array $hostData = null, ?array $deviationReport = null): array
{
    $ansibleState = (string) ($snapshot['ansible']['state'] ?? 'unknown');
    $esxiState = (string) ($snapshot['esxi']['state'] ?? 'unknown');
    $internalState = (string) ($snapshot['internal']['state'] ?? 'unknown');
    $evidenceCurrent = system_status_deviation_evidence_current($deviationReport);

    $cards = [[
        'anchor' => VIRTUSPHERE_SYSTEM_STATUS_ANCHOR_DEPLOY_SERVICE,
        'label' => __t('system_status.overview_deploy_service'),
        'tone' => deploy_service_summary_tone($serviceSnapshot),
        'html' => deploy_service_summary_badge($serviceSnapshot),
    ]];
    if ($directoryData !== null) {
        $directoryState = (string) $directoryData['snapshot']['overall'];
        $cards[] = [
            'anchor' => VIRTUSPHERE_SYSTEM_STATUS_ANCHOR_DIRECTORY,
            'label' => __t('system_status.overview_directory'),
            'tone' => status_tone_for('directory', $directoryState),
            'html' => directory_state_badge($directoryState),
        ];
    }
    $cards[] = [
        'anchor' => VIRTUSPHERE_SYSTEM_STATUS_ANCHOR_MECM,
        'label' => __t('system_status.overview_mecm'),
        'tone' => system_status_mecm_overview_tone($snapshot),
        'html' => system_status_mecm_overview_badge($snapshot),
    ];
    $cards[] = [
        'anchor' => VIRTUSPHERE_SYSTEM_STATUS_ANCHOR_ANSIBLE,
        'label' => __t('system_status.overview_ansible'),
        'tone' => status_tone_for('ansible', $ansibleState),
        'html' => ansible_state_badge($ansibleState),
    ];
    $cards[] = [
        'anchor' => VIRTUSPHERE_SYSTEM_STATUS_ANCHOR_ESXI,
        'label' => __t('system_status.overview_esxi'),
        'tone' => status_tone_for('esxi', $esxiState),
        'html' => esxi_state_badge($esxiState),
    ];
    $cards[] = [
        'anchor' => VIRTUSPHERE_SYSTEM_STATUS_ANCHOR_DEVIATIONS,
        'label' => __t('system_status.overview_deviations'),
        'tone' => deviation_count_tone($deviationCount, $evidenceCurrent),
        'html' => deviation_count_badge($deviationCount, $evidenceCurrent),
    ];
    $cards[] = [
        'anchor' => VIRTUSPHERE_SYSTEM_STATUS_ANCHOR_HOST_BACKUP,
        'label' => __t('system_status.overview_host_backup'),
        'tone' => system_status_host_overview_tone($hostData ?? []),
        'html' => system_status_host_overview_badge($hostData ?? []),
    ];
    $cards[] = [
        'anchor' => VIRTUSPHERE_SYSTEM_STATUS_ANCHOR_INTERNAL,
        'label' => __t('system_status.overview_internal'),
        'tone' => status_tone_for('liveness', $internalState),
        'html' => heartbeat_badge($internalState, 'liveness'),
    ];

    return $cards;
}

/**
 * Is everything in order, and if not, where.
 *
 * Areas needing attention (warning, limited, critical, unreachable) come first,
 * worst first and in page order within one rank. Areas without current evidence
 * (grey) follow in their own list, because grey is never "in order" (R58) and
 * never a fault either. A switched-off area and a running job are in order.
 *
 * @param list<array{anchor: string, label: string, tone: string, html: string}> $cards
 * @return array{tone: string, attention: list<array{anchor: string, label: string, tone: string, html: string}>, no_evidence: list<array{anchor: string, label: string, tone: string, html: string}>}
 */
function system_status_overview_headline(array $cards): array
{
    $attention = [];
    $noEvidence = [];
    foreach ($cards as $position => $card) {
        $rank = status_tone_rank($card['tone']);
        if ($rank >= status_tone_rank('warning')) {
            $attention[] = ['rank' => $rank, 'position' => $position, 'card' => $card];
        } elseif ($rank > status_tone_rank('ok')) {
            $noEvidence[] = $card;
        }
    }
    usort($attention, static fn (array $a, array $b): int => [$b['rank'], $a['position']] <=> [$a['rank'], $b['position']]);
    $attention = array_map(static fn (array $entry): array => $entry['card'], $attention);

    return [
        'tone' => $attention[0]['tone'] ?? ($noEvidence[0]['tone'] ?? 'ok'),
        'attention' => $attention,
        'no_evidence' => $noEvidence,
    ];
}

/**
 * The headline as HTML: the only live region of the page (`role="status"`), so
 * a screen reader hears the one answer and not every badge. Each named area is a
 * link to its section, the links separated by commas, never by a bare space
 * (R30). The worst tone's icon leads the sentence like on a badge.
 *
 * @param list<array{anchor: string, label: string, tone: string, html: string}> $cards
 * @param array<string,int|string> $query the page's current selection
 */
function system_status_render_overview_headline(array $cards, array $query = []): string
{
    $headline = system_status_overview_headline($cards);
    $links = static fn (array $list): string => implode(', ', array_map(
        static fn (array $card): string => '<a href="' . h(system_status_url($card['anchor'], $query)) . '">' . h($card['label']) . '</a>',
        $list
    ));
    $sentences = [];
    if ($headline['attention'] !== []) {
        $count = count($headline['attention']);
        $sentences[] = h($count === 1
            ? __t('system_status_overview.headline_attention_one')
            : __t('system_status_overview.headline_attention_many', ['count' => $count])) . ' ' . $links($headline['attention']) . '.';
    }
    if ($headline['no_evidence'] !== []) {
        $count = count($headline['no_evidence']);
        $sentences[] = h($count === 1
            ? __t('system_status_overview.headline_no_evidence_one')
            : __t('system_status_overview.headline_no_evidence_many', ['count' => $count])) . ' ' . $links($headline['no_evidence']) . '.';
    }
    if ($sentences === []) {
        $sentences[] = h(__t('system_status_overview.headline_ok'));
    }
    $meta = status_tone_meta($headline['tone']);

    return '<p class="status-headline status-headline-' . h($meta['variant']) . '" role="status" data-status-headline="' . h($headline['tone']) . '">'
        . portal_badge_icon($meta['icon'])
        . '<span>' . implode(' ', $sentences) . '</span></p>';
}

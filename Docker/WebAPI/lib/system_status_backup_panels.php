<?php

declare(strict_types=1);

// The "Host and backup" section of the System status page (operations analysis
// B3/B6, SY1): last backup run, last proven restore drill, last checksum scrub
// and, only while nginx serves HTTPS, the certificate's expiry.
//
// It used to sit as a block inside "Internal services" without an overview card,
// so a failed backup or a checksum mismatch left the whole overview green (SY1
// K2). It is now an area of its own with its own card: the worst of its rows.
// It still does not feed the heartbeat state of "Internal services", the
// dashboard banner or health.php (ADR-0021 amendment).
//
// Every row has its own badge over its own facts, so no badge ever summarizes a
// fact another row shows. Missing evidence reads "unknown", never "OK". The
// badge of each row is computed once (system_status_host_checks()) and read by
// both the row and the overview card, so the two cannot disagree.
//
// The filename ends in `*panels.php` on purpose; see
// lib/system_status_shared_panels.php for the contracts that glob it.
require_once __DIR__ . '/backup_evidence.php';
require_once __DIR__ . '/backup_status.php';
require_once __DIR__ . '/https_config.php';
require_once __DIR__ . '/settings_page.php';
require_once __DIR__ . '/status.php';
require_once __DIR__ . '/system_status_shared_panels.php';

/**
 * Reads everything the section shows. Fail-soft per source: a broken reader
 * costs its own row's evidence (it reads unknown), never the page.
 *
 * @return array<string, array<string,mixed>|null> keys backup, drill, scrub, https
 */
function system_status_backup_panel_data(?int $now = null): array
{
    $now ??= time();

    return [
        'backup' => system_status_backup_read_source('backup', static fn (): array => backup_status_read(null, $now)),
        'drill' => system_status_backup_read_source('drill', static fn (): array => backup_drill_status_read(null, $now)),
        'scrub' => system_status_backup_read_source('scrub', static fn (): array => backup_scrub_status_read(null, $now)),
        'https' => system_status_backup_read_source('https', static fn (): ?array => https_listener_live() ? https_installed_metadata() : null),
    ];
}

/**
 * @param callable(): (array<string,mixed>|null) $reader
 * @return array<string,mixed>|null
 */
function system_status_backup_read_source(string $source, callable $reader): ?array
{
    try {
        return $reader();
    } catch (Throwable $exception) {
        error_log('[system-status] host state ' . $source . ' unavailable: ' . virtusphere_redact_log_text($exception->getMessage()));

        return null;
    }
}

/** "today", "1 day ago", "N days ago". */
function system_status_backup_age_label(int $days): string
{
    return match (true) {
        $days <= 0 => __t('system_status_backup.age_today'),
        $days === 1 => __t('system_status_backup.age_days_one', ['days' => $days]),
        default => __t('system_status_backup.age_days', ['days' => $days]),
    };
}

/**
 * @param array<string,mixed>|null $backup
 * @return array{variant: string, label: string}
 */
function system_status_backup_run_badge(?array $backup): array
{
    $state = (string) ($backup['state'] ?? VIRTUSPHERE_BACKUP_STATE_UNKNOWN);

    return ['variant' => backup_status_badge_class($state), 'label' => backup_status_label($state)];
}

/** @param array<string,mixed>|null $drill */
function system_status_backup_drill_known(?array $drill): bool
{
    $state = (string) ($drill['state'] ?? VIRTUSPHERE_BACKUP_EVIDENCE_UNKNOWN);

    return $state !== VIRTUSPHERE_BACKUP_EVIDENCE_UNKNOWN && isset($drill['ts'], $drill['age_days']);
}

/**
 * @param array<string,mixed>|null $drill
 * @return array{variant: string, label: string}
 */
function system_status_backup_drill_badge(?array $drill): array
{
    return match (true) {
        !system_status_backup_drill_known($drill) => ['variant' => 'neutral', 'label' => __t('system_status_backup.state_unknown')],
        ($drill['state'] ?? '') === VIRTUSPHERE_BACKUP_EVIDENCE_DUE => ['variant' => 'warning', 'label' => __t('system_status_backup.state_due')],
        default => ['variant' => 'success', 'label' => __t('system_status_backup.state_ok')],
    };
}

/** @param array<string,mixed>|null $scrub */
function system_status_backup_scrub_known(?array $scrub): bool
{
    $state = (string) ($scrub['state'] ?? VIRTUSPHERE_BACKUP_EVIDENCE_UNKNOWN);

    return $state !== VIRTUSPHERE_BACKUP_EVIDENCE_UNKNOWN && isset($scrub['ts']);
}

/**
 * @param array<string,mixed>|null $scrub
 * @return array{variant: string, label: string}
 */
function system_status_backup_scrub_badge(?array $scrub): array
{
    return match (true) {
        !system_status_backup_scrub_known($scrub) => ['variant' => 'neutral', 'label' => __t('system_status_backup.state_unknown')],
        ($scrub['state'] ?? '') === VIRTUSPHERE_BACKUP_EVIDENCE_FAILED => ['variant' => 'danger', 'label' => __t('system_status_backup.state_mismatch')],
        default => ['variant' => 'success', 'label' => __t('system_status_backup.state_ok')],
    };
}

/**
 * Expiry facts of a live certificate.
 *
 * @param array<string,mixed> $https
 * @return array{days: int, valid_to: int, expired: bool, expiring: bool}
 */
function system_status_backup_https_expiry(array $https): array
{
    $days = (int) ($https['days_remaining'] ?? 0);
    $validTo = (int) ($https['valid_to'] ?? 0);
    $expired = $validTo > 0 && $days < 0;

    return [
        'days' => $days,
        'valid_to' => $validTo,
        'expired' => $expired,
        'expiring' => !$expired && $days <= VIRTUSPHERE_HTTPS_CERT_EXPIRY_WARN_DAYS,
    ];
}

/**
 * @param array<string,mixed> $https certificate metadata of a live listener
 * @return array{variant: string, label: string}
 */
function system_status_backup_https_badge(array $https): array
{
    $expiry = system_status_backup_https_expiry($https);

    return match (true) {
        $expiry['valid_to'] <= 0 => ['variant' => 'neutral', 'label' => __t('system_status_backup.state_unknown')],
        $expiry['expired'] => ['variant' => 'danger', 'label' => __t('system_status_backup.state_expired')],
        $expiry['expiring'] => ['variant' => 'warning', 'label' => __t('system_status_backup.state_expiring')],
        default => ['variant' => 'success', 'label' => __t('system_status_backup.state_ok')],
    };
}

/**
 * The badge of every row the section renders, in row order. The certificate
 * row exists only while nginx serves HTTPS: on the documented HTTP-first setup
 * there is no certificate to expire, so it cannot colour the card either.
 *
 * @param array<string, array<string,mixed>|null> $data from system_status_backup_panel_data()
 * @return array<string, array{variant: string, label: string}> row kind => badge
 */
function system_status_host_checks(array $data): array
{
    $checks = [
        'backup' => system_status_backup_run_badge($data['backup'] ?? null),
        'restore-drill' => system_status_backup_drill_badge($data['drill'] ?? null),
        'backup-scrub' => system_status_backup_scrub_badge($data['scrub'] ?? null),
    ];
    $https = $data['https'] ?? null;
    if ($https !== null) {
        $checks['https-certificate'] = system_status_backup_https_badge($https);
    }

    return $checks;
}

/**
 * The overview card: the worst row, with that row's own word and colour.
 *
 * Ranked by virtusphere_heartbeat_state_rank(), the one roll-up order of the
 * page: red before yellow before grey before green. Grey ranks above green on
 * purpose, so a row without evidence keeps the card from reading OK. The first
 * row wins a tie, which is the order the section lists them in.
 *
 * @param array<string, array<string,mixed>|null> $data from system_status_backup_panel_data()
 */
function system_status_host_overview_badge(array $data): string
{
    $worst = null;
    $worstRank = -1;
    foreach (system_status_host_checks($data) as $badge) {
        $rank = virtusphere_heartbeat_state_rank(match ($badge['variant']) {
            'success' => 'ok',
            'warning' => 'warning',
            'danger' => 'danger',
            default => 'unknown',
        });
        if ($rank > $worstRank) {
            $worst = $badge;
            $worstRank = $rank;
        }
    }
    $worst ??= ['variant' => 'neutral', 'label' => __t('system_status_backup.state_unknown')];

    return portal_badge($worst['variant'], $worst['label']);
}

/**
 * One row: head with label and badge, a fixed fact list, and an optional
 * repair hint whose HTML the caller has already escaped.
 *
 * @param array{variant: string, label: string} $badge
 * @param list<array{label: string, html: string}> $facts
 */
function system_status_backup_row(string $kind, string $label, array $badge, array $facts, string $hintHtml): string
{
    $html = '<article class="status-row" data-host-state="' . h($kind) . '">'
        . '<div class="status-row-head"><strong>' . h($label) . '</strong>' . portal_badge($badge['variant'], $badge['label']) . '</div>'
        . system_status_fact_list($facts);
    if ($hintHtml !== '') {
        $html .= '<p class="status-action">' . $hintHtml . '</p>';
    }

    return $html . '</article>';
}

/**
 * @param array<string,mixed>|null $backup
 * @param array{variant: string, label: string} $badge
 */
function system_status_backup_run_row(?array $backup, array $badge, array $user): string
{
    $state = (string) ($backup['state'] ?? VIRTUSPHERE_BACKUP_STATE_UNKNOWN);
    $lastTs = isset($backup['last']['ts']) && is_numeric($backup['last']['ts']) ? (int) $backup['last']['ts'] : 0;
    $hint = '';
    if ($state !== VIRTUSPHERE_BACKUP_STATE_OK) {
        $hint = h(backup_status_message($state));
        if (can('system.config', $user)) {
            $hint .= ' <a href="' . h(settings_url('backup')) . '">' . h(__t('settings.backup_title')) . '</a>';
        }
    }

    return system_status_backup_row(
        'backup',
        __t('system_status_backup.row_backup'),
        $badge,
        [['label' => __t('system_status_backup.fact_last_run'), 'html' => $lastTs > 0 ? h(portal_format_epoch($lastTs)) : '&mdash;']],
        $hint
    );
}

/**
 * @param array<string,mixed>|null $drill
 * @param array{variant: string, label: string} $badge
 */
function system_status_backup_drill_row(?array $drill, array $badge): string
{
    $known = system_status_backup_drill_known($drill);
    $due = $known && ($drill['state'] ?? '') === VIRTUSPHERE_BACKUP_EVIDENCE_DUE;
    $stamp = (string) ($drill['backup_ts'] ?? '');
    $command = ' <code>' . h(VIRTUSPHERE_RESTORE_DRILL_RECORD_COMMAND) . '</code>';
    $hint = match (true) {
        !$known => h(__t('system_status_backup.drill_unknown_hint')) . $command,
        $due => h(__t('system_status_backup.drill_due_hint', ['days' => VIRTUSPHERE_RESTORE_DRILL_DUE_DAYS])) . $command,
        default => '',
    };

    return system_status_backup_row(
        'restore-drill',
        __t('system_status_backup.row_drill'),
        $badge,
        [
            [
                'label' => __t('system_status_backup.fact_drill_last'),
                'html' => $known
                    ? '<time title="' . h(portal_format_epoch((int) ($drill['ts'] ?? 0))) . '">' . h(system_status_backup_age_label((int) ($drill['age_days'] ?? 0))) . '</time>'
                    : h(__t('system_status_backup.state_unknown')),
            ],
            ['label' => __t('system_status_backup.fact_drill_backup'), 'html' => $known && $stamp !== '' ? '<code>' . h($stamp) . '</code>' : '&mdash;'],
        ],
        $hint
    );
}

/**
 * @param array<string,mixed>|null $scrub
 * @param array{variant: string, label: string} $badge
 */
function system_status_backup_scrub_row(?array $scrub, array $badge): string
{
    $known = system_status_backup_scrub_known($scrub);
    $failed = $known && ($scrub['state'] ?? '') === VIRTUSPHERE_BACKUP_EVIDENCE_FAILED;
    $failedSets = array_map('strval', (array) ($scrub['failed_sets'] ?? []));
    $hint = match (true) {
        !$known => h(__t('system_status_backup.scrub_unknown_hint')) . ' <code>' . h(VIRTUSPHERE_BACKUP_SCRUB_COMMAND) . '</code>',
        $failed => h(__t('system_status_backup.scrub_failed_hint', [
            'sets' => $failedSets !== [] ? implode(', ', $failedSets) : (string) ($scrub['sets_failed'] ?? ''),
        ])),
        default => '',
    };

    return system_status_backup_row(
        'backup-scrub',
        __t('system_status_backup.row_scrub'),
        $badge,
        [
            ['label' => __t('system_status_backup.fact_scrub_last'), 'html' => $known ? h(portal_format_epoch((int) ($scrub['ts'] ?? 0))) : '&mdash;'],
            [
                'label' => __t('system_status_backup.fact_scrub_result'),
                'html' => $known
                    ? h(__t('system_status_backup.scrub_result', ['checked' => (int) ($scrub['sets_checked'] ?? 0), 'failed' => (int) ($scrub['sets_failed'] ?? 0)]))
                    : '&mdash;',
            ],
        ],
        $hint
    );
}

/**
 * @param array<string,mixed> $https certificate metadata of a live listener
 * @param array{variant: string, label: string} $badge
 */
function system_status_backup_https_row(array $https, array $badge, array $user): string
{
    $expiry = system_status_backup_https_expiry($https);
    $hint = match (true) {
        $expiry['valid_to'] > 0 && $expiry['expired'] => h(__t('system_status_backup.https_expired_hint')),
        $expiry['valid_to'] > 0 && $expiry['expiring'] => h(__t('settings.https_meta_expires_soon', ['days' => max(0, $expiry['days'])])),
        default => '',
    };
    if ($hint !== '' && can('system.config', $user)) {
        $hint .= ' <a href="' . h(settings_url(VIRTUSPHERE_SETTINGS_TAB_HTTPS)) . '">' . h(__t('settings.https_title')) . '</a>';
    }

    return system_status_backup_row(
        'https-certificate',
        __t('system_status_backup.row_https'),
        $badge,
        [['label' => __t('system_status_backup.fact_https_valid_to'), 'html' => $expiry['valid_to'] > 0 ? h(portal_format_epoch($expiry['valid_to'])) : '&mdash;']],
        $hint
    );
}

/**
 * The section itself, with its own anchor, so the overview card jumps to it.
 *
 * @param array<string, array<string,mixed>|null> $data from system_status_backup_panel_data()
 */
function system_status_render_host_backup(array $data, array $user): void
{
    $checks = system_status_host_checks($data);
    $rows = system_status_backup_run_row($data['backup'] ?? null, $checks['backup'], $user)
        . system_status_backup_drill_row($data['drill'] ?? null, $checks['restore-drill'])
        . system_status_backup_scrub_row($data['scrub'] ?? null, $checks['backup-scrub']);
    $https = $data['https'] ?? null;
    if ($https !== null && isset($checks['https-certificate'])) {
        $rows .= system_status_backup_https_row($https, $checks['https-certificate'], $user);
    }
    ?>
    <section class="panel status-section" id="<?php echo h(VIRTUSPHERE_SYSTEM_STATUS_ANCHOR_HOST_BACKUP); ?>">
        <h2><?php echo h(__t('system_status_backup.heading')); ?></h2>
        <p class="muted"><?php echo h(__t('system_status_backup.hint')); ?></p>
        <div class="status-list"><?php echo $rows; ?></div>
    </section>
    <?php
}

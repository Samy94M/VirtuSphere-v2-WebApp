<?php

declare(strict_types=1);

// The host-state block of the System status page (operations analysis B3/B6,
// stage 1): last backup run, last proven restore drill, last checksum scrub and,
// only while nginx serves HTTPS, the certificate's expiry. It sits inside the
// "Internal services" section rather than being an area of its own: these are
// hints from the Docker host, not a service state, so they get no overview card
// and do not change the section's heartbeat state (ADR-0006).
//
// Every row has its own badge over its own facts, so no badge ever summarizes a
// fact another row shows. Missing evidence reads "unknown", never "OK".
//
// The filename ends in `*panels.php` on purpose; see
// lib/system_status_shared_panels.php for the contracts that glob it.
require_once __DIR__ . '/backup_evidence.php';
require_once __DIR__ . '/backup_status.php';
require_once __DIR__ . '/https_config.php';
require_once __DIR__ . '/settings_page.php';
require_once __DIR__ . '/system_status_shared_panels.php';

/**
 * Reads everything the block shows. Fail-soft per source: a broken reader
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
 * One row: head with label and badge, a fixed fact list, and an optional
 * repair hint whose HTML the caller has already escaped.
 *
 * @param list<array{label: string, html: string}> $facts
 */
function system_status_backup_row(string $kind, string $label, string $badgeHtml, array $facts, string $hintHtml): string
{
    $html = '<article class="status-row" data-host-state="' . h($kind) . '">'
        . '<div class="status-row-head"><strong>' . h($label) . '</strong>' . $badgeHtml . '</div>'
        . system_status_fact_list($facts);
    if ($hintHtml !== '') {
        $html .= '<p class="status-action">' . $hintHtml . '</p>';
    }

    return $html . '</article>';
}

/** @param array<string,mixed>|null $backup */
function system_status_backup_run_row(?array $backup, array $user): string
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
        portal_badge(backup_status_badge_class($state), backup_status_label($state)),
        [['label' => __t('system_status_backup.fact_last_run'), 'html' => $lastTs > 0 ? h(portal_format_epoch($lastTs)) : '&mdash;']],
        $hint
    );
}

/** @param array<string,mixed>|null $drill */
function system_status_backup_drill_row(?array $drill): string
{
    $state = (string) ($drill['state'] ?? VIRTUSPHERE_BACKUP_EVIDENCE_UNKNOWN);
    $known = $state !== VIRTUSPHERE_BACKUP_EVIDENCE_UNKNOWN && isset($drill['ts'], $drill['age_days']);
    $badge = match (true) {
        !$known => portal_badge('neutral', __t('system_status_backup.state_unknown')),
        $state === VIRTUSPHERE_BACKUP_EVIDENCE_DUE => portal_badge('warning', __t('system_status_backup.state_due')),
        default => portal_badge('success', __t('system_status_backup.state_ok')),
    };
    $stamp = (string) ($drill['backup_ts'] ?? '');
    $command = ' <code>' . h(VIRTUSPHERE_RESTORE_DRILL_RECORD_COMMAND) . '</code>';
    $hint = match (true) {
        !$known => h(__t('system_status_backup.drill_unknown_hint')) . $command,
        $state === VIRTUSPHERE_BACKUP_EVIDENCE_DUE => h(__t('system_status_backup.drill_due_hint', ['days' => VIRTUSPHERE_RESTORE_DRILL_DUE_DAYS])) . $command,
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
                    ? '<time title="' . h(portal_format_epoch((int) $drill['ts'])) . '">' . h(system_status_backup_age_label((int) $drill['age_days'])) . '</time>'
                    : h(__t('system_status_backup.state_unknown')),
            ],
            ['label' => __t('system_status_backup.fact_drill_backup'), 'html' => $known && $stamp !== '' ? '<code>' . h($stamp) . '</code>' : '&mdash;'],
        ],
        $hint
    );
}

/** @param array<string,mixed>|null $scrub */
function system_status_backup_scrub_row(?array $scrub): string
{
    $state = (string) ($scrub['state'] ?? VIRTUSPHERE_BACKUP_EVIDENCE_UNKNOWN);
    $known = $state !== VIRTUSPHERE_BACKUP_EVIDENCE_UNKNOWN && isset($scrub['ts']);
    $badge = match (true) {
        !$known => portal_badge('neutral', __t('system_status_backup.state_unknown')),
        $state === VIRTUSPHERE_BACKUP_EVIDENCE_FAILED => portal_badge('danger', __t('system_status_backup.state_mismatch')),
        default => portal_badge('success', __t('system_status_backup.state_ok')),
    };
    $failedSets = array_map('strval', (array) ($scrub['failed_sets'] ?? []));
    $hint = match (true) {
        !$known => h(__t('system_status_backup.scrub_unknown_hint')) . ' <code>' . h(VIRTUSPHERE_BACKUP_SCRUB_COMMAND) . '</code>',
        $state === VIRTUSPHERE_BACKUP_EVIDENCE_FAILED => h(__t('system_status_backup.scrub_failed_hint', [
            'sets' => $failedSets !== [] ? implode(', ', $failedSets) : (string) ($scrub['sets_failed'] ?? ''),
        ])),
        default => '',
    };

    return system_status_backup_row(
        'backup-scrub',
        __t('system_status_backup.row_scrub'),
        $badge,
        [
            ['label' => __t('system_status_backup.fact_scrub_last'), 'html' => $known ? h(portal_format_epoch((int) $scrub['ts'])) : '&mdash;'],
            [
                'label' => __t('system_status_backup.fact_scrub_result'),
                'html' => $known
                    ? h(__t('system_status_backup.scrub_result', ['checked' => (int) $scrub['sets_checked'], 'failed' => (int) $scrub['sets_failed']]))
                    : '&mdash;',
            ],
        ],
        $hint
    );
}

/** @param array<string,mixed> $https certificate metadata of a live listener */
function system_status_backup_https_row(array $https, array $user): string
{
    $days = (int) ($https['days_remaining'] ?? 0);
    $validTo = (int) ($https['valid_to'] ?? 0);
    $expired = $validTo > 0 && $days < 0;
    $expiring = !$expired && $days <= VIRTUSPHERE_HTTPS_CERT_EXPIRY_WARN_DAYS;
    $badge = match (true) {
        $validTo <= 0 => portal_badge('neutral', __t('system_status_backup.state_unknown')),
        $expired => portal_badge('danger', __t('system_status_backup.state_expired')),
        $expiring => portal_badge('warning', __t('system_status_backup.state_expiring')),
        default => portal_badge('success', __t('system_status_backup.state_ok')),
    };
    $hint = match (true) {
        $validTo > 0 && $expired => h(__t('system_status_backup.https_expired_hint')),
        $validTo > 0 && $expiring => h(__t('settings.https_meta_expires_soon', ['days' => max(0, $days)])),
        default => '',
    };
    if ($hint !== '' && can('system.config', $user)) {
        $hint .= ' <a href="' . h(settings_url(VIRTUSPHERE_SETTINGS_TAB_HTTPS)) . '">' . h(__t('settings.https_title')) . '</a>';
    }

    return system_status_backup_row(
        'https-certificate',
        __t('system_status_backup.row_https'),
        $badge,
        [['label' => __t('system_status_backup.fact_https_valid_to'), 'html' => $validTo > 0 ? h(portal_format_epoch($validTo)) : '&mdash;']],
        $hint
    );
}

/**
 * The block itself. The certificate row exists only while nginx serves HTTPS:
 * on the documented HTTP-first setup there is no certificate to expire.
 *
 * @param array<string, array<string,mixed>|null> $data from system_status_backup_panel_data()
 */
function system_status_render_backup_rows(array $data, array $user): void
{
    $rows = system_status_backup_run_row($data['backup'] ?? null, $user)
        . system_status_backup_drill_row($data['drill'] ?? null)
        . system_status_backup_scrub_row($data['scrub'] ?? null);
    $https = $data['https'] ?? null;
    if ($https !== null) {
        $rows .= system_status_backup_https_row($https, $user);
    }
    ?>
    <h3><?php echo h(__t('system_status_backup.heading')); ?></h3>
    <p class="muted"><?php echo h(__t('system_status_backup.hint')); ?></p>
    <div class="status-list"><?php echo $rows; ?></div>
    <?php
}

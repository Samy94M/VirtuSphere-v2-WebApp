# ADR-0024: The Backup Schedule is Reported by the Scheduler

Date: 2026-07-09
Status: Accepted

## Context

ADR-0021 gave the portal a backup *status* channel, but not a *schedule*. The card could say when the last run happened and nothing about the next one, and its staleness rule was a hardcoded 26 hours that silently assumed a daily cron. For rotating admin staff the page therefore answered "did it run" but never "where is this configured, when does it run again, and is it late".

Cron has no interface for "when do you next fire" (unlike `systemctl list-timers`, which prints `NextElapseUSecRealtime`). Two obvious fixes are both wrong:

- Store the schedule a second time, in a portal setting or a PHP constant. It then disagrees with the real crontab the first time someone edits one and not the other, and the portal states a falsehood with full confidence.
- Derive the next run as "last run + 24 h". That is a guess presented as a fact, it breaks for any non-daily schedule, and a manual run moves the displayed time.

## Decision

The component that owns the schedule reports it; the portal never keeps a second copy.

`scripts/backup.sh` determines, on every run, the schedule it was started under, in this order: an explicit `VIRTUSPHERE_BACKUP_SCHEDULE`; a systemd timer's own `NextElapseUSecRealtime` (an exact epoch); the cron file it actually runs from (`/etc/cron.d/virtusphere-backup`, honouring `CRON_TZ`); the invoking user's crontab. The result goes into the existing JSONL status line as `schedule`, `schedule_tz`, `schedule_source` and `next_ts`. Detection is best-effort and never fails a backup: unknown schedule means empty fields.

`lib/cron_schedule.php` is a dependency-free five-field cron evaluator. It understands `*`, steps, ranges, lists, month/weekday names, `7` as Sunday and the `@daily` family, applies cron's day-of-month/day-of-week OR rule, and returns null for everything else (`@reboot`, seconds fields, `L`, `?`, `#`) rather than a wrong date.

`lib/backup_status.php` derives the expected next run from that channel with the same precedence, falling back to `VIRTUSPHERE_BACKUP_INTERVAL_SECONDS` only when nothing was reported. Staleness is healthchecks.io semantics: overdue at *expected run plus `VIRTUSPHERE_BACKUP_GRACE_SECONDS`*, not at a fixed age. `VIRTUSPHERE_BACKUP_STALE_AFTER_SECONDS` is now derived from interval plus grace instead of being an independent magic number.

`scripts/install-backup-schedule.sh` writes the cron entry, so the schedule is installed in one place by one command and read back from that same file. There is no second source to drift from.

The portal presents the provenance rather than hiding it: the card names the schedule and the file it was read from, and when nothing was reported it says so and labels the interval fallback as an estimate. A missed run whose next occurrence cannot be derived shows no next run at all instead of inventing one.

## Consequences

- The backup card can be trusted: the schedule it shows is the schedule cron uses, because it was read out of the crontab at run time.
- Non-daily schedules work. A weekly backup is no longer permanently "stale".
- Status lines written before this ADR lack the new fields and degrade to the interval estimate, so no migration or backfill is needed.
- The evaluator is a small amount of calendar code the project now owns. It is deliberately conservative: unsupported syntax yields the estimate and a visible "not reported" hint, never a confident wrong time.
- ADR-0021 stands unchanged on the security boundary: the schedule travels through the same read-only metadata channel, and there is still no dump download and no web restore.

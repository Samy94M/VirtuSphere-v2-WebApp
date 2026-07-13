# ADR-0022: Portal Timezone and Deploy Scheduling

Date: 2026-07-09
Status: Accepted

## Context

Two related needs: operators wanted timestamps shown in their local timezone (not UTC), and they wanted to schedule deploys — run a whole mission at a set time, or boot VMs staggered a few minutes apart — instead of only "run now".

Preflight of the running stack: the PHP container runs in UTC (`date.timezone=UTC`) and MySQL's `SYSTEM` timezone is also UTC (`NOW() == UTC_TIMESTAMP()`), so all stored and displayed timestamps were already UTC.

## Decision

**Store and compare in UTC, convert only for display.** `db()` pins every session to `SET time_zone = '+00:00'` so `TIMESTAMP` reads and `NOW()`/`UTC_TIMESTAMP()` are identical regardless of the container's system timezone. A single display-timezone setting `portal_timezone` (default `Europe/Berlin`, chosen from `DateTimeZone::listIdentifiers()`) drives one formatter: `portal_format_datetime()` in `lib/portal_time.php` parses a UTC DB string and converts to the portal timezone. The existing SSoT formatter `portal_format_timestamp()` now delegates to it, so every existing call site follows automatically. We deliberately do **not** call `date_default_timezone_set()` to the portal zone, because that would make `strtotime()` misread the UTC DB strings; conversion is always explicit via `DateTimeImmutable`. The settings "time" card shows the server time with offset and warns (via a CSP-nonced JSON island read by `assets/core.js`) when the browser clock drifts more than two minutes from the server. The host, not the portal, sets the system clock (NTP).

**Scheduling lives on `deploy_jobs`** (migration 0011): nullable `scheduled_at DATETIME` (UTC) and `group_id CHAR(12)`, plus indexes `(status, scheduled_at)` and `(group_id)`. The worker claim query gains `AND (scheduled_at IS NULL OR scheduled_at <= UTC_TIMESTAMP())`, so a future-scheduled job simply is not claimed yet; unscheduled jobs behave exactly as before. A single Termin is one job with `scheduled_at`. **Staggering creates one job per VM** (`repo_enqueue_deploy_group`), sharing a `group_id`, each `scheduled_at = base + i × N minutes`; the single-active-per-mission guard is checked once for the whole group, ascending job id is the boot order. "Cancel group" stops only the still-queued slots; a running slot finishes. Input is parsed by `deploy_parse_schedule()` (unit-tested): the `datetime-local` value is portal-zone wall time converted to UTC, rejected on a past time (5-minute grace), the 30-day horizon (checked for the last staggered slot too), a DST spring-forward gap, or an out-of-range/mode-incompatible stagger (stagger only for the power-on modes full/powercycle/start). A preview/confirm step appears only when a schedule is set, so an immediate deploy stays a single click.

## Consequences

- **Displayed existing timestamps shift** from UTC to the portal zone (e.g. Europe/Berlin +1h/+2h). Nothing stored changes; only rendering. Called out in the changelog/update note.
- The reaper and health staleness stay heartbeat-based on `running` jobs, so queued/scheduled jobs never count as stuck (documented by `DeployJobReaperTest`).
- Deleting a mission with waiting scheduled jobs cascades them away; the mission list shows a stronger delete confirmation in that case.
- Timezone is display-only and must never drive auth, RBAC, deploy decisions or wire contracts (`.claude/rules/i18n.md`). Scheduling accuracy depends on the Docker-host/MySQL clock (host NTP), not on ESXi.

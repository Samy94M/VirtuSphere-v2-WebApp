<?php

declare(strict_types=1);

/**
 * The revision fence every mutating MECM callback passes (Etappe 14D, ADR-0043).
 *
 * The problem it solves is not theoretical. Before 14D a reset opened a fresh
 * queue entry, and the PREVIOUS rollout's `updateDevice` could still be in
 * flight; it arrived seconds later, wrote the OLD ResourceID, set the VM to
 * `registered` and took it straight back out of `getDeviceList`. The new rollout
 * then never happened, and nothing in the portal said why.
 *
 * So every mutating callback carries the rollout revision it believes it is
 * answering for, and the write only lands when that belief is current. The check
 * runs under the SAME row lock as the write, because a revision read before the
 * lock is a revision that may already be stale when the update executes.
 *
 * Answers are deliberately three, not two:
 *  - `accept`  the caller is current and may write.
 *  - `noop`    the caller is current AND the write is already there. Idempotent
 *              200, no second write, no second status event: a retried callback
 *              of the same rollout is a duplicate, not a conflict.
 *  - `stale`   missing, older, newer or contradicting. 409 without side effects.
 *
 * A FUTURE revision is refused as firmly as an old one. It cannot be a legal
 * caller: only this server hands revisions out, so a higher one means the caller
 * invented it or the row was restored from an older backup, and writing on that
 * belief would bind a ResourceID to a rollout that does not exist here.
 */

require_once __DIR__ . '/constants.php';

const VIRTUSPHERE_MECM_FENCE_ACCEPT = 'accept';
const VIRTUSPHERE_MECM_FENCE_NOOP = 'noop';
const VIRTUSPHERE_MECM_FENCE_STALE = 'stale';

/**
 * Pure decision, so it can be driven through every branch without a database.
 *
 * `$reported` is the revision the caller sent, or null when it sent none.
 * `$stored` is the row's current revision, `$storedBinding` what is already
 * written for the thing the caller wants to write (a ResourceID, an ACK marker),
 * `$incomingBinding` what it wants to write.
 *
 * The legacy branch is the one deliberate hole and it is fail-closed: a callback
 * with NO revision is accepted only for revision 1 with no tombstone, i.e. a VM
 * that has never been reset and whose desired name never moved while pending.
 * That is exactly the estate a site runs before the MECM script and client
 * packages are cut over. The moment anything reset or a pending snapshot moved,
 * the revision is above 1 and an un-upgraded caller gets 409 instead of writing
 * into the wrong rollout. Removing this branch after a proven cutover is its own
 * E3/ADR decision, not a cleanup.
 */
function mecm_rollout_fence_decide(?int $reported, ?int $stored, ?string $storedBinding, ?string $incomingBinding, ?string $tombstone): string
{
    $stored = $stored ?? VIRTUSPHERE_MECM_ROLLOUT_REVISION_INITIAL;
    $hasTombstone = $tombstone !== null && $tombstone !== '';

    if ($reported === null) {
        if ($stored !== VIRTUSPHERE_MECM_ROLLOUT_REVISION_INITIAL || $hasTombstone) {
            return VIRTUSPHERE_MECM_FENCE_STALE;
        }
    } elseif ($reported !== $stored) {
        return VIRTUSPHERE_MECM_FENCE_STALE;
    }

    // Current caller. Now the binding itself decides between a first write, a
    // duplicate and an attempt to overwrite an existing binding with a different
    // value. The last one is a conflict even from a current caller: two
    // ResourceIDs for one rollout means the sync found two devices, and picking
    // one here would make VirtuSphere the thing that chose wrong.
    if ($storedBinding === null || $storedBinding === '') {
        return VIRTUSPHERE_MECM_FENCE_ACCEPT;
    }
    if ($incomingBinding !== null && $storedBinding === $incomingBinding) {
        return VIRTUSPHERE_MECM_FENCE_NOOP;
    }

    return VIRTUSPHERE_MECM_FENCE_STALE;
}

/**
 * Reads the revision a callback reported. Absent and empty mean "no revision"
 * (the pre-cutover caller); anything present but not a positive integer is a
 * malformed body, which the endpoint answers with 400 rather than 409, because
 * 409 promises the caller that retrying after a resync could work.
 *
 * @throws InvalidArgumentException on a present but unusable value.
 */
function mecm_rollout_fence_reported_revision(mixed $raw): ?int
{
    if ($raw === null || $raw === '') {
        return null;
    }
    if (is_int($raw)) {
        $value = $raw;
    } elseif (is_string($raw) && preg_match('/^[0-9]{1,18}$/', $raw) === 1) {
        $value = (int) $raw;
    } else {
        throw new InvalidArgumentException('rollout_revision must be a positive integer.');
    }
    if ($value < 1) {
        throw new InvalidArgumentException('rollout_revision must be a positive integer.');
    }

    return $value;
}

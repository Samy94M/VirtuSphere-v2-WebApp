<?php

declare(strict_types=1);

require_once __DIR__ . '/esxi_inventory_deviations.php';
require_once __DIR__ . '/esxi_inventory_evidence.php';
require_once __DIR__ . '/status.php';

/**
 * Evidence-qualified inventory-union comparison. Every configured ESXi source
 * must have answered a kind with semantics v2 before absence from the union is
 * meaningful. Old complete evidence remains a historical diagnostic.
 *
 * @param list<array{credential:array<string,mixed>,state:?array<string,mixed>}> $summaries
 */
function esxi_inventory_deviation_report(
    mysqli $db,
    array $summaries,
    int $intervalHours,
    int $now,
    bool $includeTemplates = false
): array {
    $kinds = [
        VIRTUSPHERE_INVENTORY_KIND_DATACENTER,
        VIRTUSPHERE_INVENTORY_KIND_DATASTORE,
        VIRTUSPHERE_INVENTORY_KIND_NETWORK,
    ];
    $setsByCredential = repo_esxi_inventory_name_sets_by_credential($db, $kinds);
    $kindFacts = [];
    $qualifiedSets = array_fill_keys($kinds, []);
    $evaluatedKinds = array_fill_keys($kinds, false);
    $staleAfter = $intervalHours > 0
        ? VIRTUSPHERE_ESXI_INVENTORY_STALE_FACTOR * $intervalHours * 3600
        : 0;

    foreach ($kinds as $kind) {
        $qualified = $summaries !== [];
        $current = $summaries !== [];
        $lastObservedAt = null;
        foreach ($summaries as $summary) {
            $state = $summary['state'] ?? null;
            $semantics = json_decode((string) ($state['kind_name_semantics_json'] ?? ''), true);
            $observations = json_decode((string) ($state['kind_observation_json'] ?? ''), true);
            $freshness = json_decode((string) ($state['kind_freshness_json'] ?? ''), true);
            $semantics = is_array($semantics) ? $semantics : [];
            $observations = is_array($observations) ? $observations : [];
            $freshness = is_array($freshness) ? $freshness : [];
            $observation = is_array($observations[$kind] ?? null) ? $observations[$kind] : [];
            $attemptedAt = isset($observation['attempted_at']) ? (string) $observation['attempted_at'] : null;
            if ($attemptedAt !== null && ($lastObservedAt === null || strcmp($attemptedAt, $lastObservedAt) > 0)) {
                $lastObservedAt = $attemptedAt;
            }
            $freshAt = isset($freshness[$kind]) ? (string) $freshness[$kind] : null;
            $freshTs = virtusphere_evidence_timestamp($freshAt, $now);
            // A failed follow-up does not erase an older complete answer. It
            // does make that answer historical: only an answered latest
            // observation may contribute current negative evidence.
            if ((int) ($semantics[$kind] ?? 1) !== 2 || $freshTs === null) {
                $qualified = false;
                $current = false;
                continue;
            }
            if ((string) ($observation['outcome'] ?? '') !== 'answered'
                || ($staleAfter > 0 && ($now - $freshTs) > $staleAfter)
            ) {
                $current = false;
            }
            $credentialId = (int) ($summary['credential']['id'] ?? 0);
            foreach (($setsByCredential[$credentialId][$kind] ?? []) as $name => $_present) {
                if (esxi_object_name_classify_raw((string) $name)['supported']) {
                    $qualifiedSets[$kind][(string) $name] = true;
                }
            }
        }
        $evaluatedKinds[$kind] = $qualified;
        $kindFacts[$kind] = [
            'qualified' => $qualified,
            'current' => $qualified && $current,
            'historical' => $qualified && !$current,
            'last_observed_at' => $lastObservedAt,
            'name_count' => $qualified ? count($qualifiedSets[$kind]) : 0,
        ];
        if (!$qualified) {
            $qualifiedSets[$kind] = [];
        }
    }

    $deviations = esxi_inventory_mission_deviations(
        $db,
        $includeTemplates,
        $qualifiedSets,
        $evaluatedKinds
    );
    $qualifiedCount = count(array_filter($kindFacts, static fn (array $fact): bool => $fact['qualified']));
    $currentCount = count(array_filter($kindFacts, static fn (array $fact): bool => $fact['current']));

    return [
        'deviations' => $deviations,
        'kinds' => $kindFacts,
        // The renderer must distinguish "no configured source" from
        // "sources exist, but no kind is qualified across all of them".
        // This is presentation context only; it does not weaken the all-source
        // negative-evidence gate above.
        'source_count' => count($summaries),
        'has_evidence' => $qualifiedCount > 0,
        'fully_evaluable' => $qualifiedCount === count($kinds),
        'fully_current' => $currentCount === count($kinds),
        'historical' => $qualifiedCount > 0 && $currentCount < count($kinds),
        'total' => count($deviations),
    ];
}

<?php

declare(strict_types=1);

const VIRTUSPHERE_SYSTEM_STATUS_DEVIATIONS_PER_PAGE = 50;
const VIRTUSPHERE_SYSTEM_STATUS_DEVIATION_FILTERS = [
    'all', 'mission', 'template', 'vm', 'datacenter', 'datastore', 'vlan',
];

/** @param list<array<string,mixed>> $entries */
function system_status_deviation_view(array $entries, string $filter, string $query, int $page): array
{
    if (!in_array($filter, VIRTUSPHERE_SYSTEM_STATUS_DEVIATION_FILTERS, true)) {
        $filter = 'all';
    }
    $query = mb_substr(trim($query), 0, 100);
    $filtered = [];
    foreach ($entries as $entry) {
        $issues = (array) ($entry['issues'] ?? []);
        if (in_array($filter, ['datacenter', 'datastore', 'vlan'], true)) {
            $fields = match ($filter) {
                'datacenter' => ['datacenter', 'vm_datacenter'],
                'datastore' => ['datastore', 'vm_datastore'],
                default => ['vlan'],
            };
            $issues = array_values(array_filter(
                $issues,
                static fn (array $issue): bool => in_array((string) ($issue['field'] ?? ''), $fields, true)
            ));
        } elseif ($filter === 'mission' && (isset($entry['vm_id']) || !empty($entry['is_template']))) {
            continue;
        } elseif ($filter === 'template' && empty($entry['is_template'])) {
            continue;
        } elseif ($filter === 'vm' && !isset($entry['vm_id'])) {
            continue;
        }
        if ($issues === []) {
            continue;
        }
        $entry['issues'] = $issues;
        if ($query !== '') {
            $haystack = [(string) ($entry['mission_name'] ?? ''), (string) ($entry['vm_name'] ?? '')];
            foreach ($issues as $issue) {
                $haystack[] = (string) ($issue['value'] ?? '');
            }
            if (!str_contains(mb_strtolower(implode("\n", $haystack)), mb_strtolower($query))) {
                continue;
            }
        }
        $filtered[] = $entry;
    }
    usort($filtered, static function (array $a, array $b): int {
        return strcmp((string) ($a['mission_name'] ?? ''), (string) ($b['mission_name'] ?? ''))
            ?: ((int) ($a['mission_id'] ?? 0) <=> (int) ($b['mission_id'] ?? 0))
            ?: ((isset($a['vm_id']) ? 1 : 0) <=> (isset($b['vm_id']) ? 1 : 0))
            ?: strcmp((string) ($a['vm_name'] ?? ''), (string) ($b['vm_name'] ?? ''))
            ?: ((int) ($a['vm_id'] ?? 0) <=> (int) ($b['vm_id'] ?? 0));
    });

    $total = count($filtered);
    $pages = max(1, (int) ceil($total / VIRTUSPHERE_SYSTEM_STATUS_DEVIATIONS_PER_PAGE));
    $page = max(1, min($page, $pages));
    $offset = ($page - 1) * VIRTUSPHERE_SYSTEM_STATUS_DEVIATIONS_PER_PAGE;

    return [
        'entries' => array_slice($filtered, $offset, VIRTUSPHERE_SYSTEM_STATUS_DEVIATIONS_PER_PAGE),
        'total' => $total,
        'page' => $page,
        'pages' => $pages,
        'filter' => $filter,
        'query' => $query,
        'has_vlan' => count(array_filter($entries, static function (array $entry): bool {
            foreach ((array) ($entry['issues'] ?? []) as $issue) {
                if ((string) ($issue['field'] ?? '') === 'vlan') {
                    return true;
                }
            }
            return false;
        })) > 0,
    ];
}

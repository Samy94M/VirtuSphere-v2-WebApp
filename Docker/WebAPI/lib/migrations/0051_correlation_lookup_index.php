<?php

declare(strict_types=1);

/**
 * Lookup indexes for the correlation id (Etappe 15.10, ADR-0032).
 *
 * Etappe 15 turned the id from a column that is written into a column that is
 * SEARCHED: the audit table filters on it, its count does, and the deploy jobs
 * of one traced request are read by it. Measured on a small installation
 * (7 559 audit rows) before this migration:
 *
 *   SELECT COUNT(*) ... WHERE l.correlation_id = ?   type=ALL,   key=NULL, rows=7239
 *   SELECT ...        WHERE l.correlation_id = ?     type=index, key=PRIMARY,
 *                                                    "Backward index scan"
 *
 * The count is a full table scan, and the page query is a backwards walk of the
 * primary key that only stops once it has collected the LIMIT - which, for an id
 * that matches two rows out of seven thousand, means walking the whole table.
 * On a year of security rows under the long retention window that is the same
 * scan over two orders of magnitude more rows, on a page an operator opens
 * precisely while something is going wrong.
 *
 * After, on the same rows:
 *
 *   SELECT COUNT(*) ... WHERE l.correlation_id = ?   type=ref, key=deploy_logs_
 *                                                    correlation_lookup, rows=1
 *   SELECT ...        WHERE l.correlation_id = ?     type=ref, same key, rows=1
 *   SELECT ...        WHERE j.correlation_id = ?     type=ref, key=deploy_jobs_
 *                                                    correlation_lookup, rows=1
 *
 * Both indexes are (correlation_id, id), not correlation_id alone. Every reader
 * filters on the id and orders by the primary key descending, so the trailing
 * column lets the range be walked in the requested order and the LIMIT stop
 * where it should, instead of collecting matches and sorting them afterwards.
 *
 * Nothing else changes: no column, no default, no data. An index is invisible to
 * every wire contract, which is why this can be a plain additive migration.
 */
function migrate_0051_correlation_lookup_index(mysqli $db): void
{
    migrator_add_index(
        $db,
        'deploy_logs',
        'deploy_logs_correlation_lookup',
        'INDEX deploy_logs_correlation_lookup (correlation_id, id)'
    );

    migrator_add_index(
        $db,
        'deploy_jobs',
        'deploy_jobs_correlation_lookup',
        'INDEX deploy_jobs_correlation_lookup (correlation_id, id)'
    );
}

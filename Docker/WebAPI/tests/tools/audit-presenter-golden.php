<?php

declare(strict_types=1);

/**
 * CLI half of the audit-presenter golden: renders every case of
 * tests/fixtures/audit_presenter_cases.php through the REAL audit_event_description()
 * and prints {id: sentence} as JSON, sorted by id. AuditPresenterGoldenTest compares
 * the same sentences with tests/fixtures/audit_presenter_golden.json.
 *
 * Usage (working directory Docker/WebAPI, inside the PHP image):
 *   php tests/tools/audit-presenter-golden.php > tests/fixtures/audit_presenter_golden.json
 *
 * Needs no database. Exit codes: 0 printed; 1 presenter or encoder failure (a real
 * finding); 2 unusable environment (fixture missing, empty, or duplicate case ids).
 */

require_once dirname(__DIR__, 2) . '/lib/audit_presenter.php';

$fixturePath = dirname(__DIR__) . '/fixtures/audit_presenter_cases.php';
if (!is_file($fixturePath)) {
    fwrite(STDERR, "fixture missing: {$fixturePath}\n");
    exit(2);
}

$cases = require $fixturePath;
if (!is_array($cases) || $cases === []) {
    fwrite(STDERR, "fixture holds no cases: {$fixturePath}\n");
    exit(2);
}

$golden = [];
foreach ($cases as $case) {
    if (isset($golden[$case['id']])) {
        fwrite(STDERR, "duplicate case id: {$case['id']}\n");
        exit(2);
    }
    try {
        $golden[$case['id']] = audit_event_description(
            $case['event'],
            $case['object_type'],
            $case['object_id'],
            $case['result'],
            $case['context']
        );
    } catch (Throwable $e) {
        fwrite(STDERR, $case['id'] . ': presenter failed: ' . $e->getMessage() . "\n");
        exit(1);
    }
}

ksort($golden, SORT_STRING);
try {
    $json = json_encode($golden, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
} catch (JsonException $e) {
    fwrite(STDERR, 'golden is not encodable: ' . $e->getMessage() . "\n");
    exit(1);
}

fwrite(STDOUT, $json . "\n");
exit(0);

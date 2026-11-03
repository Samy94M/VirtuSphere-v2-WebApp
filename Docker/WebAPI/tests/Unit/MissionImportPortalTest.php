<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/lib/mission_import_portal.php';

/**
 * The hand-off status, the upload classification and the one diagnostic line.
 *
 * The bug this pins first: the GET preview answered a token MISMATCH by
 * unset()ting $_SESSION['mission_import'], so opening the stale link of upload A
 * destroyed the still valid preview of the newer upload B in the same session.
 * The status function is pure, so it cannot delete anything at all; the tests
 * below fix which status each situation produces, and the static contract pins
 * which of them the page is allowed to delete on.
 */
final class MissionImportPortalTest extends TestCase
{
    private const NOW = 1_800_000_000;

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function state(array $overrides = []): array
    {
        return array_merge([
            'token' => 'aabbccdd',
            'created' => self::NOW,
            'payload' => ['format_version' => 1],
            'suggested_name' => 'from_file',
        ], $overrides);
    }

    /**
     * @return array<string, array{0: int, 1: string}>
     */
    public static function ages(): array
    {
        return [
            'fresh' => [0, 'valid'],
            'one second before the limit' => [VIRTUSPHERE_MISSION_IMPORT_TTL_SECONDS - 1, 'valid'],
            'exactly at the limit' => [VIRTUSPHERE_MISSION_IMPORT_TTL_SECONDS, 'valid'],
            'one second past the limit' => [VIRTUSPHERE_MISSION_IMPORT_TTL_SECONDS + 1, 'expired'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('ages')]
    public function testTheTtlBoundaryIsInclusive(int $age, string $expected): void
    {
        $status = mission_import_handoff_status($this->state(), 'aabbccdd', self::NOW + $age);

        self::assertSame($expected, $status);
    }

    /**
     * @return array<string, array{0: mixed, 1: string, 2: string}>
     */
    public static function handOffSituations(): array
    {
        $good = ['token' => 'aabbccdd', 'created' => self::NOW, 'payload' => ['format_version' => 1]];

        return [
            'no state at all' => [null, 'aabbccdd', 'missing'],
            'state is not an array' => ['broken', 'aabbccdd', 'missing'],
            'another upload owns it' => [$good, 'ffffffff', 'mismatch'],
            'empty request token' => [$good, '', 'mismatch'],
            'token missing from the state' => [['created' => self::NOW, 'payload' => []], 'aabbccdd', 'invalid'],
            'token is not a string' => [['token' => ['x'], 'created' => self::NOW, 'payload' => []], 'aabbccdd', 'invalid'],
            'created is not an int' => [['token' => 'aabbccdd', 'created' => 'now', 'payload' => []], 'aabbccdd', 'invalid'],
            'payload missing' => [['token' => 'aabbccdd', 'created' => self::NOW], 'aabbccdd', 'invalid'],
            'payload is not an array' => [['token' => 'aabbccdd', 'created' => self::NOW, 'payload' => 'x'], 'aabbccdd', 'invalid'],
            'this link owns it' => [$good, 'aabbccdd', 'valid'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('handOffSituations')]
    public function testEverySituationHasItsOwnStatus(mixed $state, string $token, string $expected): void
    {
        self::assertSame($expected, mission_import_handoff_status($state, $token, self::NOW));
        self::assertContains($expected, VIRTUSPHERE_MISSION_IMPORT_HANDOFF_STATES);
    }

    /**
     * The function that used to be an unset(): reading a foreign token must
     * leave the state byte-for-byte alone.
     */
    public function testAMismatchDoesNotTouchTheState(): void
    {
        $state = $this->state();
        $before = $state;

        self::assertSame('mismatch', mission_import_handoff_status($state, 'ffffffff', self::NOW));
        self::assertSame($before, $state);
    }

    /**
     * @return array<string, array{0: array<string, mixed>|null, 1: bool}>
     */
    public static function disposableStates(): array
    {
        return [
            'nothing there' => [null, false],
            'fresh' => [['token' => 'aabbccdd', 'created' => self::NOW, 'payload' => []], false],
            'expired' => [['token' => 'aabbccdd', 'created' => self::NOW - VIRTUSPHERE_MISSION_IMPORT_TTL_SECONDS - 1, 'payload' => []], true],
            'structurally broken' => [['token' => 42, 'created' => self::NOW, 'payload' => []], true],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('disposableStates')]
    public function testOnlyADeadHandOffIsSweptSilently(?array $state, bool $expected): void
    {
        self::assertSame($expected, mission_import_handoff_is_disposable($state, self::NOW));
    }

    /**
     * Every PHP upload code, including one this PHP version does not define.
     * Folding all of them into "please choose a file" is the defect: an operator
     * whose file was too large was told they had picked nothing.
     *
     * @return array<string, array{0: int, 1: string}>
     */
    public static function uploadCodes(): array
    {
        return [
            'ok' => [UPLOAD_ERR_OK, 'ok'],
            'ini size' => [UPLOAD_ERR_INI_SIZE, 'too_large'],
            'form size' => [UPLOAD_ERR_FORM_SIZE, 'too_large'],
            'no file' => [UPLOAD_ERR_NO_FILE, 'no_file'],
            'partial' => [UPLOAD_ERR_PARTIAL, 'partial'],
            'no tmp dir' => [UPLOAD_ERR_NO_TMP_DIR, 'infrastructure'],
            'cannot write' => [UPLOAD_ERR_CANT_WRITE, 'infrastructure'],
            'stopped by extension' => [UPLOAD_ERR_EXTENSION, 'infrastructure'],
            'unknown code' => [4242, 'infrastructure'],
            'negative code' => [-1, 'infrastructure'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('uploadCodes')]
    public function testEveryUploadCodeHasItsOwnClass(int $code, string $expected): void
    {
        self::assertSame($expected, mission_import_upload_classification($code));
        self::assertContains($expected, VIRTUSPHERE_MISSION_IMPORT_UPLOAD_CLASSES);
    }

    /**
     * The four operator-facing upload answers must be four different sentences,
     * or the classification above buys nothing.
     */
    public function testTheUploadRejectionsAreDistinctLocalizedSentences(): void
    {
        $messages = [
            mission_import_upload_rejection(['error' => UPLOAD_ERR_NO_FILE, 'size' => 0]),
            mission_import_upload_rejection(['error' => UPLOAD_ERR_INI_SIZE, 'size' => 0]),
            mission_import_upload_rejection(['error' => UPLOAD_ERR_PARTIAL, 'size' => 0]),
            mission_import_upload_rejection(['error' => UPLOAD_ERR_OK, 'size' => 0]),
            mission_import_upload_rejection(['error' => UPLOAD_ERR_OK, 'size' => VIRTUSPHERE_MISSION_IMPORT_MAX_BYTES + 1]),
        ];

        foreach ($messages as $message) {
            self::assertIsString($message);
            self::assertNotSame('', $message);
            self::assertStringNotContainsString('missions.', (string) $message, 'an untranslated key reached the operator');
        }
        // The size classes deliberately share the "too large" sentence; the other
        // three must each be their own.
        self::assertSame($messages[1], $messages[4]);
        self::assertCount(4, array_unique($messages));
    }

    public function testAFileInsideTheLimitIsNotRejected(): void
    {
        self::assertNull(mission_import_upload_rejection([
            'error' => UPLOAD_ERR_OK,
            'size' => VIRTUSPHERE_MISSION_IMPORT_MAX_BYTES,
        ]));
    }

    /** A missing $_FILES entry is "no file", not a fault. */
    public function testAMissingFilesEntryIsTreatedAsNoFile(): void
    {
        $message = mission_import_upload_rejection(null);

        self::assertSame(__t('missions.import_err_no_file'), $message);
    }

    /**
     * The diagnostic line names the phase, the reference and the cause, and it
     * has no parameter a payload, a token, a file name or a temp path could
     * enter through - the sentinels below are passed to everything nearby and
     * must not turn up in the log.
     */
    public function testTheDiagnosticLineCarriesScopeReferenceAndCauseOnly(): void
    {
        ['log' => $log, 'references' => $references] = $this->captureErrorLog(static function (): array {
            return [
                mission_import_diagnose('preview', new RuntimeException('SECRETPAYLOAD in secret-file.json')),
                mission_import_diagnose('upload', null, UPLOAD_ERR_NO_TMP_DIR),
            ];
        });

        self::assertStringContainsString('scope=preview', $log);
        self::assertStringContainsString('scope=upload', $log);
        self::assertStringContainsString('cause=RuntimeException', $log);
        self::assertStringContainsString('cause=upload_error_' . UPLOAD_ERR_NO_TMP_DIR, $log);
        self::assertCount(2, $references);
        foreach ($references as $reference) {
            self::assertNotSame('', $reference);
            self::assertStringContainsString('ref=' . $reference, $log);
        }
        self::assertStringNotContainsString('SECRETPAYLOAD', $log, 'the exception message reached the log');
        self::assertStringNotContainsString('secret-file.json', $log, 'a file name reached the log');
        self::assertSame(2, substr_count($log, '[virtusphere:mission-import]'), 'one line per fault, no more');
    }

    /** A scope outside the closed list cannot invent a new label. */
    public function testAnUnknownScopeIsNormalized(): void
    {
        $log = $this->captureErrorLog(static function (): array {
            return [mission_import_diagnose('whatever-the-caller-typed', new LogicException('x'))];
        })['log'];

        self::assertStringContainsString('scope=unknown', $log);
        self::assertStringNotContainsString('whatever-the-caller-typed', $log);
    }

    /** An expected operator-side upload problem leaves no server-log line. */
    public function testExpectedUploadProblemsAreNotDiagnosed(): void
    {
        $log = $this->captureErrorLog(static function (): array {
            mission_import_upload_rejection(['error' => UPLOAD_ERR_NO_FILE, 'size' => 0]);
            mission_import_upload_rejection(['error' => UPLOAD_ERR_INI_SIZE, 'size' => 0]);
            mission_import_upload_rejection(['error' => UPLOAD_ERR_PARTIAL, 'size' => 0]);
            mission_import_upload_rejection(['error' => UPLOAD_ERR_OK, 'size' => VIRTUSPHERE_MISSION_IMPORT_MAX_BYTES + 1]);

            return [];
        })['log'];

        self::assertStringNotContainsString('[virtusphere:mission-import]', $log);

        // ... while the infrastructure class does write exactly one.
        $infrastructureLog = $this->captureErrorLog(static function (): array {
            mission_import_upload_rejection(['error' => UPLOAD_ERR_NO_TMP_DIR, 'size' => 0]);

            return [];
        })['log'];
        self::assertSame(1, substr_count($infrastructureLog, '[virtusphere:mission-import]'));
    }

    /**
     * Runs $body with error_log() redirected into a throwaway file and returns
     * what it wrote there, next to whatever references $body collected. There is
     * no other way to prove "this path writes exactly one line, and that line
     * carries nothing it should not".
     *
     * @param callable(): list<string> $body
     * @return array{log: string, references: list<string>}
     */
    private function captureErrorLog(callable $body): array
    {
        $path = tempnam(sys_get_temp_dir(), 'vs-import-log-');
        self::assertIsString($path);
        $previous = ini_get('error_log');
        ini_set('error_log', $path);
        try {
            $references = $body();
        } finally {
            ini_set('error_log', $previous === false ? '' : $previous);
        }
        $contents = (string) file_get_contents($path);
        unlink($path);

        return ['log' => $contents, 'references' => $references];
    }
}

<?php

declare(strict_types=1);

/**
 * Portal-side helpers for the mission import step (ADR-0021): the session
 * hand-off state, the PHP upload-error classification and the one safe
 * diagnostic line an unexpected fault is allowed to leave behind.
 *
 * Loaded by portal/missions.php and its tests only; nothing here knows about the
 * transfer document itself, which lib/mission_transfer_document.php owns.
 *
 * The first two functions are pure on purpose. The GET preview and the confirm
 * POST used to repeat the same token/TTL condition in two shapes, and the copy
 * in the GET path answered a token MISMATCH by deleting the session state - so
 * opening the stale link of upload A destroyed the still valid preview of the
 * newer upload B in the same session. A closed status set the two paths share
 * makes that class of drift impossible to reintroduce silently, and the pure
 * function cannot delete anything at all.
 */

require_once __DIR__ . '/mission_transfer.php';
require_once __DIR__ . '/errors.php';
require_once __DIR__ . '/format.php';
require_once __DIR__ . '/validate.php';

/**
 * Every answer the hand-off lookup can give.
 *
 * valid    - this link owns the current hand-off and it is inside its TTL
 * expired  - this link owns it, but it is older than the TTL
 * mismatch - a hand-off exists and belongs to a DIFFERENT upload
 * missing  - no hand-off in this session at all
 * invalid  - a hand-off exists but is not structurally usable
 */
const VIRTUSPHERE_MISSION_IMPORT_HANDOFF_STATES = ['valid', 'expired', 'mismatch', 'missing', 'invalid'];

/** Every answer the upload classification can give. */
const VIRTUSPHERE_MISSION_IMPORT_UPLOAD_CLASSES = ['ok', 'too_large', 'no_file', 'partial', 'infrastructure'];

/** The phases an import diagnostic line may name. */
const VIRTUSPHERE_MISSION_IMPORT_DIAGNOSTIC_SCOPES = ['upload', 'preview', 'confirm'];

/**
 * Classifies the session hand-off against the token a request carries.
 *
 * Pure: it reads the state, it never writes or deletes it. Only the caller acts,
 * and only `expired`, `invalid` and a successful import may remove anything -
 * `mismatch` and `missing` must leave the current hand-off alone, because the
 * request that produced them is about a DIFFERENT upload.
 *
 * The TTL boundary is inclusive on purpose: an age of exactly
 * VIRTUSPHERE_MISSION_IMPORT_TTL_SECONDS is still valid, one second more is not.
 *
 * @param mixed $state raw $_SESSION['mission_import'], untrusted
 */
function mission_import_handoff_status(mixed $state, string $requestToken, int $now): string
{
    if (!is_array($state)) {
        return 'missing';
    }

    $token = $state['token'] ?? null;
    $created = $state['created'] ?? null;
    // Structural check before the comparison: a half-written or foreign-shaped
    // state is not a mismatch (which would preserve it forever), it is garbage
    // the caller may clear.
    if (!is_string($token) || $token === '' || !is_int($created) || !isset($state['payload']) || !is_array($state['payload'])) {
        return 'invalid';
    }

    // An empty request token can never own a hand-off; hash_equals('x', '') is
    // false anyway, but saying so here keeps the intent readable.
    if ($requestToken === '' || !hash_equals($token, $requestToken)) {
        return 'mismatch';
    }

    return $now - $created > VIRTUSPHERE_MISSION_IMPORT_TTL_SECONDS ? 'expired' : 'valid';
}

/**
 * True when the CURRENT hand-off is provably unusable and may be dropped without
 * telling anyone.
 *
 * Used on every missions.php render, with no `?import=` token involved: an
 * operator who keeps working in the portal should not carry a dead 2 MB payload
 * in their session until the session GC runs. A closed tab that never sends
 * another request stays the GC's business, which is honest rather than a promise
 * this code could not keep.
 */
function mission_import_handoff_is_disposable(mixed $state, int $now): bool
{
    if (!is_array($state)) {
        return false;
    }

    $token = is_string($state['token'] ?? null) ? (string) $state['token'] : '';
    $status = mission_import_handoff_status($state, $token, $now);

    return $status === 'invalid' || $status === 'expired';
}

/**
 * Maps a PHP UPLOAD_ERR_* code onto what the operator has to be told.
 *
 * Every non-OK code used to fold into "please choose a file", which is a lie for
 * five of them: a file rejected by upload_max_filesize said the operator had
 * picked nothing, so they picked it again, and again. The three infrastructure
 * codes (no temp dir, cannot write, blocked by an extension) share one class
 * because they share one answer: nothing the operator does fixes them, so they
 * get a reference and a server-side diagnosis. An unknown integer joins them
 * rather than passing as "ok" - a code this PHP version invented later is not a
 * successful upload.
 */
function mission_import_upload_classification(int $uploadError): string
{
    return match ($uploadError) {
        UPLOAD_ERR_OK => 'ok',
        UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'too_large',
        UPLOAD_ERR_NO_FILE => 'no_file',
        UPLOAD_ERR_PARTIAL => 'partial',
        default => 'infrastructure',
    };
}

/**
 * The localized reason an upload cannot become a preview, or null when the file
 * is usable.
 *
 * Three answers that used to be one. `size <= 0` is separated from `size > max`
 * because a zero-byte file is neither too large nor unselected, and the app
 * limit is checked here in addition to the form's MAX_FILE_SIZE hint, which a
 * client can change or omit and which is therefore UX, never a boundary.
 *
 * @param mixed $file the $_FILES entry, untrusted
 */
function mission_import_upload_rejection(mixed $file): ?string
{
    $uploadError = is_array($file) ? (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) : UPLOAD_ERR_NO_FILE;
    $class = mission_import_upload_classification($uploadError);
    if ($class === 'infrastructure') {
        // Nothing the operator can do about a missing temp dir or a blocked
        // extension, so this one gets the reference and the server-side line.
        return validator_text(
            'missions.import_err_upload_infrastructure',
            'The upload could not be processed on the server (reference :reference).',
            ['reference' => mission_import_diagnose('upload', null, $uploadError)]
        );
    }
    if ($class === 'too_large') {
        return mission_import_too_large_message();
    }
    if ($class === 'partial') {
        return validator_text('missions.import_err_partial_upload', 'The file was only transferred in part. Please upload it again.');
    }
    if ($class !== 'ok') {
        return validator_text('missions.import_err_no_file', 'Please choose a mission file (JSON).');
    }

    $size = (int) ($file['size'] ?? 0);
    if ($size <= 0) {
        return validator_text('missions.import_err_empty', 'The selected file is empty.');
    }

    return $size > VIRTUSPHERE_MISSION_IMPORT_MAX_BYTES ? mission_import_too_large_message() : null;
}

/** The app limit named from its constant, never spelled out in the catalog. */
function mission_import_too_large_message(): string
{
    return validator_text('missions.import_err_too_large', 'The file is too large (limit :max).', [
        'max' => virtusphere_human_bytes(VIRTUSPHERE_MISSION_IMPORT_MAX_BYTES),
    ]);
}

/**
 * Reads the uploaded file into a hand-off payload, or into the one localized
 * sentence that says why it cannot become one.
 *
 * Only TOP-LEVEL readability decides here. Every finding deeper inside the
 * document (a broken sub-list, a VM entry that is not an object, a package
 * reference without a name) is exactly what the preview exists to show, so it
 * must not stop the hand-off from being created.
 *
 * @param mixed $file the $_FILES entry, untrusted
 * @return array{rejection: ?string, payload: array<string, mixed>, suggested_name: string}
 */
function mission_import_read_upload(mixed $file): array
{
    $rejected = static fn (string $message): array => ['rejection' => $message, 'payload' => [], 'suggested_name' => ''];

    $rejection = mission_import_upload_rejection($file);
    if ($rejection !== null) {
        return $rejected($rejection);
    }

    $raw = file_get_contents((string) $file['tmp_name']);
    if (!is_string($raw) || $raw === '') {
        // The size was positive a moment ago, so an empty read is the server's
        // problem, not the operator's: it gets the reference, like the other
        // infrastructure codes, instead of being reported as broken JSON.
        return $rejected(validator_text(
            'missions.import_err_upload_infrastructure',
            'The upload could not be processed on the server (reference :reference).',
            ['reference' => mission_import_diagnose('upload', null, UPLOAD_ERR_CANT_WRITE)]
        ));
    }

    $payload = json_decode($raw, true, VIRTUSPHERE_MISSION_IMPORT_JSON_DEPTH);
    if (json_last_error() === JSON_ERROR_DEPTH) {
        return $rejected(validator_text('missions.import_err_json_depth', 'The file is nested too deeply (at most :depth levels).', [
            'depth' => VIRTUSPHERE_MISSION_IMPORT_JSON_DEPTH,
        ]));
    }
    if (!is_array($payload)) {
        return $rejected(validator_text('missions.import_err_json', 'The file is not valid JSON.'));
    }

    $document = mission_transfer_document_analyze($payload);
    if ($document['document_error'] !== '') {
        return $rejected($document['document_error']);
    }

    return ['rejection' => null, 'payload' => $payload, 'suggested_name' => $document['suggested_name']];
}

/**
 * Records one unexpected import fault and returns the reference to show.
 *
 * Deliberately narrow: it takes a scope from a closed list and EITHER a
 * Throwable (whose class name it uses, never its message) OR an upload code.
 * There is no parameter a payload, a token, a file name or a temp path could
 * enter through, which is what makes "the import never logs file content" a
 * property of the signature rather than of caller discipline.
 *
 * One line, through error_log(), so it lands in the configured PHP error log.
 * Not repo_log_failure() and not an audit row: deploy_logs answers successful
 * functional changes and security events, and a second diagnostic channel for
 * the same fault means neither of them is complete.
 */
function mission_import_diagnose(string $scope, ?Throwable $exception = null, int $uploadError = 0): string
{
    $reference = virtusphere_error_reference();
    $safeScope = in_array($scope, VIRTUSPHERE_MISSION_IMPORT_DIAGNOSTIC_SCOPES, true) ? $scope : 'unknown';
    $cause = $exception !== null ? $exception::class : 'upload_error_' . $uploadError;

    error_log(sprintf('[virtusphere:mission-import] scope=%s ref=%s cause=%s', $safeScope, $reference, $cause));

    return $reference;
}

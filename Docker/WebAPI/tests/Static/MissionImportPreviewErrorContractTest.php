<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * The mission import preview must never fail silently.
 *
 * The reported bug was not a wrong message, it was no message: the GET-time
 * dry run caught every Throwable, dropped the session hand-off and returned,
 * so the page re-rendered the empty upload form. From the operator's side
 * "Preview" did nothing at all, twice in a row, with nothing in the log to
 * look at. The two branches that simply left $importPreview at null had the
 * same effect without an exception being involved.
 *
 * This pins the shape that fixes it, because nothing else can: a missing
 * flash_set() produces a page that renders perfectly and tells the operator
 * nothing, which no functional test notices.
 */
final class MissionImportPreviewErrorContractTest extends TestCase
{
    /** @return array<string, string> path => source, the page plus any module split out of it */
    private function pageSources(): array
    {
        $root = str_replace('\\', '/', dirname(__DIR__, 2));
        // Globbed, never a single named file: a helper split out of the page
        // later must stay inside this contract (portal rule).
        $paths = array_merge(
            [$root . '/portal/missions.php'],
            glob($root . '/lib/missions_*.php') ?: [],
            glob($root . '/lib/mission_import_*.php') ?: []
        );

        $sources = [];
        foreach ($paths as $path) {
            self::assertFileExists($path);
            $sources[$path] = (string) file_get_contents($path);
        }

        return $sources;
    }

    /**
     * The block that turns the ?import=<token> link into a preview, from the
     * token read to the first layout output.
     */
    private function handOffBlock(): string
    {
        $source = $this->pageSources()[str_replace('\\', '/', dirname(__DIR__, 2)) . '/portal/missions.php'];
        $start = strpos($source, "\$importToken = request_string(\$_GET, 'import');");
        self::assertNotFalse($start, 'the import hand-off block was not found; this test needs re-anchoring');
        $end = strpos($source, 'layout_header(', $start);
        self::assertNotFalse($end, 'no layout output follows the hand-off block; this test needs re-anchoring');

        return substr($source, $start, $end - $start);
    }

    /** The confirm POST branch, from its action test to the closing else-if. */
    private function confirmBlock(): string
    {
        $source = $this->pageSources()[str_replace('\\', '/', dirname(__DIR__, 2)) . '/portal/missions.php'];
        $start = strpos($source, "\$action === 'import_confirm'");
        self::assertNotFalse($start, 'the import confirm branch was not found; this test needs re-anchoring');
        $end = strpos($source, "\n    } catch (ValidationException", $start);
        self::assertNotFalse($end, 'the POST dispatch is not shaped as expected; this test needs re-anchoring');

        return substr($source, $start, $end - $start);
    }

    /**
     * The GET hand-off block cut into one segment per status it answers.
     *
     * The trailing else covers the two statuses that share one answer and is
     * keyed 'other'. Counting flashes over the whole block was not enough: a new
     * silent branch stays green behind three older flashes, so every status is
     * pinned to its own visible exit below.
     *
     * @return array<string, string>
     */
    private function handOffBranches(): array
    {
        $block = $this->handOffBlock();
        $cuts = [];
        if (preg_match_all("/\\\$status === '(\w+)'/", $block, $matches, PREG_OFFSET_CAPTURE)) {
            foreach ($matches[1] as $index => $capture) {
                $cuts[] = [$matches[0][$index][1], (string) $capture[0]];
            }
        }
        $trailingElse = strrpos($block, '} else {');
        self::assertNotFalse($trailingElse, 'the hand-off chain has no trailing else; this test needs re-anchoring');
        $cuts[] = [$trailingElse, 'other'];
        usort($cuts, static fn (array $a, array $b): int => $a[0] <=> $b[0]);

        $branches = [];
        foreach ($cuts as $index => [$offset, $key]) {
            $end = $cuts[$index + 1][0] ?? strlen($block);
            $branches[$key] = substr($block, $offset, $end - $offset);
        }

        return $branches;
    }

    public function testEveryHandOffBranchAnswersWithAFlash(): void
    {
        $block = $this->handOffBlock();

        // One flash per branch that ends without a preview: the dry run that
        // could not produce a report, the expired hand-off, and the link whose
        // hand-off is simply gone. Fewer means one of them fell silent again.
        self::assertGreaterThanOrEqual(
            3,
            substr_count($block, 'flash_set('),
            'a branch of the import hand-off ends without telling the operator anything'
        );
    }

    /**
     * Both import paths ask the SAME function what the hand-off is, instead of
     * spelling the token/TTL condition out twice. The copy in the GET path is
     * what answered a mismatch by deleting the other upload's preview.
     */
    public function testGetAndConfirmShareOneHandOffPredicate(): void
    {
        self::assertStringContainsString('mission_import_handoff_status(', $this->handOffBlock());
        self::assertStringContainsString('mission_import_handoff_status(', $this->confirmBlock());

        $page = $this->pageSources()[str_replace('\\', '/', dirname(__DIR__, 2)) . '/portal/missions.php'];
        self::assertSame(
            0,
            preg_match("/time\(\) - \(int\) \(\\\$state\['created'\]/", $page),
            'the TTL comparison is spelled out on the page again instead of coming from the shared predicate'
        );
    }

    /**
     * Every terminal status is pinned individually: a visible exit for each, and
     * a deletion only where this request OWNS the hand-off. `missing` and
     * `mismatch` share the trailing branch, and it must delete nothing at all -
     * that unset() was the bug that let an old link kill a newer upload.
     *
     * @return array<string, array{0: string, 1: bool}>
     */
    public static function handOffStatuses(): array
    {
        return [
            'expired' => ['expired', true],
            'invalid' => ['invalid', true],
            'missing or mismatch' => ['other', false],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('handOffStatuses')]
    public function testEachTerminalStatusHasItsOwnExitAndDeletionRule(string $key, bool $mayDelete): void
    {
        $branches = $this->handOffBranches();
        self::assertArrayHasKey($key, $branches, 'the hand-off chain no longer answers ' . $key);

        self::assertStringContainsString('flash_set(', $branches[$key], 'the ' . $key . ' branch ends silently');
        if ($mayDelete) {
            self::assertStringContainsString("unset(\$_SESSION['mission_import'])", $branches[$key]);

            return;
        }
        self::assertStringNotContainsString(
            "unset(\$_SESSION['mission_import'])",
            $branches[$key],
            'a request that does not own the hand-off deletes it: that is how the stale link of upload A destroyed the '
                . 'valid preview of upload B in the same session'
        );
    }

    /** The valid branch is the one that must produce a preview at all. */
    public function testTheValidBranchRendersThePreview(): void
    {
        $branches = $this->handOffBranches();
        self::assertArrayHasKey('valid', $branches);
        self::assertStringContainsString('$importPreview = [', $branches['valid']);
        self::assertStringContainsString('mission_import(', $branches['valid']);
    }

    /**
     * The two kinds of failure the import paths must keep apart.
     *
     * An expected, already localized document error renders its own sentence and
     * writes NOTHING to the server log; an unexpected Throwable gets exactly one
     * diagnostic line plus the generic reference message. Collapsing them either
     * floods the log with operator typos or hides a real fault behind a sentence
     * about the file.
     *
     * @return array<string, array{0: string}>
     */
    public static function importPaths(): array
    {
        return ['preview' => ['handOffBlock'], 'confirm' => ['confirmBlock']];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('importPaths')]
    public function testExpectedAndUnexpectedFailuresAreKeptApart(string $blockMethod): void
    {
        $block = $this->{$blockMethod}();

        $documentCatch = strpos($block, 'catch (MissionTransferDocumentException');
        self::assertNotFalse($documentCatch, 'the expected document failure is no longer caught separately');
        $documentBody = substr($block, $documentCatch, (int) strpos($block, 'catch (Throwable', $documentCatch) - $documentCatch);
        self::assertStringNotContainsString(
            'mission_import_diagnose(',
            $documentBody,
            'an unreadable file is an expected outcome and must not write a server diagnostic line'
        );
        self::assertStringContainsString('flash_set(', $documentBody);

        $faultCatch = strpos($block, 'catch (Throwable');
        self::assertNotFalse($faultCatch, 'the unexpected failure is no longer caught');
        $faultBody = substr($block, $faultCatch);
        self::assertStringContainsString('mission_import_diagnose(', $faultBody);
        self::assertStringContainsString('missions.import_err_unexpected', $faultBody);
    }

    /**
     * The diagnostic call sites hand over a scope and a Throwable or an upload
     * code - never a payload, a token, a file name, a temp path or an exception
     * message. The function signature cannot take those; this keeps the call
     * sites from routing them in through the scope string.
     */
    public function testTheDiagnosticCallSitesCarryNoContent(): void
    {
        $source = implode("\n", $this->pageSources());
        // The declaration itself is skipped; only call sites are the contract.
        $callSites = preg_match_all("/(?<!function )mission_import_diagnose\(([^)]*)\)/", $source, $matches);
        self::assertGreaterThan(0, $callSites, 'no import diagnostic is written any more; this test needs re-anchoring');
        foreach ($matches[1] as $arguments) {
            self::assertMatchesRegularExpression(
                "/^'(upload|preview|confirm)', (null, \\\$?\w+|\\\$\w+)$/",
                trim($arguments),
                'an import diagnostic call passes something other than a fixed scope plus a Throwable or an upload code'
            );
        }
    }

    /**
     * A confirm that fails must come back with the name the operator just typed,
     * not with the file's suggestion: the finding would otherwise talk about a
     * name the form no longer shows.
     */
    public function testTheTypedNameSurvivesAFailedConfirm(): void
    {
        $block = $this->confirmBlock();
        $update = strpos($block, "\$_SESSION['mission_import']['suggested_name'] = ");
        self::assertNotFalse($update, 'the confirm no longer stores the typed name');
        $import = strpos($block, 'mission_import($connection');
        self::assertNotFalse($import, 'the confirm no longer calls the importer; this test needs re-anchoring');
        self::assertLessThan($import, $update, 'the typed name is stored after the import, so a failure redirect loses it');
    }

    /**
     * Cancel stays a link. A POST action would need CSRF, a confirm
     * classification and a busy-button decision for something that only leaves
     * the view; the hand-off ages out with the TTL instead.
     */
    public function testCancelIsStillALinkAndNotAPostAction(): void
    {
        $source = implode("\n", $this->pageSources());

        self::assertStringNotContainsString('import_cancel', $source);
    }

    public function testNoCatchInTheHandOffSwallowsItsError(): void
    {
        $block = $this->handOffBlock();
        $offset = 0;
        $catches = 0;
        while (($position = strpos($block, 'catch (Throwable', $offset)) !== false) {
            $catches++;
            $offset = $position + 1;
            // The flash has to be inside the catch, and the catch bodies here are
            // short; anything further away is a different statement.
            $body = substr($block, $position, 900);
            $close = strpos($body, "\n        }");
            self::assertNotFalse($close, 'a catch in the import hand-off is not shaped as expected');
            self::assertStringContainsString(
                'flash_set(',
                substr($body, 0, $close),
                'a catch in the import hand-off swallows its error without a flash - that is the bug this contract exists for'
            );
        }
        self::assertGreaterThan(0, $catches, 'the dry run is no longer guarded; this test needs re-anchoring');
    }

    /**
     * The button must follow a predicate the report computed, not a copy the
     * renderer keeps. The copy had already drifted off the write path once.
     *
     * It must be blocked_in_file specifically. Wiring the button to the full
     * `blocked` looks stricter and is worse: a name problem sets it, the field
     * that fixes the name sits directly under the message, and the disabled
     * button then leaves the operator reading an instruction they cannot carry
     * out. The write keeps refusing on the full predicate either way.
     */
    public function testConfirmButtonIsDisabledOnlyByFindingsTheFormCannotFix(): void
    {
        $source = implode("\n", $this->pageSources());

        // Read the variable the disabled attribute actually depends on, then
        // require THAT one to come from the report. Naming it here instead would
        // only pin a rename.
        self::assertSame(
            1,
            preg_match('/\$(\w+)\s*\?\s*\'disabled\'/', $source, $match),
            'the confirm button no longer has a disabled state; this test needs re-anchoring'
        );
        self::assertMatchesRegularExpression(
            '/\$' . $match[1] . '\s*=\s*(\(bool\)\s*)?\$report\[\'blocked_in_file\'\];/',
            $source,
            'the confirm button must be disabled by report[blocked_in_file] alone: re-deriving the predicate drifts '
                . 'off the write path, and using the full report[blocked] disables the button for a name problem the '
                . 'operator is being told to fix in the field right below it'
        );
    }

    /**
     * Both import steps do server work that takes visible time (a dry run walks
     * every VM against the DB, the confirm writes them), so both buttons say so.
     *
     * @return array<string, array{0: string}>
     */
    public static function importActions(): array
    {
        return ['preview' => ['import_preview'], 'confirm' => ['import_confirm']];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('importActions')]
    public function testImportSubmitButtonsShowAPendingState(string $action): void
    {
        $source = implode("\n", $this->pageSources());
        $start = strpos($source, 'name="action" value="' . $action . '"');
        self::assertNotFalse($start, 'the ' . $action . ' form was not found; this test needs re-anchoring');
        $end = strpos($source, '</form>', $start);
        self::assertNotFalse($end, 'the ' . $action . ' form is not closed');

        self::assertStringContainsString(
            'data-busy-label=',
            substr($source, $start, $end - $start),
            'the ' . $action . ' button gives no feedback while the server works'
        );
    }
}

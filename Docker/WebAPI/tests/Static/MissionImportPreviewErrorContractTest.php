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
            glob($root . '/lib/missions_*.php') ?: []
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

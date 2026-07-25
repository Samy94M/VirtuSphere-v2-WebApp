<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/lib/repo/missions.php';

/**
 * Every field the mission editor can save must re-render from the sticky stash
 * after a failed validation, not from the row it is about to overwrite.
 *
 * Nothing notices when one does not. The page renders, the flash names the
 * field that actually failed, and the *other* field the operator had just
 * changed quietly snaps back to its stored value: `wds_vlan` did exactly that
 * while name, datastore, datacenter, domain and notes went through form_old().
 * A lost edit that reports success is the worst shape this bug can take.
 *
 * The list is REPO_MISSION_EDITABLE_COLUMNS, the same SSoT the update path
 * writes through, so a new mission column fails this build until somebody
 * either renders it stickily or books it in NOT_IN_FORM with the reason.
 */
final class MissionFormStickyContractTest extends TestCase
{
    /**
     * Editable columns the mission form deliberately does not render, each with
     * the reason. Adding an entry is a decision, not a formality.
     *
     * @var array<string, string>
     */
    private const NOT_IN_FORM = [
        'mission_status' => 'set by the status actions on missions.php, never by this form',
    ];

    /** @return array<string, string> path => source, the page plus its own modules */
    private function missionEditorSources(): array
    {
        $root = str_replace('\\', '/', dirname(__DIR__, 2));
        // Globbed, never a single named file: a helper split out of the page
        // later must stay inside this contract (portal rule).
        $paths = array_merge(
            [$root . '/portal/mission_details.php'],
            glob($root . '/lib/mission_details*.php') ?: []
        );

        $sources = [];
        foreach ($paths as $path) {
            self::assertFileExists($path);
            $sources[$path] = (string) file_get_contents($path);
        }
        self::assertNotSame([], $sources, 'the mission editor page was not found');

        return $sources;
    }

    private function missionEditorSource(): string
    {
        return implode("\n", $this->missionEditorSources());
    }

    public function testEveryEditableColumnRendersThroughTheStickyStash(): void
    {
        $source = $this->missionEditorSource();
        $missing = [];
        foreach (REPO_MISSION_EDITABLE_COLUMNS as $column) {
            if (array_key_exists($column, self::NOT_IN_FORM)) {
                continue;
            }
            if (!str_contains($source, "form_old('update', '" . $column . "'")) {
                $missing[] = $column;
            }
        }

        sort($missing);
        self::assertSame(
            [],
            $missing,
            "a mission field renders its stored value instead of the posted one.\n"
            . "Read it through form_old('update', '<column>', <stored>), or declare it in NOT_IN_FORM with the reason."
        );
    }

    public function testTheNotInFormListHasNoStaleEntries(): void
    {
        foreach (self::NOT_IN_FORM as $column => $reason) {
            self::assertNotSame('', trim($reason), $column . ' must carry the reason it is not rendered');
            self::assertContains(
                $column,
                REPO_MISSION_EDITABLE_COLUMNS,
                'NOT_IN_FORM names a column the mission editor no longer knows: ' . $column
            );
            self::assertStringNotContainsString(
                "form_old('update', '" . $column . "'",
                $this->missionEditorSource(),
                'NOT_IN_FORM claims ' . $column . ' is not rendered, but the page renders it'
            );
        }
    }

    public function testTheFormReadsNothingButEditableColumnsFromTheStash(): void
    {
        preg_match_all("/form_old\('update',\s*'([^']+)'/", $this->missionEditorSource(), $matches);
        $unknown = array_values(array_unique(array_diff($matches[1], REPO_MISSION_EDITABLE_COLUMNS)));

        sort($unknown);
        self::assertSame(
            [],
            $unknown,
            'the mission form restores a field the update path cannot save; it would come back and be dropped'
        );
    }
}

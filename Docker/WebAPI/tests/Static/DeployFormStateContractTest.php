<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/lib/deploy_form_state.php';

/**
 * The deploy queue form is rendered on three paths (a mission change, the
 * schedule preview, a failed validation) and every field has to survive all
 * three. Nothing notices when one does not: the page still renders, the field
 * just quietly holds its default again, which is the bug this contract exists
 * for.
 *
 * Two agreements are pinned here, both invisible to php -l and to the runtime:
 *  - VIRTUSPHERE_DEPLOY_QUEUE_FIELDS lists the form's scalar controls, in both
 *    directions, so a new field fails the build until somebody decides whether
 *    it travels or belongs in NON_CARRIED with its reason,
 *  - the page reads every value through the deploy_form_* readers. A direct
 *    form_old('schedule', ...) reads the sticky stash only, so the field
 *    survives a failed validation and nothing else.
 */
final class DeployFormStateContractTest extends TestCase
{
    /**
     * Controls of the queue form that are not carried as scalar fields, each
     * with the reason. Adding an entry is a decision, not a formality.
     *
     * @var array<string, string>
     */
    private const NON_CARRIED = [
        'action' => 'the dispatch key the form sets itself, never a user value',
        'verbose' => 'a checkbox: its absence is its "off" value, so it is read and re-posted on its own',
        'vm_ids[]' => 'a selection bound to one mission; a mission change replaces the whole list',
    ];

    private function deployPage(): string
    {
        $path = str_replace('\\', '/', dirname(__DIR__, 2)) . '/portal/deploy.php';
        self::assertFileExists($path);

        return (string) file_get_contents($path);
    }

    /**
     * The queue form only. The page carries five more forms (the preview
     * confirm, the job filter, cancel, retry, cancel_group) whose fields are
     * not this contract's subject.
     */
    private function queueForm(): string
    {
        $matched = preg_match('#<form class="form-grid" method="post".*?</form>#s', $this->deployPage(), $matches);
        self::assertSame(1, $matched, 'the deploy queue form was not found; its opening tag changed');

        return $matches[0];
    }

    /** @return list<string> the literal name="..." controls of the queue form */
    private function queueFormControls(): array
    {
        // Literal names only: a name built in PHP (the preview's hidden inputs)
        // is not a control of this form.
        preg_match_all('/\bname="([^"<]+)"/', $this->queueForm(), $matches);
        $names = array_values(array_unique($matches[1]));
        self::assertNotSame([], $names, 'the queue form has no named controls; the slice or the markup changed');

        return $names;
    }

    public function testEveryQueueFormControlIsEitherCarriedOrDeclared(): void
    {
        $unclassified = [];
        foreach ($this->queueFormControls() as $name) {
            if (!in_array($name, VIRTUSPHERE_DEPLOY_QUEUE_FIELDS, true) && !array_key_exists($name, self::NON_CARRIED)) {
                $unclassified[] = $name;
            }
        }

        sort($unclassified);
        self::assertSame(
            [],
            $unclassified,
            "a queue-form field that neither travels nor is declared.\n"
            . 'Add it to VIRTUSPHERE_DEPLOY_QUEUE_FIELDS, or to NON_CARRIED with the reason it must not travel.'
        );
    }

    public function testEveryCarriedFieldIsStillAControlOfTheQueueForm(): void
    {
        $stale = array_values(array_diff(VIRTUSPHERE_DEPLOY_QUEUE_FIELDS, $this->queueFormControls()));

        sort($stale);
        self::assertSame([], $stale, 'VIRTUSPHERE_DEPLOY_QUEUE_FIELDS names a field the form no longer has; delete it');
    }

    public function testTheNonCarriedListHasNoStaleEntries(): void
    {
        $controls = $this->queueFormControls();
        foreach (self::NON_CARRIED as $name => $reason) {
            self::assertNotSame('', trim($reason), $name . ' must carry the reason it does not travel');
            self::assertContains($name, $controls, 'NON_CARRIED names a control the queue form no longer has: ' . $name);
        }
    }

    public function testThePreviewConfirmStepRepostsThatSameList(): void
    {
        // The confirm step re-posts the form without rendering it, so a field
        // missing there is lost between preview and enqueue, silently.
        self::assertStringContainsString(
            'foreach (VIRTUSPHERE_DEPLOY_QUEUE_FIELDS as $field)',
            $this->deployPage(),
            'the schedule preview must re-post the carried field list, not a hand-written copy of it'
        );
    }

    public function testThePageReadsItsValuesThroughTheDeployFormReaders(): void
    {
        $offenders = [];
        foreach (["form_old('schedule'", "form_old_array('schedule'", "form_has_state('schedule')"] as $direct) {
            if (str_contains($this->deployPage(), $direct)) {
                $offenders[] = $direct;
            }
        }

        self::assertSame(
            [],
            $offenders,
            "deploy.php reads the sticky stash directly.\n"
            . 'Use deploy_form_value()/deploy_form_vm_selection(), or the field survives a failed validation and nothing else.'
        );
    }
}

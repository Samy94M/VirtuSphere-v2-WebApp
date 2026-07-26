<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/lib/lang.php';
require_once dirname(__DIR__, 2) . '/lib/credentials_status.php';

/**
 * The cadence line under a credential's status badge. Every branch that stops
 * the ESXi scheduler must produce its own sentence: the line exists because the
 * badge-over-timestamp shape reads as "last poll", so a wrong cadence here is
 * the same defect in a new place.
 */
final class CredentialStatusCadenceTest extends TestCase
{
    private const HOURS = 6;

    protected function setUp(): void
    {
        Lang::load('de');
    }

    /** @param array<string, mixed> $overrides */
    private function esxiState(array $overrides = []): array
    {
        return $overrides + [
            'last_attempt_at' => '2026-07-24 12:00:00',
            'last_success_at' => '2026-07-24 12:00:00',
            'last_status' => 'ok',
            'failure_streak' => 0,
            'paused_until_credential_change' => 0,
        ];
    }

    public function testTheHealthyCaseNamesTheConfiguredInterval(): void
    {
        self::assertSame(
            __t('credentials.cadence_esxi', ['hours' => self::HOURS]),
            credential_cadence_esxi(self::HOURS, $this->esxiState(), true)
        );
    }

    public function testOneHourGetsItsOwnSentence(): void
    {
        // __t() does not pluralize; "alle 1 Stunden" is what a single catalog
        // entry with a placeholder would have produced.
        $text = credential_cadence_esxi(1, $this->esxiState(), true);
        self::assertSame(__t('credentials.cadence_esxi_one'), $text);
        self::assertStringNotContainsString('1 Stunden', $text);
    }

    public function testIntervalZeroSaysThereIsNoAutomaticPull(): void
    {
        self::assertSame(
            __t('credentials.cadence_esxi_off'),
            credential_cadence_esxi(0, $this->esxiState(), true)
        );
    }

    public function testAMissingAnsibleHostStopsEveryCredential(): void
    {
        // esxi_inventory_enqueue_for_credential() bails before creating a job
        // when the Ansible selection does not resolve, and never stamps an
        // attempt, so the row would otherwise promise a cycle that cannot run.
        self::assertSame(
            __t('credentials.cadence_esxi_no_ansible'),
            credential_cadence_esxi(self::HOURS, $this->esxiState(), false)
        );
    }

    public function testAPausedCredentialOutranksTheConfiguredInterval(): void
    {
        // esxi_inventory_enqueue_due() skips a paused credential entirely, so
        // the configured interval says nothing about this row any more.
        self::assertSame(
            __t('credentials.cadence_esxi_paused'),
            credential_cadence_esxi(self::HOURS, $this->esxiState(['paused_until_credential_change' => 1]), true)
        );
    }

    public function testADeadDeployWorkerStopsTheCycleForEveryCredential(): void
    {
        // The pull is a deploy JOB: the maintenance worker enqueues it, the deploy
        // worker runs it. With no worker the job sits `queued` forever, so a line
        // promising a cycle would be exactly the defect this line exists against.
        self::assertSame(
            __t('credentials.cadence_esxi_no_worker'),
            credential_cadence_esxi(self::HOURS, $this->esxiState(), true, false)
        );
    }

    public function testADeadWorkerDoesNotOutrankAGlobalSetting(): void
    {
        // Fix order: restarting the worker cannot start a cycle whose interval is
        // off or whose Ansible host is missing, so those two are named first.
        self::assertSame(
            __t('credentials.cadence_esxi_off'),
            credential_cadence_esxi(0, $this->esxiState(), true, false)
        );
        self::assertSame(
            __t('credentials.cadence_esxi_no_ansible'),
            credential_cadence_esxi(self::HOURS, $this->esxiState(), false, false)
        );
    }

    public function testADeadWorkerOutranksAPerCredentialPause(): void
    {
        // The other direction: un-pausing one credential does nothing while there
        // is no executor at all, so the worker is the one to fix first.
        self::assertSame(
            __t('credentials.cadence_esxi_no_worker'),
            credential_cadence_esxi(self::HOURS, $this->esxiState(['paused_until_credential_change' => 1]), true, false)
        );
    }

    public function testEveryBlockerTheSchedulerKnowsHasASentence(): void
    {
        // The SSoT is VIRTUSPHERE_ESXI_AUTOMATION_BLOCKERS, which the scheduler
        // skips on. A blocker added there without a sentence here would make the
        // exhaustive match throw on a live page; this walks the constant so it
        // breaks in CI instead. Reaching each one through the real predicate,
        // not by calling the match, keeps this honest.
        $reached = [
            credential_cadence_esxi(0, $this->esxiState(), true),
            credential_cadence_esxi(self::HOURS, $this->esxiState(), false),
            credential_cadence_esxi(self::HOURS, $this->esxiState(), true, false),
            credential_cadence_esxi(self::HOURS, $this->esxiState(['paused_until_credential_change' => 1]), true),
        ];
        self::assertCount(
            count(VIRTUSPHERE_ESXI_AUTOMATION_BLOCKERS),
            $reached,
            'a blocker was added to the SSoT without a case reaching it here'
        );
        self::assertSame($reached, array_values(array_unique($reached)), 'two blockers share a sentence');
    }

    public function testThePredicateAndTheSentenceAgreeOnWhenNothingIsBlocked(): void
    {
        // The line may only promise a cycle when the scheduler would actually
        // run one. Both sides read the same predicate; this pins that they stay
        // the same call rather than two similar ones.
        $healthy = [self::HOURS, $this->esxiState(), true];
        self::assertNull(esxi_inventory_automation_blocker(...$healthy));
        self::assertSame(__t('credentials.cadence_esxi', ['hours' => self::HOURS]), credential_cadence_esxi(...$healthy));

        $blocked = [self::HOURS, $this->esxiState(['paused_until_credential_change' => 1]), true];
        self::assertNotNull(esxi_inventory_automation_blocker(...$blocked));
        self::assertNotSame(__t('credentials.cadence_esxi', ['hours' => self::HOURS]), credential_cadence_esxi(...$blocked));
    }

    public function testTheBlockersAreNamedInTheOrderTheyMustBeFixed(): void
    {
        // All three at once. The global switch is named first: saying "paused"
        // while the interval is 0 would promise that un-pausing restores the
        // cycle, and saying "no Ansible host" would promise the same of a
        // selection. Each answer must be the next thing an operator can fix.
        $paused = $this->esxiState(['paused_until_credential_change' => 1]);
        self::assertSame(__t('credentials.cadence_esxi_off'), credential_cadence_esxi(0, $paused, false));
        self::assertSame(__t('credentials.cadence_esxi_no_ansible'), credential_cadence_esxi(self::HOURS, $paused, false));
        self::assertSame(__t('credentials.cadence_esxi_paused'), credential_cadence_esxi(self::HOURS, $paused, true));
    }

    public function testNeverPulledIsNotPaused(): void
    {
        // A credential without a state row has simply not run yet; the
        // scheduler will pick it up on the next cycle.
        self::assertSame(
            __t('credentials.cadence_esxi', ['hours' => self::HOURS]),
            credential_cadence_esxi(self::HOURS, null, true)
        );
    }

    public function testAnsibleSaysOnClickAndNamesTheExpiryWindow(): void
    {
        $text = credential_cadence_ansible();
        self::assertSame(
            __t('credentials.cadence_manual', ['days' => VIRTUSPHERE_ANSIBLE_PREFLIGHT_STALE_AFTER_DAYS]),
            $text
        );
        self::assertStringContainsString((string) VIRTUSPHERE_ANSIBLE_PREFLIGHT_STALE_AFTER_DAYS, $text);
    }

    public function testEveryBranchIsTranslatedInBothLocales(): void
    {
        foreach (['de', 'en'] as $locale) {
            Lang::load($locale);
            $texts = [
                credential_cadence_esxi(self::HOURS, $this->esxiState(), true),
                credential_cadence_esxi(1, $this->esxiState(), true),
                credential_cadence_esxi(0, $this->esxiState(), true),
                credential_cadence_esxi(self::HOURS, $this->esxiState(), false),
                credential_cadence_esxi(self::HOURS, $this->esxiState(['paused_until_credential_change' => 1]), true),
                credential_cadence_ansible(),
            ];
            foreach ($texts as $text) {
                // A missing key returns the key itself, and an unpassed
                // placeholder survives as ":hours" in the rendered line.
                self::assertStringNotContainsString('credentials.', $text, $locale . ': untranslated key');
                self::assertDoesNotMatchRegularExpression('/:[a-z_]{3,}/', $text, $locale . ': unresolved placeholder in "' . $text . '"');
            }
            self::assertSame(count($texts), count(array_unique($texts)), $locale . ': two branches render the same sentence');
        }
    }
}

<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/lib/repo/deploy_job_queries.php';

/**
 * repo_open_system_job_condition() is the one WHERE fragment for an open
 * mission-less system job (ADR-0033), shared by the enqueue dedupe and the
 * portal's pending view. Its placeholders, type string and params must line up
 * one to one, or the bind fails at runtime instead of at review.
 */
final class OpenSystemJobConditionTest extends TestCase
{
    public function testOnePlaceholderAndOneStringTypePerActiveStatus(): void
    {
        $open = repo_open_system_job_condition();
        $active = VIRTUSPHERE_DEPLOY_JOB_ACTIVE_STATUSES;

        self::assertSame(str_repeat('s', count($active)), $open['types']);
        self::assertSame($active, $open['params'], 'the params are the active statuses in their constant order');
        $placeholders = substr_count($open['sql'], '?');
        self::assertSame(count($active), $placeholders);
        self::assertSame(strlen($open['types']), $placeholders, 'one bind type per placeholder');
        self::assertSame(count($open['params']), $placeholders, 'one bound value per placeholder');
    }

    public function testTheFragmentIsTheMissionLessNotCancelledPredicateWithoutLeadingOrTrailingKeyword(): void
    {
        $sql = repo_open_system_job_condition()['sql'];

        self::assertStringContainsString('mission_id IS NULL', $sql);
        self::assertStringContainsString('cancelled_at IS NULL', $sql);
        self::assertDoesNotMatchRegularExpression('/^\s*(?:AND|WHERE)\b|\b(?:AND|WHERE)\s*$/i', $sql, 'the caller adds its own AND/WHERE');
    }
}

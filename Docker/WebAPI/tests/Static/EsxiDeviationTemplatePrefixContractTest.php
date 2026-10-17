<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/lib/constants.php';

/**
 * The ESXi inventory deviation queries exclude templates with a static SQL
 * literal, `LEFT(mission_name, 1) <> '_'`, and not with a bound parameter: the
 * two query variants stay static on purpose (lib/esxi_inventory_deviations.php,
 * SD-05). A literal in SQL cannot follow the constant, so this guard does it:
 * every `LEFT(..., 1) <> '<x>'` literal in that file is derived by regex and
 * must equal VIRTUSPHERE_TEMPLATE_PREFIX, and the prefix must be exactly one
 * character long, because LEFT(..., 1) only compares one character.
 */
final class EsxiDeviationTemplatePrefixContractTest extends TestCase
{
    public function testEveryTemplateLiteralInTheDeviationQueriesIsTheTemplatePrefix(): void
    {
        $file = dirname(__DIR__, 2) . '/lib/esxi_inventory_deviations.php';
        $source = (string) file_get_contents($file);

        preg_match_all("/LEFT\\(\\s*[^,()]+,\\s*1\\s*\\)\\s*<>\\s*'([^']*)'/", $source, $matches);
        $literals = $matches[1];

        self::assertNotSame([], $literals, 'No LEFT(..., 1) <> literal found; the query shape moved, so this guard checks nothing.');

        $prefix = VIRTUSPHERE_TEMPLATE_PREFIX;
        self::assertSame(1, mb_strlen($prefix, 'UTF-8'), 'VIRTUSPHERE_TEMPLATE_PREFIX must be exactly one character for LEFT(..., 1).');

        foreach ($literals as $literal) {
            self::assertSame(
                $prefix,
                $literal,
                sprintf("A LEFT(..., 1) <> '%s' literal in esxi_inventory_deviations.php does not match VIRTUSPHERE_TEMPLATE_PREFIX.", $literal)
            );
        }
    }
}

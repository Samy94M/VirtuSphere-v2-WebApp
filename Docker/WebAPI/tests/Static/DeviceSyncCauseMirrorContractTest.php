<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/lib/device_sync_cause_constants.php';

/**
 * MX1 (FM-05) PHP side of the device-cause mirror. The device sync sends one of
 * `$script:VsDeviceCauseCodes` (Powershell-MECM/mecm/VirtuSphere-Common.ps1);
 * the portal accepts and words exactly VIRTUSPHERE_DEVICE_SYNC_CAUSES. The
 * Pester side (VirtuSphere.RunReport.Tests.ps1) reads the PHP list; this test
 * reads the PowerShell list, so a code added on either side alone fails on both.
 *
 * Every code also needs its words in both locales (`status.sync_cause_<code>`),
 * because the display never prints the token (R56). The language keys are
 * derived from the constant, not listed here.
 */
final class DeviceSyncCauseMirrorContractTest extends TestCase
{
    private const COMMON = 'Powershell-MECM/mecm/VirtuSphere-Common.ps1';

    public function testThePowerShellListEqualsThePortalListInTheSameOrder(): void
    {
        $root = dirname(__DIR__, 4);
        if (!is_file($root . '/' . self::COMMON)) {
            self::markTestSkipped('Repo root not visible; Powershell-MECM only exists outside the container mount.');
        }
        $source = str_replace("\r\n", "\n", (string) file_get_contents($root . '/' . self::COMMON));

        self::assertSame(1, substr_count($source, '$script:VsDeviceCauseCodes = @('), 'exactly one list');
        $matched = preg_match("/^\\\$script:VsDeviceCauseCodes = @\\(\\n((?:    '[a-z_]+'\\n)+)\\)$/m", $source, $match);
        self::assertSame(1, $matched, 'the list must stay one quoted code per line, as the mirror reads it');
        preg_match_all("/'([a-z_]+)'/", $match[1], $codes);

        self::assertSame(VIRTUSPHERE_DEVICE_SYNC_CAUSES, $codes[1]);
    }

    public function testEveryCodeHasItsWordsInBothLocales(): void
    {
        self::assertCount(23, VIRTUSPHERE_DEVICE_SYNC_CAUSES, 'the 23 device codes of the user decision; a change is a contract change');
        self::assertSame(VIRTUSPHERE_DEVICE_SYNC_CAUSES, array_values(array_unique(VIRTUSPHERE_DEVICE_SYNC_CAUSES)));

        $catalogs = [];
        foreach (['de', 'en'] as $locale) {
            $catalog = require dirname(__DIR__, 2) . '/lang/' . $locale . '/status.php';
            self::assertIsArray($catalog);
            $catalogs[$locale] = $catalog;
            foreach (VIRTUSPHERE_DEVICE_SYNC_CAUSES as $code) {
                $key = 'sync_cause_' . $code;
                self::assertArrayHasKey($key, $catalog, "{$locale}/status.php misses {$key}");
                self::assertNotSame('', trim((string) $catalog[$key]), "{$locale}: {$key} is empty");
                self::assertStringNotContainsString($code, (string) $catalog[$key], "{$locale}: {$key} repeats its token");
                self::assertStringNotContainsString(':', (string) $catalog[$key], "{$locale}: {$key} takes no placeholder");
            }
            $stray = array_diff(
                array_filter(array_keys($catalog), static fn (string $key): bool => str_starts_with($key, 'sync_cause_')),
                array_map(static fn (string $code): string => 'sync_cause_' . $code, VIRTUSPHERE_DEVICE_SYNC_CAUSES)
            );
            self::assertSame([], array_values($stray), "{$locale}: words for a code the portal does not accept");
        }
        self::assertNotSame($catalogs['de']['sync_cause_mac_missing'], $catalogs['en']['sync_cause_mac_missing'], 'both locales are translated');
    }
}

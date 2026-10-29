<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/lib/deploy_create_progress_findings.php';

/**
 * B9 (29.10.2026): every stored create error code has its own visible
 * sentence in both locales.
 *
 * Derived, not listed: the codes come from VIRTUSPHERE_CREATE_ERROR_CODES, the
 * one closed set the worker and the marker protocol accept. A code added there
 * without a sentence used to fall silently on "no closer classification";
 * vm_not_powered_off did, until this test. There is no exception list: a code
 * the portal cannot word is a code a person cannot act on.
 */
final class CreateErrorReasonContractTest extends TestCase
{
    public function testEveryCreateErrorCodeHasItsSentenceInBothLocales(): void
    {
        self::assertNotSame([], VIRTUSPHERE_CREATE_ERROR_CODES, 'the derivation must not come back empty');
        $catalogs = [];
        foreach (VIRTUSPHERE_CREATE_ERROR_CODES as $code) {
            self::assertArrayHasKey($code, VIRTUSPHERE_CREATE_ERROR_REASON_KEYS, $code . ' has no reason sentence');
            $key = VIRTUSPHERE_CREATE_ERROR_REASON_KEYS[$code];
            self::assertNotSame('deploy.create_progress_reason_unknown', $key, $code . ' points at the unknown fallback');
            [$module, $name] = explode('.', $key, 2);
            foreach (['de', 'en'] as $locale) {
                $catalogs[$locale][$module] ??= require dirname(__DIR__, 2) . '/lang/' . $locale . '/' . $module . '.php';
                self::assertIsString($catalogs[$locale][$module][$name] ?? null, $key . ' is missing in ' . $locale);
                self::assertNotSame('', trim($catalogs[$locale][$module][$name]), $key . ' is empty in ' . $locale);
            }
        }
        self::assertSame(
            [],
            array_values(array_diff(array_keys(VIRTUSPHERE_CREATE_ERROR_REASON_KEYS), VIRTUSPHERE_CREATE_ERROR_CODES)),
            'the reason map names a code the worker never stores'
        );
    }
}

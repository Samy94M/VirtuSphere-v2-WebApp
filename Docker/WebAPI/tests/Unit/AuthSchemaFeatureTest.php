<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/lib/auth_schema.php';

/**
 * C3: the database-free half of the try-first schema helpers. The fallback
 * itself runs against MySQL in tests/Integration/AuthSchemaFallbackTest.php.
 */
final class AuthSchemaFeatureTest extends TestCase
{
    public function testOnlyUnknownColumnAndUnknownTableCountAsAMissingName(): void
    {
        self::assertTrue(auth_schema_error_is_missing_name(new mysqli_sql_exception('unknown column', 1054)));
        self::assertTrue(auth_schema_error_is_missing_name(new mysqli_sql_exception('unknown table', 1146)));
        foreach ([0, 1045, 1142, 1205, 1213, 2006] as $code) {
            self::assertFalse(auth_schema_error_is_missing_name(new mysqli_sql_exception('other', $code)), (string) $code);
        }
    }

    public function testAFeatureIsATableOrATableColumn(): void
    {
        self::assertSame(['deploy_users', 'auth_source'], auth_schema_feature_parts(VIRTUSPHERE_SCHEMA_USER_SOURCE));
        self::assertSame(['deploy_ad_config', null], auth_schema_feature_parts(VIRTUSPHERE_SCHEMA_AD_CONFIG));
    }

    /** @return array<string, array{string}> */
    public static function invalidFeatures(): array
    {
        return [
            'empty' => [''],
            'quote' => ['deploy_users`; DROP TABLE deploy_users; --'],
            'two dots' => ['deploy_users.auth_source.x'],
            'upper case' => ['Deploy_users'],
            'space' => ['deploy_users. auth_source'],
            'trailing dot' => ['deploy_users.'],
        ];
    }

    #[DataProvider('invalidFeatures')]
    public function testAnythingElseIsRefusedBeforeItReachesSql(string $feature): void
    {
        $this->expectException(InvalidArgumentException::class);
        auth_schema_feature_parts($feature);
    }

    public function testTheHelpersNoLongerReadInformationSchema(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 2) . '/lib/auth_schema.php');
        self::assertStringNotContainsStringIgnoringCase('information_schema.', $source);
    }
}

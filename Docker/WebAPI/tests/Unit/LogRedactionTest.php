<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/lib/log_redaction.php';

/**
 * The token sentinel. Every log sink this project owns runs through
 * virtusphere_redact_log_text(), so these cases are the definition of what
 * "no secret reaches a log" means here.
 *
 * Each case carries a distinctive sentinel value and the assertion is that the
 * sentinel is gone, not that the output equals some exact string. An expected
 * string would let a rewrite of the redaction pass by matching its own new
 * output; the sentinel cannot be satisfied by anything except removal.
 */
final class LogRedactionTest extends TestCase
{
    private const SENTINEL = 'Sup3rSekritValue';

    /** @return array<string, array{0: string}> */
    public static function secretBearingSyntaxes(): array
    {
        $s = self::SENTINEL;

        return [
            'bearer header' => ['Authorization: Bearer eyJhbGciOiJIUzI1NiJ9.' . $s],
            'basic header' => ['Authorization: Basic ' . base64_encode('user:' . $s)],
            'other scheme' => ['Authorization: Negotiate YIIZ' . $s],
            'lowercase header' => ['authorization: bearer ' . $s],
            'proxy header' => ['Proxy-Authorization: Basic ' . $s],
            'virtusphere token header' => ['X-VirtuSphere-Token: ' . $s],
            'legacy auth token header' => ['Auth-Token: ' . $s],
            'cookie header' => ['Cookie: PHPSESSID=' . $s . '; theme=dark'],
            'set-cookie header' => ['Set-Cookie: session=' . $s . '; HttpOnly'],
            'server superglobal spelling' => ['HTTP_AUTHORIZATION=Bearer ' . $s . ', HTTP_HOST=portal'],
            'query parameter' => ['GET /mecm-api.php?action=getDeviceList&token=' . $s . '&mac=aa HTTP/1.1'],
            'query password' => ['https://portal/login?user=bob&password=' . $s . '#top'],
            'query api key' => ['/x?api_key=' . $s],
            'url encoded query' => ['next=https%3A%2F%2Fh%2Fx%3Ftoken%3D' . $s . '%26a%3D1'],
            'url encoded token bytes' => ['next=https%3A%2F%2Fh%2Fx%3Ftoken%3Dabc%2F' . $s . '%2Bmore%3D%3D%26a%3D1'],
            'url encoded password' => ['body=user%3Dbob%26password%3D' . $s],
            'json object' => ['{"token": "' . $s . '", "ok": true}'],
            'json password' => ['{"user":"bob","password":"' . $s . '"}'],
            'form body' => ['user=bob&password=' . $s . '&remember=1'],
            'php array literal' => ['["api_key" => "' . $s . '", "host" => "esxi1"]'],
            'client secret' => ['client_secret: ' . $s],
            'private key field' => ['private_key=' . $s],
            'dsn in an exception' => ['PDOException: SQLSTATE[28000] mysql:host=db;password=' . $s . ';db=v'],
            'refresh token' => ['refresh_token=' . $s . '&grant_type=refresh'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('secretBearingSyntaxes')]
    public function testTheSentinelNeverSurvives(string $line): void
    {
        $redacted = virtusphere_redact_log_text($line);

        self::assertStringNotContainsString(self::SENTINEL, $redacted);
        self::assertStringContainsString(VIRTUSPHERE_REDACTED_MARK, $redacted, 'nothing was redacted at all');
    }

    /**
     * The Basic sentinel is base64, so "the sentinel is gone" would also be
     * true if the encoding merely hid it. Assert on the encoded form too.
     */
    public function testBasicCredentialsAreRemovedInTheirEncodedForm(): void
    {
        $encoded = base64_encode('user:' . self::SENTINEL);
        self::assertStringNotContainsString($encoded, virtusphere_redact_log_text('Authorization: Basic ' . $encoded));
    }

    /**
     * The scheme survives. "The client sent Basic where we expect Bearer" is
     * most of what an auth-failure investigation needs, and it is not a secret.
     */
    public function testTheAuthSchemeIsKept(): void
    {
        self::assertStringContainsString('Bearer', virtusphere_redact_log_text('Authorization: Bearer ' . self::SENTINEL));
        self::assertStringContainsString('Basic', virtusphere_redact_log_text('Authorization: Basic ' . self::SENTINEL));
    }

    /**
     * Redaction is idempotent and leaves no artefacts. The first version ran
     * two overlapping passes and produced `token=[redacted]]`, which is a small
     * thing until an operator greps for a marker that is not the one written.
     */
    public function testRedactionIsIdempotentAndLeavesNoArtefacts(): void
    {
        foreach (array_column(self::secretBearingSyntaxes(), 0) as $line) {
            $once = virtusphere_redact_log_text($line);
            self::assertSame($once, virtusphere_redact_log_text($once), 'redaction is not idempotent for: ' . $line);
            self::assertStringNotContainsString(VIRTUSPHERE_REDACTED_MARK . ']', $once);
            self::assertStringNotContainsString(
                VIRTUSPHERE_REDACTED_MARK . ' ' . VIRTUSPHERE_REDACTED_MARK,
                $once
            );
        }
    }

    /**
     * The neighbouring fields survive. A redactor that eats the rest of the
     * line removes the context that made the log line worth writing, and the
     * quiet result is that people stop logging instead of stop leaking.
     */
    public function testSurroundingFieldsAreNotSwallowed(): void
    {
        $json = virtusphere_redact_log_text('{"token":"' . self::SENTINEL . '","user":"bob","vm_id":7}');
        self::assertStringContainsString('"user":"bob"', $json);
        self::assertStringContainsString('"vm_id":7', $json);

        $query = virtusphere_redact_log_text('/mecm-api.php?action=getDeviceList&token=' . self::SENTINEL . '&mac=aa:bb');
        self::assertStringContainsString('action=getDeviceList', $query);
        self::assertStringContainsString('mac=aa:bb', $query);

        $encodedQuery = virtusphere_redact_log_text('next=https%3A%2F%2Fh%2Fx%3Ftoken%3Dabc%2F' . self::SENTINEL . '%2Bmore%3D%3D%26a%3D1');
        self::assertStringNotContainsString(self::SENTINEL, $encodedQuery);
        self::assertStringContainsString('%26a%3D1', $encodedQuery);

        $header = virtusphere_redact_log_text('Cookie: PHPSESSID=' . self::SENTINEL . '; theme=dark');
        self::assertStringContainsString('theme=dark', $header);
    }

    public function testTextWithoutASecretIsReturnedUnchanged(): void
    {
        $line = 'deploy job id 42 (mission id 7, mode full) succeeded';
        self::assertSame($line, virtusphere_redact_log_text($line));
        self::assertSame('', virtusphere_redact_log_text(''));
    }

    /** A word that merely contains a key name is not a key-value pair. */
    public function testAPlainSentenceMentioningATokenIsNotMangled(): void
    {
        $line = 'the report token is configured; no token value is stored in clear text';
        self::assertSame($line, virtusphere_redact_log_text($line));
    }

    public function testByteBoundNeverSplitsAMultibyteCharacter(): void
    {
        $text = str_repeat('ü', 10);

        for ($max = 1; $max <= strlen($text) + 2; $max++) {
            $cut = virtusphere_log_text_bytes($text, $max);
            self::assertLessThanOrEqual($max, strlen($cut));
            self::assertTrue(mb_check_encoding($cut, 'UTF-8'), 'cut at ' . $max . ' produced invalid UTF-8');
        }

        self::assertSame('', virtusphere_log_text_bytes($text, 0));
    }

    public function testInvalidUtf8IsRepairedRatherThanStored(): void
    {
        $repaired = virtusphere_log_text_bytes("valid\xB1\x31invalid", 64);
        self::assertTrue(mb_check_encoding($repaired, 'UTF-8'));
    }
}

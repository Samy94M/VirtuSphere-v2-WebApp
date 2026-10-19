<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/lib/ssh_host_key_format.php';

/**
 * UX-HK: how the Ansible host key confirmation reads a typed or pasted value.
 *
 * The pin is written only from an out-of-band value. These rules decide which
 * OpenSSH key type that value belongs to and whether it deviates from what the
 * portal observed or pinned, so the page can ask a second time.
 */
final class AnsibleHostKeyFormatTest extends TestCase
{
    private const SHORT_TYPE_LINE = '%d %s root@ubuntu-102 (%s)';

    /** A canonical SHA256 fingerprint of a seed, the shape ssh-keygen prints. */
    private static function fp(string $seed): string
    {
        return 'SHA256:' . rtrim(base64_encode(hash('sha256', $seed, true)), '=');
    }

    /** @return array{fingerprint: string, type: string} */
    private static function identity(string $seed, string $type = 'ssh-ed25519'): array
    {
        return ['fingerprint' => self::fp($seed), 'type' => $type];
    }

    public function testTheBareFingerprintIsAcceptedWithoutATypeName(): void
    {
        $parsed = ssh_host_key_parse_input(self::fp('a'));
        self::assertSame(['fingerprint' => self::fp('a'), 'type' => null], $parsed);
    }

    public function testKeygenLinesForEveryAcceptedKeyTypeAreRecognised(): void
    {
        $cases = [
            ['256', 'ED25519', 'ssh-ed25519'],
            ['256', 'ECDSA', 'ecdsa-sha2-nistp256'],
            ['384', 'ECDSA', 'ecdsa-sha2-nistp384'],
            ['521', 'ECDSA', 'ecdsa-sha2-nistp521'],
            ['3072', 'RSA', 'ssh-rsa'],
        ];
        foreach ($cases as [$bits, $short, $openSsh]) {
            $line = sprintf(self::SHORT_TYPE_LINE, (int) $bits, self::fp('host-' . $short . $bits), $short);
            $parsed = ssh_host_key_parse_input($line);
            self::assertNotNull($parsed, $line);
            self::assertSame(self::fp('host-' . $short . $bits), $parsed['fingerprint']);
            self::assertSame($openSsh, $parsed['type'], $line);
        }
    }

    public function testExtraSpacesTabsAndAMissingCommentAreTolerated(): void
    {
        $fp = self::fp('spaces');
        $spaced = "  256 \t " . $fp . "    root@ubuntu-102   with words   (ED25519)  ";
        self::assertSame(['fingerprint' => $fp, 'type' => 'ssh-ed25519'], ssh_host_key_parse_input($spaced));
        $bare = '256  ' . $fp . '  (ED25519)';
        self::assertSame(['fingerprint' => $fp, 'type' => 'ssh-ed25519'], ssh_host_key_parse_input($bare));
    }

    public function testAWrongHashIsRejected(): void
    {
        $canonical = self::fp('hash');
        $shortened = substr($canonical, 0, -1);
        $md5Shape = 'MD5:' . bin2hex(hash('md5', 'hash', true));
        // Hex-looking text is valid base64 too; a character outside the alphabet is not.
        $outsideAlphabet = 'SHA256:' . str_repeat('a', 42) . '!';
        // The last base64 character of a 32-byte digest carries two padding bits; a
        // character that sets them is not the digest ssh-keygen prints.
        $nonCanonical = substr($canonical, 0, -1) . 'B';
        foreach ([$shortened, $md5Shape, $outsideAlphabet, $nonCanonical, ''] as $value) {
            self::assertNull(ssh_host_key_parse_input($value), 'bare: ' . $value);
            self::assertNull(ssh_host_key_parse_input('256 ' . $value . ' root@host (ED25519)'), 'line: ' . $value);
        }
        self::assertFalse(ssh_host_key_fingerprint_valid($nonCanonical));
        self::assertTrue(ssh_host_key_fingerprint_valid($canonical));
    }

    public function testAKeyTypeThePortalDoesNotAcceptIsRejected(): void
    {
        $fp = self::fp('unsupported');
        self::assertNull(ssh_host_key_parse_input('1024 ' . $fp . ' root@host (DSA)'));
        self::assertNull(ssh_host_key_parse_input('256 ' . $fp . ' root@host (ED25519-SK)'));
        self::assertNull(ssh_host_key_parse_input('192 ' . $fp . ' root@host (ECDSA)'), 'no 192-bit ECDSA curve is offered');
        self::assertNull(ssh_host_key_parse_input('512 ' . $fp . ' root@host (ED25519)'), 'ED25519 keys are 256 bits');
        self::assertNull(ssh_host_key_parse_input('not a fingerprint at all'));
    }

    public function testTheShortTypeNameMapsToTheOpenSshNameByBitCount(): void
    {
        self::assertSame('ssh-ed25519', ssh_host_key_type_from_keygen('ED25519', 256));
        self::assertSame('ecdsa-sha2-nistp384', ssh_host_key_type_from_keygen('ECDSA', 384));
        self::assertSame('ssh-rsa', ssh_host_key_type_from_keygen('RSA', 2048));
        self::assertNull(ssh_host_key_type_from_keygen('DSA', 1024));
    }

    public function testTheCommandPerKeyTypeNamesTheFileAndPrintsTheFingerprint(): void
    {
        $commands = ssh_host_key_commands();
        self::assertCount(3, $commands);
        self::assertSame(
            'ssh-keygen -l -E sha256 -f /etc/ssh/ssh_host_ed25519_key.pub',
            $commands[0]['command']
        );
        $files = array_column($commands, 'file');
        self::assertSame(['ssh_host_ed25519_key.pub', 'ssh_host_ecdsa_key.pub', 'ssh_host_rsa_key.pub'], $files);
        self::assertSame('ecdsa', ssh_host_key_family('ecdsa-sha2-nistp521'));
        self::assertSame('ed25519', ssh_host_key_family('ssh-ed25519'));
        self::assertNull(ssh_host_key_family('ssh-dss'));
    }

    public function testTheAnchorAndTheLinkToItAreOneContract(): void
    {
        self::assertSame('host-identity-7', credential_host_identity_anchor(7));
        self::assertSame('credentials.php#host-identity-7', credential_host_identity_url(7));
    }

    public function testAMatchingObservationTakesItsTypeWithoutASelect(): void
    {
        $observed = self::identity('a');
        $plan = ssh_host_key_confirmation_plan($observed['fingerprint'], '', $observed, null);
        self::assertSame(['ok' => true, 'fingerprint' => $observed['fingerprint'], 'type' => 'ssh-ed25519', 'deviates' => false], $plan);
    }

    public function testTheSelectMustAgreeWithATypeTheValueNames(): void
    {
        $observed = self::identity('a');
        $plan = ssh_host_key_confirmation_plan($observed['fingerprint'], 'ssh-rsa', $observed, null);
        self::assertSame(['ok' => false, 'error' => 'type_conflict'], $plan);
        $line = '256 ' . $observed['fingerprint'] . ' root@host (RSA)';
        self::assertSame(['ok' => false, 'error' => 'type_conflict'], ssh_host_key_confirmation_plan($line, '', $observed, null));
    }

    public function testAPreSelectionWithoutAnyObservationUsesTheOfferedType(): void
    {
        $plan = ssh_host_key_confirmation_plan(self::fp('advance'), 'ecdsa-sha2-nistp384', null, null);
        self::assertSame(['ok' => true, 'fingerprint' => self::fp('advance'), 'type' => 'ecdsa-sha2-nistp384', 'deviates' => false], $plan);
        self::assertSame(['ok' => false, 'error' => 'type_unknown'], ssh_host_key_confirmation_plan(self::fp('advance'), 'ssh-dss', null, null));
        self::assertSame(['ok' => false, 'error' => 'type_needed'], ssh_host_key_confirmation_plan(self::fp('advance'), '', null, null));
    }

    public function testABareFingerprintThatMatchesNoObservedKeyNeedsTheWholeLine(): void
    {
        $observed = self::identity('a');
        self::assertSame(
            ['ok' => false, 'error' => 'needs_line'],
            ssh_host_key_confirmation_plan(self::fp('b'), '', $observed, null)
        );
    }

    public function testAnEqualValueIsNoDeviation(): void
    {
        $observed = self::identity('a');
        $plan = ssh_host_key_confirmation_plan($observed['fingerprint'], '', $observed, $observed);
        self::assertTrue($plan['ok']);
        self::assertFalse($plan['deviates']);
    }

    public function testAValueThatDiffersFromThePinIsADeviationEvenIfTheHostShowsIt(): void
    {
        $pinned = self::identity('a');
        $other = ['fingerprint' => self::fp('b'), 'type' => 'ssh-rsa'];
        $line = '3072 ' . $other['fingerprint'] . ' root@host (RSA)';
        $plan = ssh_host_key_confirmation_plan($line, '', $other, $pinned);
        self::assertTrue($plan['ok']);
        self::assertTrue($plan['deviates'], 'a replaced pin needs the second step');
        self::assertSame('ssh-rsa', $plan['type']);
    }

    public function testKeepingThePinWhileTheHostShowsAnotherKeyIsNoDeviation(): void
    {
        // The mismatch is shown on the page; confirming the pin again changes nothing.
        $pinned = self::identity('a');
        $observed = self::identity('b', 'ssh-rsa');
        $plan = ssh_host_key_confirmation_plan($pinned['fingerprint'], '', $observed, $pinned);
        self::assertTrue($plan['ok']);
        self::assertFalse($plan['deviates']);
    }

    public function testWithoutAPinTheObservationIsTheAnchor(): void
    {
        $observed = self::identity('a');
        $other = self::identity('b', 'ssh-rsa');
        $line = '3072 ' . $other['fingerprint'] . ' root@host (RSA)';
        $plan = ssh_host_key_confirmation_plan($line, '', $observed, null);
        self::assertTrue($plan['ok']);
        self::assertTrue($plan['deviates']);
    }

    public function testAnUnobservedPinFromTheOperatorIsNeverADeviation(): void
    {
        $plan = ssh_host_key_confirmation_plan(self::fp('advance'), 'ssh-ed25519', null, null);
        self::assertFalse($plan['deviates']);
    }

    public function testTheLineSkipsTheSelectAndKeepsItsOwnType(): void
    {
        $plan = ssh_host_key_confirmation_plan('256 ' . self::fp('line') . ' root@host (ED25519)', '', null, null);
        self::assertSame(['ok' => true, 'fingerprint' => self::fp('line'), 'type' => 'ssh-ed25519', 'deviates' => false], $plan);
    }
}

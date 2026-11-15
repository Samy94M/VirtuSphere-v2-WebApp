<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/lib/repo/credentials.php';

/**
 * The credential-specific rules of credential_validate_payload() (QL4, L2).
 *
 * Until QL4 only the browser test `crud-credential.spec.js` ("create
 * validation") proved them; ValidatorRulesTest covers the generic Validator
 * rules alone. The browser test stays: it proves the field binding and that a
 * rejected secret is never echoed. This class proves the rules themselves:
 * which input is refused on which field, by which rule, before any database
 * access, and which boundary values pass through to the name lookup.
 * The ESXi certificate choice is EsxiTrustModeTest.
 */
final class CredentialValidatePayloadTest extends TestCase
{
    /**
     * @return iterable<string, array{0: array<string, mixed>, 1: ?string, 2: bool, 3: array<string, ?string>}>
     *         payload, secret, secret required, expected field => message key (null: any message)
     */
    public static function refusedPayloads(): iterable
    {
        $ansible = ['type' => 'ansible', 'name' => 'ansible-1', 'host' => 'ansible.invalid', 'username' => 'ansible'];
        $esxi = ['type' => 'esxi', 'name' => 'esxi-1', 'host' => 'esxi.invalid', 'username' => 'root', 'esxi_cert_kind' => 'none'];

        // The first submit of the browser test: six fields, each with its own error.
        yield 'everything empty, unknown type, port 0' => [
            ['type' => 'invalid', 'name' => '', 'host' => '', 'port' => '0', 'username' => ''],
            '',
            true,
            ['host' => 'validate.required', 'name' => 'validate.required', 'port' => 'validate.range', 'secret' => 'validate.secret_required', 'type' => 'validate.enum', 'username' => 'validate.required'],
        ];
        // The second submit: lengths over the column width, a URL as Ansible host, port above the range.
        yield 'ansible lengths, URL host and port 65536' => [
            ['type' => 'ansible', 'name' => str_repeat('n', 192), 'host' => 'https://ansible.invalid/path', 'port' => '65536', 'username' => str_repeat('u', 192)],
            'secret-for-validation',
            true,
            ['host' => 'validate.ansible_host_no_url', 'name' => 'validate.max_length', 'port' => 'validate.range', 'username' => 'validate.max_length'],
        ];
        yield 'secret required but blank' => [$ansible, '   ', true, ['secret' => 'validate.secret_required']];
        yield 'port not an integer' => [$ansible + ['port' => '22a'], 'secret', true, ['port' => 'validate.integer']];
        yield 'port negative' => [$ansible + ['port' => '-1'], 'secret', true, ['port' => 'validate.range']];
        yield 'host with whitespace' => [['host' => 'ansible .invalid'] + $ansible, 'secret', true, ['host' => 'validate.host_no_whitespace']];
        yield 'host with a control character' => [['host' => "ansible\t.invalid"] + $ansible, 'secret', true, ['host' => 'validate.host_no_whitespace']];
        yield 'host label starting with a hyphen' => [['host' => '-ansible.invalid'] + $ansible, 'secret', true, ['host' => 'validate.host_invalid']];
        yield 'host with an underscore' => [['host' => 'ansible_1.invalid'] + $ansible, 'secret', true, ['host' => 'validate.host_invalid']];
        yield 'ansible host with ssh scheme' => [['host' => 'ssh://ansible.invalid'] + $ansible, 'secret', true, ['host' => 'validate.ansible_host_no_url']];
        yield 'esxi URL with ftp scheme' => [['host' => 'ftp://esxi.invalid'] + $esxi, 'secret', true, ['host' => 'validate.esxi_url_scheme']];
        yield 'esxi URL with user info' => [['host' => 'https://root:pw@esxi.invalid'] + $esxi, 'secret', true, ['host' => 'validate.esxi_url_no_userinfo']];
        yield 'esxi URL with query' => [['host' => 'https://esxi.invalid?x=1'] + $esxi, 'secret', true, ['host' => 'validate.esxi_url_no_userinfo']];
        yield 'esxi URL with fragment' => [['host' => 'https://esxi.invalid#top'] + $esxi, 'secret', true, ['host' => 'validate.esxi_url_no_userinfo']];
        yield 'esxi URL with path' => [['host' => 'https://esxi.invalid/sdk'] + $esxi, 'secret', true, ['host' => 'validate.esxi_url_no_path']];
        yield 'esxi URL with port 0' => [['host' => 'https://esxi.invalid:0'] + $esxi, 'secret', true, ['host' => 'validate.esxi_url_port_range']];
        yield 'esxi URL without host' => [['host' => 'https://'] + $esxi, 'secret', true, ['host' => 'validate.esxi_url_host_missing']];
        // The host inside the URL is checked as a literal of its own, under its own label.
        yield 'esxi URL with an invalid host' => [['host' => 'https://esxi_1.invalid'] + $esxi, 'secret', true, ['host' => 'validate.host_invalid.url_host']];
        // Over 255 characters the length error comes first and the literal check
        // then replaces it on the same field (Validator keeps one message per field).
        yield 'host longer than 255 characters' => [['host' => str_repeat('a', 256)] + $ansible, 'secret', true, ['host' => 'validate.host_invalid']];
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<string, ?string> $expected
     */
    #[DataProvider('refusedPayloads')]
    public function testARefusedPayloadNamesExactlyTheFieldsAndRulesAndNeverReachesTheDatabase(array $payload, ?string $secret, bool $secretRequired, array $expected): void
    {
        $db = $this->createMock(mysqli::class);
        $db->expects(self::never())->method('prepare');

        try {
            credential_validate_payload($db, $payload, $secret, $secretRequired);
            self::fail('the payload was accepted');
        } catch (ValidationException $exception) {
            $errors = $exception->errors();
            ksort($errors);
            ksort($expected);
            self::assertSame(array_keys($expected), array_keys($errors), 'refused fields');
            foreach ($expected as $field => $key) {
                self::assertSame($this->messageOf($key, $field), $errors[$field], $field . ' is refused by ' . $key);
            }
        }
    }

    public function testTheBoundaryValuesPassAndReachTheNameLookupForTheirType(): void
    {
        $db = $this->database(null, 'SELECT id FROM deploy_credentials WHERE type = ? AND name = ? LIMIT 1');

        $values = credential_validate_payload($db, [
            'type' => ' Ansible ',
            'name' => ' ' . str_repeat('n', 191) . ' ',
            'host' => '::1',
            'port' => '65535',
            'username' => str_repeat('u', 191),
        ], 'secret', true);

        self::assertSame([
            'type' => 'ansible',
            'name' => str_repeat('n', 191),
            'host' => '::1',
            'port' => 65535,
            'username' => str_repeat('u', 191),
            'esxi_cert_kind' => null,
            'esxi_certificate_pem' => null,
            'esxi_cert_choice' => null,
        ], $values);
    }

    public function testAnEsxiUrlWithRootPathAndPortPassesAsWritten(): void
    {
        $values = credential_validate_payload($this->database(null), [
            'type' => 'esxi',
            'name' => 'esxi-1',
            'host' => 'https://esxi.invalid:443/',
            'port' => '1',
            'username' => 'root',
            'esxi_cert_kind' => 'none',
        ], 'secret', true);

        self::assertSame('https://esxi.invalid:443/', $values['host']);
        self::assertSame(1, $values['port']);
        self::assertSame('none', $values['esxi_cert_choice']);
        self::assertNull($values['esxi_cert_kind']);
    }

    public function testAnEmptyPortIsNullAndAnUpdateNeedsNoSecret(): void
    {
        $values = credential_validate_payload(
            $this->database(null, 'SELECT id FROM deploy_credentials WHERE type = ? AND name = ? AND id <> ? LIMIT 1'),
            ['type' => 'ansible', 'name' => 'ansible-1', 'host' => '192.0.2.10', 'port' => '', 'username' => 'ansible'],
            null,
            false,
            7
        );

        self::assertNull($values['port']);
        self::assertSame('192.0.2.10', $values['host']);
    }

    public function testANameTakenForTheSameTypeIsRefusedOnTheNameField(): void
    {
        try {
            credential_validate_payload(
                $this->database(['id' => 3]),
                ['type' => 'ansible', 'name' => 'ansible-1', 'host' => 'ansible.invalid', 'username' => 'ansible'],
                'secret',
                true
            );
            self::fail('a taken name was accepted');
        } catch (ValidationException $exception) {
            self::assertSame(['name' => $this->messageOf('validate.credential_name_taken_for_type', 'name')], $exception->errors());
        }
    }

    /**
     * A mysqli double whose single name lookup returns $row. With $sql the exact
     * statement is pinned, so the update path is proven to exclude its own row.
     *
     * @param array<string, mixed>|null $row
     */
    private function database(?array $row, ?string $sql = null): mysqli
    {
        $result = $this->createStub(mysqli_result::class);
        $result->method('fetch_assoc')->willReturn($row);
        $statement = $this->createStub(mysqli_stmt::class);
        $statement->method('bind_param')->willReturn(true);
        $statement->method('execute')->willReturn(true);
        $statement->method('get_result')->willReturn($result);
        $db = $this->createMock(mysqli::class);
        $expectation = $db->expects(self::once())->method('prepare');
        if ($sql !== null) {
            $expectation->with($sql);
        }
        $expectation->willReturn($statement);

        return $db;
    }

    /** The message the payload validator produces for $key on $field, in the active locale. */
    private function messageOf(string $key, string $field): string
    {
        $labels = [
            'type' => validator_label('credential_type', 'Credential type'),
            'name' => validator_label('name', 'Name'),
            'host' => validator_label('host', 'Host'),
            'port' => validator_label('port', 'Port'),
            'username' => validator_label('username', 'Username'),
        ];
        $label = $labels[$field] ?? '';

        return match ($key) {
            'validate.required' => validator_text($key, ':field is required.', ['field' => $label]),
            'validate.max_length' => validator_text($key, ':field must be at most :max characters long.', ['field' => $label, 'max' => $field === 'host' ? 255 : 191]),
            'validate.range' => validator_text($key, ':field must be between :min and :max.', ['field' => $label, 'min' => 1, 'max' => 65535]),
            'validate.integer' => validator_text($key, ':field must be an integer.', ['field' => $label]),
            'validate.enum' => validator_text($key, ':field has an invalid value.', ['field' => $label]),
            'validate.secret_required' => validator_text($key, 'Secret is required.'),
            'validate.host_no_whitespace' => validator_text($key, ':field must not contain whitespace or control characters.', ['field' => $label]),
            'validate.host_invalid' => validator_text($key, ':field must be a valid hostname or IP address.', ['field' => $label]),
            'validate.host_invalid.url_host' => validator_text('validate.host_invalid', ':field must be a valid hostname or IP address.', ['field' => validator_label('esxi_host_url_host', 'ESXi host URL host')]),
            'validate.ansible_host_no_url' => validator_text($key, 'Ansible SSH host must be a hostname or IP address, not a URL.'),
            'validate.esxi_url_scheme' => validator_text($key, 'ESXi host URL must use http or https.'),
            'validate.esxi_url_no_userinfo' => validator_text($key, 'ESXi host URL must not include credentials, query or fragment parts.'),
            'validate.esxi_url_no_path' => validator_text($key, 'ESXi host URL must not include a path.'),
            'validate.esxi_url_port_range' => validator_text($key, 'ESXi host URL port must be between 1 and 65535.'),
            'validate.esxi_url_host_missing' => validator_text($key, 'ESXi host URL must include a host.'),
            'validate.credential_name_taken_for_type' => validator_text($key, 'A credential with this name already exists for this type.'),
            default => throw new LogicException('no message for ' . $key),
        };
    }
}

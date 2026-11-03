<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * The validation matrix behind every portal form, the mission import and the
 * legacy token API: all three reach the database through these rules, so a gap
 * here is a gap in all of them at once.
 *
 * Each rule is pinned at its boundary (exactly at the limit and one past it),
 * for empty versus whitespace-only input, and across the notations an operator
 * actually pastes. Lengths are counted in characters, not bytes, because that is
 * what VARCHAR(n) holds under utf8mb4: an umlaut or an emoji must not cost a
 * field two or four of its characters.
 *
 * Pure value objects, no DB.
 */
final class ValidatorRulesTest extends TestCase
{
    /**
     * Runs one rule and reports both what it returned and whether it complained,
     * which is the pair that matters: a rule that reports an error but returns a
     * usable value still hands that value to a caller who forgets throwIfInvalid().
     *
     * @return array{value: mixed, failed: bool}
     */
    private function check(callable $rule): array
    {
        $validator = new Validator();
        $value = $rule($validator);
        $failed = false;
        try {
            $validator->throwIfInvalid();
        } catch (ValidationException) {
            $failed = true;
        }

        return ['value' => $value, 'failed' => $failed];
    }

    private function assertAccepts(callable $rule, mixed $expected, string $message = ''): void
    {
        $result = $this->check($rule);
        self::assertFalse($result['failed'], $message !== '' ? $message : 'expected the value to be accepted');
        self::assertSame($expected, $result['value'], $message);
    }

    private function assertRejects(callable $rule, string $message = ''): void
    {
        self::assertTrue($this->check($rule)['failed'], $message !== '' ? $message : 'expected the value to be rejected');
    }

    // --- strings: trimming and character-counted length -------------------

    public function testOptionalStringTrimsAndCountsCharactersNotBytes(): void
    {
        $max5 = static fn (string $v): callable => static fn (Validator $x): string => $x->optionalString('f', $v, 'F', 5);

        $this->assertAccepts($max5('abcde'), 'abcde', 'exactly at the limit');
        $this->assertRejects($max5('abcdef'), 'one past the limit');

        $this->assertAccepts($max5('  ab  '), 'ab', 'surrounding whitespace is trimmed away');
        $this->assertAccepts($max5('   '), '', 'whitespace-only collapses to empty, it is not a value');
        $this->assertAccepts($max5(''), '');

        // 5 umlauts are 10 bytes and 5 emoji are 20; a byte-based length check
        // would reject both even though VARCHAR(5) holds them.
        $this->assertAccepts($max5('äöüßx'), 'äöüßx', 'multi-byte characters count as one each');
        $this->assertRejects($max5('äöüßxy'));
        $this->assertAccepts($max5('🚀🚀🚀🚀🚀'), '🚀🚀🚀🚀🚀', '4-byte characters count as one each');
        $this->assertRejects($max5('🚀🚀🚀🚀🚀🚀'));
    }

    /**
     * Free text keeps control characters: the rules trim and measure, they do not
     * sanitize. Every consumer that cannot survive them has to escape them itself,
     * which is why ansible_yaml_string() hex-escapes rather than trusting input
     * (see AnsibleServerlistYamlSafetyTest).
     */
    public function testOptionalStringDoesNotStripControlCharacters(): void
    {
        $this->assertAccepts(
            static fn (Validator $x): string => $x->optionalString('f', "a\x00b", 'F', 5),
            "a\x00b"
        );
    }

    public function testRequireStringTreatsWhitespaceOnlyAsMissing(): void
    {
        $required = static fn (string $v): callable => static fn (Validator $x): string => $x->requireString('f', $v, 'F', 5);

        $this->assertAccepts($required('ok'), 'ok');
        $this->assertRejects($required(''), 'empty is missing');
        $this->assertRejects($required('   '), 'whitespace-only is missing, not a one-space value');
    }

    // --- integers ---------------------------------------------------------

    public function testIntRangeBoundariesAndDefault(): void
    {
        $r = static fn (string $v): callable => static fn (Validator $x): int => $x->intRange('f', $v, 'F', 1, 10, 4);

        $this->assertAccepts($r('1'), 1, 'lower bound is inclusive');
        $this->assertAccepts($r('10'), 10, 'upper bound is inclusive');
        $this->assertAccepts($r('  5  '), 5, 'surrounding whitespace is trimmed before parsing');
        $this->assertAccepts($r('+5'), 5, 'an explicit plus sign is an integer');
        $this->assertAccepts($r(''), 4, 'empty falls back to the default instead of failing');

        $this->assertRejects($r('0'), 'one below the lower bound');
        $this->assertRejects($r('11'), 'one above the upper bound');
        $this->assertRejects($r('5.5'), 'a decimal is not an integer');
        $this->assertRejects($r('12abc'), 'a trailing suffix is not silently cut off');
        $this->assertRejects($r('0x1'), 'hex notation is not accepted');
        $this->assertRejects($r('e'), 'non-numeric text');
        // Beyond PHP_INT_MAX a cast would wrap to a plausible-looking number.
        $this->assertRejects($r('99999999999999999999'), 'an overflowing number is rejected, not wrapped');
    }

    public function testIntRangeWithoutDefaultStillRejectsEmpty(): void
    {
        $this->assertRejects(static fn (Validator $x): int => $x->intRange('f', '', 'F', 1, 10));
    }

    public function testOptionalIntRangeDistinguishesEmptyFromInvalid(): void
    {
        $port = static fn (string $v): callable => static fn (Validator $x): ?int => $x->optionalIntRange('f', $v, 'F', 1, 65535);

        $this->assertAccepts($port(''), null, 'empty means "not set", not an error');
        $this->assertAccepts($port('1'), 1);
        $this->assertAccepts($port('65535'), 65535);
        $this->assertRejects($port('0'), 'port 0 is below the range');
        $this->assertRejects($port('65536'), 'one past the last port');
        $this->assertRejects($port('abc'));
    }

    // --- hostnames --------------------------------------------------------

    public function testHostnameAcceptsDnsNamesAndRejectsIllegalCharacters(): void
    {
        $h = static fn (string $v): callable => static fn (Validator $x): string => $x->hostname('f', $v, 'F');

        $this->assertAccepts($h('web01'), 'web01');
        $this->assertAccepts($h('web01.intern.local'), 'web01.intern.local');
        $this->assertAccepts($h('WEB01'), 'WEB01', 'case is preserved, not folded');
        $this->assertAccepts($h('a'), 'a', 'a single character is a name');

        $this->assertRejects($h('under_score'), 'the underscore is not a DNS character');
        $this->assertRejects($h('-lead'), 'a name may not start with a hyphen');
        $this->assertRejects($h('trail-'), 'a name may not end with a hyphen');
        $this->assertRejects($h('dot.'), 'a trailing dot is not accepted here');
        $this->assertRejects($h('hö.local'), 'no umlauts: the name goes to DNS and to MECM as ASCII');
        $this->assertRejects($h('a..b'), 'an empty label between dots');
        $this->assertRejects($h('.lead'), 'a leading dot is an empty first label');
        $this->assertRejects($h('a.-b.c'), 'a label may not start with a hyphen');
        $this->assertRejects($h('a-.b'), 'a label may not end with a hyphen');
        $this->assertRejects($h('a.' . str_repeat('b', 64) . '.c'), 'a label is capped at 63 characters');
    }

    public function testNetbiosHostnameIsFifteenCharactersAndDotless(): void
    {
        $n = static fn (string $v, bool $req = false): callable => static fn (Validator $x): string => $x->netbiosHostname('host', $v, 'Hostname', $req);

        $this->assertAccepts($n('WS-042'), 'WS-042');
        $this->assertAccepts($n('ABCDEFGHIJKLMNO'), 'ABCDEFGHIJKLMNO', 'exactly 15 characters');
        $this->assertAccepts($n(''), '', 'optional and empty');

        $this->assertRejects($n('ABCDEFGHIJKLMNOP'), '16 characters: MECM would truncate it silently');
        $this->assertRejects($n('web01.intern'), 'a Windows computer name has no dots');
        $this->assertRejects($n('-leading'));
        $this->assertRejects($n('trailing-'));
        $this->assertRejects($n('under_score'));
        $this->assertRejects($n('', true), 'required and empty');
    }

    public function testFqdnRequiresTwoLabelsAndValidLabelLengths(): void
    {
        $f = static fn (string $v): callable => static fn (Validator $x): string => $x->fqdn('f', $v, 'F');

        $this->assertAccepts($f('corp.example.local'), 'corp.example.local');
        $this->assertAccepts($f('a.b'), 'a.b', 'two labels are enough');
        $this->assertAccepts($f('a-b.local'), 'a-b.local', 'an internal hyphen is fine');
        $this->assertAccepts($f(str_repeat('a', 63) . '.local'), str_repeat('a', 63) . '.local', 'a label may be 63 characters');

        $this->assertRejects($f('single'), 'a single label is not an FQDN');
        $this->assertRejects($f('corp.example.local.'), 'the trailing dot leaves an empty label');
        $this->assertRejects($f('.corp.local'), 'a leading dot leaves an empty label');
        $this->assertRejects($f(str_repeat('a', 64) . '.local'), 'one character past the 63-character label limit');
        $this->assertRejects($f('-a.local'), 'a label may not start with a hyphen');
        $this->assertRejects($f('a-.local'), 'a label may not end with a hyphen');
    }

    // --- addresses --------------------------------------------------------

    public function testIpv4BoundariesAndNearMisses(): void
    {
        $ip = static fn (string $v): callable => static fn (Validator $x): string => $x->ipv4('f', $v, 'F');

        $this->assertAccepts($ip('0.0.0.0'), '0.0.0.0');
        $this->assertAccepts($ip('255.255.255.255'), '255.255.255.255');
        $this->assertAccepts($ip(' 1.2.3.4 '), '1.2.3.4', 'a pasted address keeps its surrounding whitespace out of the DB');

        $this->assertRejects($ip('256.1.1.1'), 'one past the last octet value');
        $this->assertRejects($ip('192.168.1'), 'three octets are not an address');
        $this->assertRejects($ip('1.2.3.4.5'), 'five octets are not an address');
        $this->assertRejects($ip('::1'), 'IPv6 is not accepted where IPv4 is required');
        // A leading zero reads as octal to some resolvers and as decimal to others,
        // which is how an allowlist entry and its target end up disagreeing.
        $this->assertRejects($ip('01.2.3.4'), 'a leading zero is ambiguous, not a nicety');
    }

    public function testSubnetAcceptsDottedMaskOrCidrSuffix(): void
    {
        $m = static fn (string $v): callable => static fn (Validator $x): string => $x->ipv4OrCidrMask('f', $v, 'F');

        $this->assertAccepts($m('255.255.255.0'), '255.255.255.0');
        $this->assertAccepts($m('/0'), '/0');
        $this->assertAccepts($m('/24'), '/24');
        $this->assertAccepts($m('/30'), '/30', 'the last prefix that still leaves usable hosts');

        $this->assertRejects($m('/31'), 'one past /30');
        $this->assertRejects($m('/32'), 'a single-host prefix cannot address a VM network');
        $this->assertRejects($m('24'), 'the slash is required, a bare number is not a mask');
    }

    // --- MAC: notation is normalized, not merely accepted -----------------

    /**
     * Every MAC-based lookup (mecm-api, mecm_report, the db_importMAC duplicate
     * guard) runs the incoming address through virtusphere_normalize_mac() and
     * then matches it exactly. So the stored value has to be canonical too. The
     * hyphen form is what Windows prints in `ipconfig /all` and is therefore the
     * form an operator pastes; stored verbatim it would make the VM unresolvable
     * for MECM, silently. Migration 0008 canonicalized the column once; this rule
     * is what stops the portal from drifting it again.
     */
    public function testMacIsStoredCanonicalWhateverNotationWasTyped(): void
    {
        $mac = static fn (string $v): callable => static fn (Validator $x): string => $x->mac('f', $v, 'F');

        $canonical = '00:50:56:AA:BB:CC';
        $this->assertAccepts($mac('00:50:56:AA:BB:CC'), $canonical, 'already canonical');
        $this->assertAccepts($mac('00:50:56:aa:bb:cc'), $canonical, 'lower case is upper-cased');
        $this->assertAccepts($mac('00-50-56-AA-BB-CC'), $canonical, 'the hyphen form Windows prints');
        $this->assertAccepts($mac('0050.56aa.bbcc'), $canonical, 'the Cisco dotted form');
        $this->assertAccepts($mac(' 00:50:56:aa:bb:cc '), $canonical, 'a pasted address with whitespace');
        $this->assertAccepts($mac(''), '', 'optional and empty stays empty, it is not a MAC');
    }

    public function testMacRejectsNonAddresses(): void
    {
        $mac = static fn (string $v): callable => static fn (Validator $x): string => $x->mac('f', $v, 'F');

        $this->assertRejects($mac('00:50:56:aa:bb'), 'five octets are not a MAC');
        $this->assertRejects($mac('00:50:56:aa:bb:zz'), 'zz is not hex');
        $this->assertRejects($mac('005056aabbcc'), 'the separator-less form is not accepted, here or at the machine API');
    }

    // --- enums ------------------------------------------------------------

    /**
     * enum() folds case and applies the default, so a stored enum is always one of
     * the known values: the create playbook fails at ESXi on anything else.
     */
    public function testEnumCanonicalizesAndFallsBackToTheDefault(): void
    {
        $type = static fn (string $v): callable => static fn (Validator $x): string => $x->enum('f', $v, 'F', ['vmxnet3', 'e1000', 'e1000e'], 'vmxnet3');

        $this->assertAccepts($type('vmxnet3'), 'vmxnet3');
        $this->assertAccepts($type('VMXNET3'), 'vmxnet3', 'case is folded');
        $this->assertAccepts($type(' E1000 '), 'e1000', 'trimmed and folded');
        $this->assertAccepts($type(''), 'vmxnet3', 'empty takes the default');

        $this->assertRejects($type('bogus'), 'an unknown value never reaches the hypervisor');
    }

    public function testEnumWithoutDefaultRejectsEmpty(): void
    {
        $credential = static fn (string $v): callable => static fn (Validator $x): string => $x->enum('f', $v, 'F', ['esxi', 'ansible']);

        $this->assertAccepts($credential('esxi'), 'esxi');
        $this->assertRejects($credential(''), 'without a default there is nothing to fall back to');
    }
}

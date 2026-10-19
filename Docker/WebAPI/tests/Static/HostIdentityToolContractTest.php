<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * UX-HK (way b): the one-shot tool on the Ansible host that prints the host's own
 * public key fingerprints in the form the credentials page accepts. Its safety is
 * a property of the text it is written in, so this test reads the file: it reads
 * only the public host keys of this host, takes no path from its arguments, writes
 * nothing and reaches no network.
 */
final class HostIdentityToolContractTest extends TestCase
{
    private const SCRIPT = 'Ansible/host-identity/virtusphere-host-identity.sh';

    private function raw(): string
    {
        $path = dirname(__DIR__, 4) . '/' . self::SCRIPT;
        self::assertFileExists($path);

        return (string) file_get_contents($path);
    }

    public function testItIsPosixShWithLfAndAsciiOnly(): void
    {
        $raw = $this->raw();
        self::assertStringStartsWith("#!/bin/sh\n", $raw);
        self::assertStringNotContainsString("\r", $raw, 'the script must be LF');
        self::assertSame(0, preg_match('/[^\x09\x0A\x20-\x7E]/', $raw), 'the script must be ASCII');
    }

    public function testItReadsOnlyThePublicHostKeysOfThisHost(): void
    {
        $raw = $this->raw();
        self::assertStringContainsString('for key in /etc/ssh/ssh_host_*_key.pub; do', $raw);
        // A private key is the same name without the .pub suffix; no other path may be named.
        self::assertSame(0, preg_match('#ssh_host_\*_key(?!\.pub)#', $raw));
        // Arguments never become a path: the script refuses any argument.
        self::assertStringNotContainsString('$1', $raw);
        self::assertSame(1, preg_match_all('/^\s*ssh-keygen -l -E sha256 -f "\$key"$/m', $raw));
    }

    public function testItWritesNothingAndReachesNoNetwork(): void
    {
        $raw = $this->raw();
        foreach (['curl', 'wget', ' nc ', 'sudo', 'rm ', 'tee ', 'mv ', 'cp ', '>>', 'apt', 'pip'] as $forbidden) {
            self::assertStringNotContainsString($forbidden, $raw, 'forbidden in the host tool: ' . $forbidden);
        }
    }
}

<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * K21-F (user decision 5): the systemd timer that removes stale accounts.yml
 * files from the Ansible host, its service, and the installer that puts them
 * there under root. The rules are read from the files as text, because the
 * safety of a shell script is a property of the text it is written in:
 *
 *  - the find that selects a file is bounded (-maxdepth 2, -type f, -user,
 *    24 h) and follows no link (no -L, -H, -follow);
 *  - every removal is preceded by flock -n on the job directory and is only
 *    `rm -f --` of one file, never a recursive removal;
 *  - the job directory must be a real directory, the script refuses uid 0 and
 *    never reads the content of accounts.yml;
 *  - nothing needs the network or a package manager (air-gapped customer host),
 *    and the directory is not handed to systemd-tmpfiles.
 *
 * tests/Static pins the text; the behaviour is proven in a util-linux container.
 */
final class HostSecretCleanupContractTest extends TestCase
{
    private const DIR = 'Ansible/host-cleanup/';
    private const SCRIPT = 'virtusphere-secret-cleanup.sh';
    private const SERVICE = 'virtusphere-secret-cleanup.service';
    private const TIMER = 'virtusphere-secret-cleanup.timer';
    private const INSTALLER = 'install-secret-cleanup.sh';
    private const INSTALLED_DIR = '/usr/local/libexec/virtusphere';
    private const INSTALLED_SCRIPT = self::INSTALLED_DIR . '/virtusphere-secret-cleanup.sh';
    private const UNIT_DIR = '/etc/systemd/system';
    private const PLACEHOLDER = '@ANSIBLE_ACCOUNT@';

    protected function setUp(): void
    {
        // Skipped only where the repository root is not mounted at all. The QA
        // lane runs phpunit with --fail-on-skipped, so there a missing mount is
        // a failure, not a pass.
        if (!is_file(self::root() . '/Ansible/requirements.yml')) {
            self::markTestSkipped('Repo root not visible from this runtime.');
        }
    }

    public function testTheFourHostFilesExistAndTheShellFilesAreLfAsciiPosixSh(): void
    {
        foreach ([self::SCRIPT, self::INSTALLER, self::SERVICE, self::TIMER] as $name) {
            self::assertFileExists(self::root() . '/' . self::DIR . $name);
        }
        foreach ([self::SCRIPT, self::INSTALLER] as $name) {
            $raw = self::raw($name);
            self::assertStringStartsWith("#!/bin/sh\n", $raw, $name . ' must be POSIX sh');
            self::assertStringNotContainsString("\r", $raw, $name . ' must be LF');
            self::assertSame(0, preg_match('/[^\x09\x0A\x20-\x7E]/', $raw), $name . ' must be ASCII');
        }
    }

    public function testTheCleanupSelectsOnlyOwnRegularFilesOneLevelDownAndFollowsNoLink(): void
    {
        $find = self::singleLine(self::code(self::SCRIPT), '/\bfind\s+\/tmp\b/');

        self::assertMatchesRegularExpression(
            '/\bfind \/tmp -maxdepth 2 -mindepth 2 -path \'\/tmp\/virtusphere-job-\*\/accounts\.yml\' '
            . '-type f -user "\$\(id -un\)" -mmin \+1440(\s|$)/',
            $find,
            'the find must select exactly accounts.yml one level below /tmp/virtusphere-job-*, owned by the running account, older than 24 h'
        );
        self::assertDoesNotMatchRegularExpression(
            '/\s-(L|H|follow)\b/',
            $find,
            'the find must not follow symbolic links'
        );
    }

    public function testEveryRemovalIsLockedFirstAndIsASingleFileRemoval(): void
    {
        $source = self::joined(self::code(self::SCRIPT));

        self::assertSame(
            0,
            preg_match('/\brm\s+-[a-zA-Z]*r/', $source),
            'no recursive rm: the job directory is never removed here'
        );
        self::assertSame(
            1,
            preg_match_all('/\brm -f -- /', $source),
            'exactly one removal form: rm -f -- <file>'
        );
        self::assertMatchesRegularExpression(
            '/\bflock -n [^\n]*\brm -f -- /',
            $source,
            'the removal runs under flock -n on the job directory'
        );
        self::assertLessThan(
            strpos($source, 'rm -f -- '),
            strpos($source, 'flock -n '),
            'flock -n must be taken before the removal'
        );
    }

    public function testTheJobDirectoryMustBeARealDirectoryAndEveryOutcomeIsNamed(): void
    {
        $source = self::joined(self::code(self::SCRIPT));

        self::assertMatchesRegularExpression(
            '/\[ -L /',
            $source,
            'a job directory that is a link must be refused before it is locked'
        );
        foreach (['removed', 'locked', 'failed'] as $outcome) {
            self::assertStringContainsString($outcome, $source, 'the per-file outcome ' . $outcome . ' is missing');
        }
    }

    public function testItRefusesToRunAsRootAndNeverReadsTheContentOfAccountsYml(): void
    {
        $lines = self::code(self::SCRIPT);
        $uidLine = null;
        foreach ($lines as $index => $line) {
            if (str_contains($line, 'id -u')) {
                $uidLine = $index;
                break;
            }
        }
        self::assertNotNull($uidLine, 'the script must check its own uid');
        $window = implode("\n", array_slice($lines, $uidLine, 3));
        self::assertMatchesRegularExpression('/\bexit\s+[1-9]/', $window, 'uid 0 must end the run with a nonzero exit');

        self::assertSame(
            0,
            preg_match('/\b(cat|head|tail|grep|sed|awk|less|more|tee|cut)\b[^\n]*accounts\.yml/', self::joined($lines)),
            'only the path of accounts.yml is logged, never its content'
        );
    }

    public function testTheSummaryGoesToTheJournalUnderTheTag(): void
    {
        $source = self::joined(self::code(self::SCRIPT));

        self::assertGreaterThanOrEqual(
            1,
            preg_match_all('/\blogger -t virtusphere-secret-cleanup\b/', $source),
            'the outcome lines reach the journal through logger with the tag'
        );
    }

    public function testNothingNeedsTheNetworkOrAPackageManager(): void
    {
        foreach ([self::SCRIPT, self::INSTALLER, self::SERVICE, self::TIMER] as $name) {
            $source = self::joined(self::code($name));
            self::assertSame(
                0,
                preg_match(
                    '/\b(curl|wget|apt|apt-get|aptitude|dpkg|dnf|yum|zypper|snap|pip|pip3|git|nc|ssh|scp|sftp|systemd-tmpfiles)\b/',
                    $source,
                    $found
                ),
                $name . ' uses ' . ($found[0] ?? '')
            );
        }
    }

    public function testTheServiceRunsOneshotAsThePlaceholderAccountOnTheRealTmp(): void
    {
        $unit = self::joined(self::code(self::SERVICE));

        self::assertSame(1, substr_count($unit, self::PLACEHOLDER), 'exactly one account placeholder, filled in by the installer');
        self::assertMatchesRegularExpression('/^Type=oneshot$/m', $unit);
        self::assertMatchesRegularExpression('/^User=' . preg_quote(self::PLACEHOLDER, '/') . '$/m', $unit);
        self::assertMatchesRegularExpression(
            '/^PrivateTmp=no$/m',
            $unit,
            'the job directories live in the real /tmp; a private /tmp would see none of them'
        );
        self::assertMatchesRegularExpression('/^NoNewPrivileges=yes$/m', $unit);
        self::assertMatchesRegularExpression(
            '/^ExecStart=' . preg_quote(self::INSTALLED_SCRIPT, '/') . '$/m',
            $unit,
            'ExecStart names the path the installer puts the script at'
        );
        self::assertDoesNotMatchRegularExpression('/^User=root$/m', $unit);
        self::assertDoesNotMatchRegularExpression('/^PrivateTmp=yes$/m', $unit);
        self::assertDoesNotMatchRegularExpression(
            '/^Standard(Output|Error)=(file|append|truncate|null)/m',
            $unit,
            'output goes to the journal'
        );
    }

    public function testTheTimerStartsAfterBootHourlyAndDoesNotCatchUpMissedRuns(): void
    {
        $timer = self::joined(self::code(self::TIMER));

        self::assertMatchesRegularExpression('/^\[Timer\]$/m', $timer);
        self::assertMatchesRegularExpression('/^OnBootSec=15min$/m', $timer);
        self::assertMatchesRegularExpression('/^OnUnitActiveSec=1h$/m', $timer);
        // A catch-up run after an outage adds nothing: OnBootSec already runs
        // the cleanup once the host is back, and a backlog of runs is noise.
        self::assertMatchesRegularExpression('/^Persistent=false$/m', $timer);
        self::assertMatchesRegularExpression('/^WantedBy=timers\.target$/m', $timer);
    }

    public function testThePreflightProbeAndTheInstallerNameTheSameTimer(): void
    {
        $probeFound = false;
        foreach (glob(self::root() . '/Docker/WebAPI/lib/ansible_command*.php') ?: [] as $file) {
            if (str_contains((string) file_get_contents($file), self::TIMER)) {
                $probeFound = true;
            }
        }
        self::assertTrue($probeFound, 'no preflight probe under lib/ansible_command*.php names ' . self::TIMER);

        self::assertStringContainsString(
            'systemctl enable --now ' . self::TIMER,
            self::joined(self::code(self::INSTALLER))
        );
    }

    public function testTheInstallerReplacesThePlaceholderAndEnablesTheTimerAsRootOnly(): void
    {
        $installer = self::joined(self::code(self::INSTALLER));

        self::assertStringContainsString('getent passwd', $installer, 'the account is checked before anything is written');
        self::assertStringContainsString(self::PLACEHOLDER, $installer, 'the installer fills in the placeholder');
        // The whole name is checked: a character outside [a-z0-9_-] anywhere in
        // it, and a first character outside [a-z_]. A prefix test is not enough.
        self::assertMatchesRegularExpression(
            '/\*\[!a-z0-9_-\]\*\)/',
            $installer,
            'the account name must be rejected when any character is outside [a-z0-9_-]'
        );
        self::assertMatchesRegularExpression(
            '/\[!a-z_\]\*/',
            $installer,
            'the account name must be rejected when its first character is outside [a-z_]'
        );
        self::assertStringContainsString('$#', $installer, 'the account argument is counted');
        self::assertMatchesRegularExpression('/\bid -u\b/', $installer, 'the installer checks for root');

        self::assertStringContainsString(self::INSTALLED_DIR, $installer);
        self::assertStringContainsString(self::UNIT_DIR, $installer);
        self::assertMatchesRegularExpression('/\binstall\s[^\n]*-m\s*0?755\b/', $installer, 'the script is installed 0755');
        self::assertMatchesRegularExpression('/-o\s+root\b/', $installer);
        self::assertMatchesRegularExpression('/-g\s+root\b/', $installer);

        self::assertStringContainsString('systemctl daemon-reload', $installer);
        self::assertStringContainsString('systemctl enable --now ' . self::TIMER, $installer);

        foreach (['flock', 'logger'] as $tool) {
            self::assertMatchesRegularExpression(
                '/\bcommand -v ' . $tool . '\b/',
                $installer,
                $tool . ' is checked, not assumed'
            );
        }

        // Idempotent: a unit is written as a whole, never appended to.
        self::assertStringNotContainsString('>>', $installer);
    }

    public function testTheAccountPlaceholderIsTheOnlyAccountSpecificValueInTheHostFiles(): void
    {
        // The script and the timer take no account at all; only the service is
        // bound to one, and only through the placeholder.
        self::assertSame(0, substr_count(self::raw(self::SCRIPT), self::PLACEHOLDER));
        self::assertSame(0, substr_count(self::raw(self::TIMER), self::PLACEHOLDER));
    }

    private static function root(): string
    {
        return dirname(__DIR__, 4);
    }

    private static function raw(string $name): string
    {
        $path = self::root() . '/' . self::DIR . $name;
        self::assertFileExists($path);

        return (string) file_get_contents($path);
    }

    /**
     * The code lines of a host file: CRLF normalised, blanks and comment lines
     * (#, ; as systemd allows) removed, so a comment that explains why a thing is
     * absent cannot satisfy or break a rule.
     *
     * @return list<string>
     */
    private static function code(string $name): array
    {
        $lines = [];
        foreach (explode("\n", str_replace("\r\n", "\n", self::raw($name))) as $line) {
            $trimmed = trim($line);
            if ($trimmed === '' || $trimmed[0] === '#' || $trimmed[0] === ';') {
                continue;
            }
            $lines[] = $trimmed;
        }

        return $lines;
    }

    /** @param list<string> $lines */
    private static function joined(array $lines): string
    {
        return implode("\n", $lines);
    }

    /** @param list<string> $lines */
    private static function singleLine(array $lines, string $pattern): string
    {
        $matches = array_values(array_filter($lines, static fn (string $line): bool => preg_match($pattern, $line) === 1));
        self::assertCount(1, $matches, 'exactly one line must match ' . $pattern);

        return $matches[0];
    }
}

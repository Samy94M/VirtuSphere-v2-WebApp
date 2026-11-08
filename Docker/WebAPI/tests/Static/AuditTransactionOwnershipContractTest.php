<?php

declare(strict_types=1);

use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\ParentConnectingVisitor;
use PhpParser\ParserFactory;
use PHPUnit\Framework\TestCase;

/**
 * R19 (MECM plan decision 32): a domain write and its audit row commit or roll back
 * together, written with audit_event_required() inside the repo_transaction() of the
 * write. An unchecked audit_event() after the commit is how a change ends up applied
 * with no trail when the insert is the thing that fails.
 *
 * W3A: the sites are DERIVED from the source, not listed. The test parses every PHP
 * file of the web root outside tests/ and vendor/ and
 *  - requires every audit_event_required() call to run inside a repo_transaction():
 *    lexically inside a closure handed to repo_transaction(), or inside a function
 *    whose every call site does (a fixpoint over the call graph, so a shared row
 *    helper such as repo_audit_mecm_id_reset() needs no entry);
 *  - requires every unchecked audit_event() call to be one of the reasoned
 *    exceptions below, with its exact count. A new unchecked site fails, and so
 *    does an exception whose site is gone, so the list can only follow the code.
 *
 * Behaviour is covered by the integration tests of the writers
 * (AuditRequiredTransactionTest, CredentialAuditTransactionIntegrationTest,
 * AuditAtomicityIntegrationTest).
 */
final class AuditTransactionOwnershipContractTest extends TestCase
{
    /**
     * Unchecked audit_event() sites, keyed `file::function::EVENT` (`{main}` for top-level
     * code, `*` for an event code that is a parameter). `pure` records an event without a
     * domain write of its own (R19: an export, a denied request, a login attempt).
     * `separate` records a state change that deliberately does not share the audit
     * transaction; the reason says why.
     *
     * @var array<string,array{count:int,kind:'pure'|'separate',reason:string}>
     */
    private const UNCHECKED = [
        'lib/audit_events.php::portal_forbid::VIRTUSPHERE_AUDIT_EVENT_AUTH_ACCESS_DENIED' => ['count' => 1, 'kind' => 'pure', 'reason' => 'a refused request writes nothing'],
        'lib/audit_events.php::portal_reject_csrf::VIRTUSPHERE_AUDIT_EVENT_AUTH_CSRF_REJECTED' => ['count' => 1, 'kind' => 'pure', 'reason' => 'a refused request writes nothing'],
        'portal/login.php::{main}::VIRTUSPHERE_AUDIT_EVENT_AUTH_CSRF_REJECTED' => ['count' => 1, 'kind' => 'pure', 'reason' => 'a refused sign-in form writes nothing'],
        'lib/auth.php::auth_login_local::VIRTUSPHERE_AUDIT_EVENT_AUTH_ACCOUNT_LOCKED' => ['count' => 1, 'kind' => 'pure', 'reason' => 'the lock is derived from the attempt counter (C-04c), no column is written for it'],
        'lib/auth.php::auth_login_local::VIRTUSPHERE_AUDIT_EVENT_AUTH_LOGIN' => ['count' => 2, 'kind' => 'pure', 'reason' => 'R19: a login attempt is a security event; the success row already shares the attempt transaction'],
        'lib/auth_directory_login.php::auth_login_directory::VIRTUSPHERE_AUDIT_EVENT_AUTH_LOGIN' => ['count' => 2, 'kind' => 'pure', 'reason' => 'R19: a login attempt is a security event; the success row already shares the attempt transaction'],
        'lib/auth_rate_limit.php::auth_record_failed_login::VIRTUSPHERE_AUDIT_EVENT_AUTH_IP_RATE_LIMITED' => ['count' => 1, 'kind' => 'pure', 'reason' => 'the limit onset is derived from counted attempts, nothing is written for it'],
        'lib/auth_rate_limit.php::auth_finish_failed_login::VIRTUSPHERE_AUDIT_EVENT_AUTH_IP_RATE_LIMITED' => ['count' => 1, 'kind' => 'pure', 'reason' => 'the limit onset is derived from counted attempts, nothing is written for it'],
        'lib/auth.php::current_user::VIRTUSPHERE_AUDIT_EVENT_AUTH_SESSION_ENDED' => ['count' => 3, 'kind' => 'pure', 'reason' => 'the ended session is PHP session state, not a database row'],
        'portal/logout.php::{main}::VIRTUSPHERE_AUDIT_EVENT_AUTH_LOGOUT' => ['count' => 1, 'kind' => 'pure', 'reason' => 'the ended session is PHP session state, not a database row'],
        'portal/account.php::{main}::VIRTUSPHERE_AUDIT_EVENT_AUTH_PASSWORD_CHANGE_ATTEMPT' => ['count' => 1, 'kind' => 'pure', 'reason' => 'the denied attempt (wrong current password) writes nothing; the success row is required'],
        'lib/errors.php::virtusphere_audit_uncaught_error::VIRTUSPHERE_AUDIT_EVENT_SYSTEM_ERROR' => ['count' => 1, 'kind' => 'pure', 'reason' => 'an error reference, written fail-soft from the error handler'],
        'lib/logs_export.php::logs_export_prepare::VIRTUSPHERE_AUDIT_EVENT_LOGS_CSV_EXPORTED' => ['count' => 1, 'kind' => 'pure', 'reason' => 'an export reads only'],
        'lib/missions_page.php::missions_export_csv_if_requested::VIRTUSPHERE_AUDIT_EVENT_MISSION_LIST_EXPORTED' => ['count' => 1, 'kind' => 'pure', 'reason' => 'an export reads only'],
        'lib/vms_page.php::vms_export_csv_if_requested::VIRTUSPHERE_AUDIT_EVENT_VM_LIST_EXPORTED' => ['count' => 1, 'kind' => 'pure', 'reason' => 'an export reads only'],
        'portal/deploy_log.php::{main}::VIRTUSPHERE_AUDIT_EVENT_DEPLOY_LOG_EXPORTED' => ['count' => 1, 'kind' => 'pure', 'reason' => 'an export reads only (HA-14: written before the stream)'],
        'portal/mission_details.php::{main}::VIRTUSPHERE_AUDIT_EVENT_MISSION_TRANSFERRED' => ['count' => 1, 'kind' => 'pure', 'reason' => 'the mission export reads only'],
        'lib/maintenance_tasks.php::maintenance_worker_record_transitions::VIRTUSPHERE_AUDIT_EVENT_INTEGRATION_STATE' => ['count' => 1, 'kind' => 'pure', 'reason' => 'the remembered state lives in the worker process; LG-06 keeps it unchanged until the row is stored'],
        'lib/machine_api.php::machine_api_audit_warning::*' => ['count' => 1, 'kind' => 'pure', 'reason' => 'throttled observation of a refused or failed machine call, fail-soft by design'],
        'lib/settings_actions.php::settings_handle_post::VIRTUSPHERE_AUDIT_EVENT_SETTINGS_CERT_INSTALLED' => ['count' => 1, 'kind' => 'separate', 'reason' => 'the certificate material is written to the file system, which no database transaction covers'],
        'lib/credentials_actions.php::credentials_handle_post::VIRTUSPHERE_AUDIT_EVENT_DEPLOY_INVENTORY_REQUESTED' => ['count' => 1, 'kind' => 'separate', 'reason' => 'esxi_inventory_enqueue_for_credential() turns its own failure into a reason; inside an outer transaction that catch would commit a partial rest'],
        'lib/deploy_worker_inventory.php::deploy_worker_process_inventory_job::VIRTUSPHERE_AUDIT_EVENT_CREDENTIAL_INVENTORY_AUTOMATION' => ['count' => 1, 'kind' => 'separate', 'reason' => 'the auth-failure pause protects the ESXi account from lockout (ADR-0023) and must survive a refused row'],
        'lib/directory_service.php::directory_open_service_connection::VIRTUSPHERE_AUDIT_EVENT_DIRECTORY_BIND_REJECTED' => ['count' => 1, 'kind' => 'separate', 'reason' => 'the bind-rejection pause protects the search account from a domain-wide lockout and must survive a refused row'],
        'lib/deploy_worker_outcome_audit.php::deploy_worker_write_outcome_audit::VIRTUSPHERE_AUDIT_EVENT_DEPLOY_OUTCOME' => ['count' => 1, 'kind' => 'separate', 'reason' => 'LG-07/LG-08: conclusions publish first and write the row fail-soft; cancel and reaper call it in their transaction and check the result'],
        'lib/maintenance_tasks.php::maintenance_worker_retention::VIRTUSPHERE_AUDIT_EVENT_SYSTEM_RETENTION_PURGED' => ['count' => 1, 'kind' => 'separate', 'reason' => 'each purge commits in its own batches; a missing row is reported as a failed job'],
        'lib/maintenance_tasks.php::maintenance_worker_sweep_deploying_vms::VIRTUSPHERE_AUDIT_EVENT_DEPLOY_CONVERGENCE' => ['count' => 1, 'kind' => 'separate', 'reason' => 'one transaction per VM (SKIP LOCKED) with its status event; the mission summary would need a wider transaction'],
    ];

    /** Ratchet: deliberately separate state changes. It may only go down. */
    private const SEPARATE_SITES = 7;

    /** The owner: audit_event_required() itself calls audit_event(). */
    private const OWNER = 'lib/repo/log.php::audit_event_required';

    /** @var array<string,list<Node>>|null */
    private static ?array $trees = null;

    private static function webRoot(): string
    {
        return dirname(__DIR__, 2);
    }

    /** @return array<string,list<Node>> relative path => statements with parent links */
    private static function trees(): array
    {
        if (self::$trees !== null) {
            return self::$trees;
        }
        $parser = (new ParserFactory())->createForNewestSupportedVersion();
        $root = self::webRoot();
        $trees = [];
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            if (!$file instanceof SplFileInfo || $file->getExtension() !== 'php') {
                continue;
            }
            $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
            if (preg_match('#^(tests|vendor|logs)/#', $relative) === 1) {
                continue;
            }
            $nodes = $parser->parse((string) file_get_contents($file->getPathname())) ?? [];
            $traverser = new NodeTraverser(new ParentConnectingVisitor());
            $trees[$relative] = $traverser->traverse($nodes);
        }
        ksort($trees);

        return self::$trees = $trees;
    }

    private static function calledName(Node\Expr\FuncCall $call): ?string
    {
        return $call->name instanceof Node\Name ? strtolower($call->name->toString()) : null;
    }

    /**
     * The named function around a node (`{main}` at top level) and whether a closure
     * handed to repo_transaction() lies between them.
     *
     * @return array{function:string,lexical:bool}
     */
    private static function scope(Node $node): array
    {
        $lexical = false;
        for ($current = $node->getAttribute('parent'); $current instanceof Node; $current = $current->getAttribute('parent')) {
            if ($current instanceof Node\Stmt\Function_) {
                return ['function' => $current->name->toString(), 'lexical' => $lexical];
            }
            if ($current instanceof Node\Stmt\ClassMethod) {
                return ['function' => 'method:' . $current->name->toString(), 'lexical' => $lexical];
            }
            if ($current instanceof Node\Expr\Closure || $current instanceof Node\Expr\ArrowFunction) {
                $arg = $current->getAttribute('parent');
                $call = $arg instanceof Node\Arg ? $arg->getAttribute('parent') : null;
                if ($call instanceof Node\Expr\FuncCall && self::calledName($call) === 'repo_transaction') {
                    $lexical = true;
                }
            }
        }

        return ['function' => '{main}', 'lexical' => $lexical];
    }

    /** @return list<array{file:string,function:string,lexical:bool,call:Node\Expr\FuncCall}> */
    private static function calls(string $name): array
    {
        $finder = new NodeFinder();
        $found = [];
        foreach (self::trees() as $file => $nodes) {
            foreach ($finder->findInstanceOf($nodes, Node\Expr\FuncCall::class) as $call) {
                if (self::calledName($call) !== $name) {
                    continue;
                }
                $scope = self::scope($call);
                $found[] = ['file' => $file, 'function' => $scope['function'], 'lexical' => $scope['lexical'], 'call' => $call];
            }
        }

        return $found;
    }

    /**
     * Functions that only ever run inside a transaction: every call site is inside a
     * repo_transaction() closure or inside another such function. Least fixpoint, so
     * a function without callers, or one reachable from a single bare call, is not.
     *
     * @return array<string,true> lower-cased function name => true
     */
    private static function transactionalFunctions(): array
    {
        $finder = new NodeFinder();
        $declared = [];
        foreach (self::trees() as $nodes) {
            foreach ($finder->findInstanceOf($nodes, Node\Stmt\Function_::class) as $function) {
                $declared[strtolower($function->name->toString())] = true;
            }
        }
        $sites = [];
        foreach (self::trees() as $nodes) {
            foreach ($finder->findInstanceOf($nodes, Node\Expr\FuncCall::class) as $call) {
                $name = self::calledName($call);
                if ($name !== null && isset($declared[$name])) {
                    $sites[$name][] = self::scope($call);
                }
            }
        }

        $transactional = [];
        do {
            $changed = false;
            foreach ($sites as $name => $calls) {
                if (isset($transactional[$name])) {
                    continue;
                }
                $all = true;
                foreach ($calls as $site) {
                    if (!$site['lexical'] && !isset($transactional[strtolower($site['function'])])) {
                        $all = false;
                        break;
                    }
                }
                if ($all) {
                    $transactional[$name] = true;
                    $changed = true;
                }
            }
        } while ($changed);

        return $transactional;
    }

    private static function eventOf(Node\Expr\FuncCall $call): string
    {
        $event = $call->getArgs()[1]->value ?? null;

        return $event instanceof Node\Expr\ConstFetch ? $event->name->toString() : '*';
    }

    public function testTheScanSeesTheWholeWebRoot(): void
    {
        // Zero-match guard: a scan that finds nothing reads exactly like a clean tree.
        $trees = self::trees();
        foreach (['lib/settings_actions.php', 'lib/users_admin.php', 'lib/repo/log.php', 'portal/vms.php', 'mecm_packages.php'] as $file) {
            self::assertArrayHasKey($file, $trees, $file . ' must be scanned');
        }
        self::assertGreaterThan(40, count(self::calls('audit_event_required')));
        self::assertGreaterThan(20, count(self::calls('audit_event')));
    }

    public function testEveryRequiredRowIsWrittenInsideATransaction(): void
    {
        $transactional = self::transactionalFunctions();
        $outside = [];
        foreach (self::calls('audit_event_required') as $site) {
            if ($site['lexical'] || isset($transactional[strtolower($site['function'])])) {
                continue;
            }
            $outside[] = sprintf('%s:%d (%s)', $site['file'], $site['call']->getStartLine(), $site['function']);
        }

        self::assertSame([], $outside, 'audit_event_required() outside any repo_transaction(): it would throw at run time');
    }

    public function testEveryUncheckedRowIsAReasonedException(): void
    {
        $found = [];
        $lines = [];
        foreach (self::calls('audit_event') as $site) {
            if ($site['file'] . '::' . $site['function'] === self::OWNER) {
                continue;
            }
            $key = $site['file'] . '::' . $site['function'] . '::' . self::eventOf($site['call']);
            $found[$key] = ($found[$key] ?? 0) + 1;
            $lines[$key][] = $site['call']->getStartLine();
        }

        $listed = array_map(static fn (array $entry): int => $entry['count'], self::UNCHECKED);
        foreach ($found as $key => $count) {
            self::assertArrayHasKey($key, $listed, sprintf(
                'New unchecked audit_event() at %s line %s. A domain write writes its row with '
                . 'audit_event_required() inside its repo_transaction() (R19); only a reasoned exception goes into UNCHECKED.',
                $key,
                implode(', ', $lines[$key])
            ));
            self::assertSame($listed[$key], $count, $key . ': the exception count must match the code');
        }
        foreach (array_keys($listed) as $key) {
            self::assertArrayHasKey($key, $found, $key . ': the exception has no site any more; remove it');
        }
        foreach (self::UNCHECKED as $key => $entry) {
            self::assertContains($entry['kind'], ['pure', 'separate'], $key);
            self::assertNotSame('', trim($entry['reason']), $key . ' needs a reason');
        }
    }

    public function testDeliberatelySeparateStateChangesOnlyShrink(): void
    {
        $separate = array_sum(array_map(
            static fn (array $entry): int => $entry['kind'] === 'separate' ? $entry['count'] : 0,
            self::UNCHECKED
        ));

        self::assertLessThanOrEqual(self::SEPARATE_SITES, $separate, 'a new separate state change needs the review that raises SEPARATE_SITES');
        self::assertSame(self::SEPARATE_SITES, $separate, 'a site became atomic: lower SEPARATE_SITES to ' . $separate);
    }

    private function code(string $relative): string
    {
        $path = self::webRoot() . '/' . $relative;
        self::assertFileExists($path);

        return (string) file_get_contents($path);
    }

    /** HA-25: the bulk reset writes one row per VM, in that VM's own transaction, through the same helper as the single action. */
    public function testTheBulkResetWritesItsRowPerVmInsideThatVmsTransaction(): void
    {
        $code = $this->code('lib/repo/vms_mecm_reset.php');

        preg_match('/function repo_bulk_reset_mecm_ids\(.*?\n}\n/s', $code, $bulk);
        self::assertNotEmpty($bulk, 'repo_bulk_reset_mecm_ids() must exist (zero-match guard)');
        $transaction = strpos($bulk[0], 'repo_transaction(');
        $row = strpos($bulk[0], 'repo_audit_mecm_id_reset(');
        self::assertIsInt($transaction);
        self::assertIsInt($row);
        self::assertLessThan($row, $transaction, 'the reset and its row share the per-VM transaction');

        preg_match('/function repo_audit_mecm_id_reset\(.*?\n}\n/s', $code, $helper);
        self::assertNotEmpty($helper, 'the shared row helper must exist (zero-match guard)');
        self::assertStringContainsString('audit_event_required($db, VIRTUSPHERE_AUDIT_EVENT_VM_MECM_CHANGED', $helper[0]);
    }

    /** HA-13: the bulk summary row is required on both paths, as the single action's row is. */
    public function testTheBulkSummaryIsRequiredOnBothPaths(): void
    {
        $code = $this->code('portal/vms.php');

        self::assertSame(2, substr_count($code, 'audit_event_required($connection, VIRTUSPHERE_AUDIT_EVENT_VM_BULK_CHANGED'));
        self::assertStringNotContainsString('audit_event($connection, VIRTUSPHERE_AUDIT_EVENT_VM_BULK_CHANGED', $code);
    }

    /** HA-24: deletions and creations name what they changed, and the registry allows the key. */
    public function testDeletionsAndCreationsNameWhatTheyChanged(): void
    {
        self::assertStringContainsString("'name' => \$missionName", $this->code('portal/missions.php'));
        self::assertStringContainsString("['name' => \$name]", $this->code('portal/os.php'));
        self::assertStringContainsString("['name' => \$name]", $this->code('portal/vlans.php'));
        self::assertStringContainsString("'name' => \$vmName", $this->code('portal/vms.php'));
        self::assertStringContainsString('$auditContext[\'name\'] = $savedName;', $this->code('lib/vm_save_service.php'));

        $credentials = $this->code('lib/credentials_actions.php');
        self::assertStringContainsString("'host' => (string) (\$createdRow['host'] ?? '')", $credentials);
        self::assertStringContainsString("'host' => (string) (\$before['host'] ?? '')", $credentials);

        $definitions = $this->code('lib/audit_event_definitions.php');
        self::assertStringContainsString("[], ['name'], 'required'", $definitions, 'the catalog deletion event must allow the name');
        self::assertStringContainsString("'selection_cleared', 'name', 'host']", $definitions, 'the credential event must allow the name and the host');
        self::assertStringContainsString("['changes', 'name']", $definitions, 'the VM event must allow the name');
    }

    /** HA-14: the raw log download is recorded before its stream starts. */
    public function testTheRawLogDownloadIsAuditedBeforeItStreams(): void
    {
        $code = $this->code('portal/deploy_log.php');

        $audit = strpos($code, 'audit_event($connection, VIRTUSPHERE_AUDIT_EVENT_DEPLOY_LOG_EXPORTED');
        $stream = strpos($code, 'Content-Disposition: attachment; filename="\' . $filename');
        self::assertIsInt($audit, 'the raw download must write its audit row (zero-match guard)');
        self::assertIsInt($stream, 'the raw download header was not found (zero-match guard)');
        self::assertLessThan($stream, $audit, 'the row must exist before the first byte of the download');
    }

    /** The one machine-API wrapper stays the only function that takes the event code as a parameter. */
    public function testOnlyTheMachineApiWrapperForwardsAVariableEventCode(): void
    {
        $wrappers = [];
        foreach (self::calls('audit_event') as $site) {
            if (self::eventOf($site['call']) === '*' && $site['file'] . '::' . $site['function'] !== self::OWNER) {
                $wrappers[] = $site['file'] . '::' . $site['function'];
            }
        }

        self::assertSame(['lib/machine_api.php::machine_api_audit_warning'], $wrappers);
    }
}

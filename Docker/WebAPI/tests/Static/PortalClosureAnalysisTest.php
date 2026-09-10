<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../Support/PortalClosureSource.php';
require_once __DIR__ . '/../Support/PortalClosureCalls.php';

// Deliberately loaded by PHPUnit, but never a portal dependency or a built-in.
function __portal_test_helper(): void {}

final class PortalClosureAnalysisTest extends TestCase
{
    private string $fixture;
    /** @var list<string> */
    private array $written = [];

    protected function setUp(): void
    {
        $this->fixture = str_replace('\\', '/', sys_get_temp_dir()) . '/portal-closure-' . bin2hex(random_bytes(8));
        mkdir($this->fixture);
        mkdir($this->fixture . '/lib');
        mkdir($this->fixture . '/portal');
        $this->put('lib/helper.php', '<?php function app_helper(): void {}');
        $this->put('lib/bootstrap.php', '<?php require_once __DIR__ . "/helper.php";');
        $this->put('portal/index.php', '<?php require_once __DIR__ . "/../lib/bootstrap.php"; if (false) { app_helper(); }');
    }

    protected function tearDown(): void
    {
        foreach (array_unique($this->written) as $file) { unlink($file); }
        rmdir($this->fixture . '/lib');
        rmdir($this->fixture . '/portal');
        rmdir($this->fixture);
    }

    private function put(string $relative, string $source): void
    {
        $file = $this->fixture . '/' . $relative;
        file_put_contents($file, $source);
        $this->written[] = $file;
    }

    /** @return list<string> */
    private function problems(?PortalClosureSource $source = null, ?string $root = null, string $entry = 'index.php'): array
    {
        $source ??= new PortalClosureSource();
        $root ??= $this->fixture;
        $owners = $source->owners($root);
        $closure = $source->closure($root . '/portal/' . $entry);
        $problems = [];
        foreach ($closure as $file) {
            if (!str_contains($file, '/vendor/')) {
                $calls = new PortalClosureCalls($owners, $source->available($root . '/portal/' . $entry) + $source->available($file), $source->methods, $source);
                $problems = array_merge($problems, $calls->check($source->nodes($file), $file));
            }
        }
        return array_values(array_unique($problems));
    }

    public function testBootstrapClosureIncludesUnexecutedBranchesWithoutExecutingThem(): void
    {
        $this->put('portal/index.php', '<?php require_once __DIR__ . "/../lib/bootstrap.php"; throw new Exception("must never execute"); if (false) { app_helper(); }');
        self::assertSame([], $this->problems());
    }

    public function testRemovedBootstrapEdgeFailsEvenInFalseBranch(): void
    {
        $this->put('lib/bootstrap.php', '<?php // missing dependency');
        self::assertStringContainsString('[portal-closure.missing-owner]', implode("\n", $this->problems()));
    }

    public function testUnknownFunctionsAreNotMistakenForBuiltinsOrLoadedTestHelpers(): void
    {
        $this->put('portal/index.php', '<?php strlen("x"); __portal_test_helper();');
        self::assertStringContainsString('[portal-closure.unknown-function]', implode("\n", $this->problems()));
    }

    public function testCommentsStringsAndMethodsAreNotGlobalCalls(): void
    {
        $this->put('portal/index.php', '<?php // missing_comment();' . "\n" . '$x = "missing_string()"; $object->missing_method(); SomeClass::missing_static(); strlen($x);');
        self::assertSame([], $this->problems());
    }

    public function testNamespacesAndFunctionImportsResolveExactOwner(): void
    {
        $this->put('lib/helper.php', '<?php namespace Fixture; function helper(): void {}');
        $this->put('portal/index.php', '<?php namespace Route; use function Fixture\helper as renamed; require_once __DIR__ . "/../lib/bootstrap.php"; renamed(); \Fixture\helper(); strlen("x");');
        self::assertSame([], $this->problems());
        $this->put('lib/bootstrap.php', '<?php');
        self::assertStringContainsString('fixture\\helper', implode("\n", $this->problems()));
    }

    public function testNamedCallbacksAndFirstClassCallablesNeedTheirOwner(): void
    {
        $this->put('portal/index.php', '<?php array_map("app_helper", []); $f = app_helper(...);');
        self::assertStringContainsString('[portal-closure.missing-owner]', implode("\n", $this->problems()));
    }

    public function testReorderedNamedCallbackArgumentsAndUnresolvedUnpackFailClosed(): void
    {
        $this->put('portal/index.php', '<?php array_filter(mode: 0, array: [], callback: "missing_named_callback");');
        self::assertStringContainsString('missing_named_callback', implode("\n", $this->problems()));
        $this->put('portal/index.php', '<?php array_filter(...$_GET);');
        self::assertStringContainsString('[portal-closure.callback-unpack]', implode("\n", $this->problems()));
    }

    public function testResolvedClosureAndCallableParameterAreCheckedAtCaller(): void
    {
        $this->put('lib/bootstrap.php', '<?php require_once __DIR__ . "/helper.php"; function invoke_callback(callable $callback): void { $callback(); }');
        $this->put('portal/index.php', '<?php require_once __DIR__ . "/../lib/bootstrap.php"; $f = static fn () => app_helper(); invoke_callback($f);');
        self::assertSame([], $this->problems());
        $this->put('portal/index.php', '<?php require_once __DIR__ . "/../lib/bootstrap.php"; invoke_callback("unknown_callback");');
        self::assertStringContainsString('unknown_callback', implode("\n", $this->problems()));
    }

    public function testUnknownDynamicCallAndMissingClosureCaptureFailClosed(): void
    {
        $this->put('portal/index.php', '<?php $callback = $_GET["handler"]; $callback();');
        self::assertStringContainsString('[portal-closure.dynamic-call]', implode("\n", $this->problems()));
        $this->put('portal/index.php', '<?php $f = static fn () => 1; $g = static function () { $f(); };');
        self::assertStringContainsString('[portal-closure.dynamic-call]', implode("\n", $this->problems()));
    }

    public function testCallableReturnAndMethodCallbackCannotHideMissingFunction(): void
    {
        $this->put('portal/index.php', '<?php function factory(): callable { return "missing_return"; } class Action { public function run(callable $f): void { $f(); } } $a = new Action(); $a->run("missing_method_callback");');
        $problems = implode("\n", $this->problems());
        self::assertStringContainsString('missing_return', $problems);
        self::assertStringContainsString('missing_method_callback', $problems);
    }

    public function testCallableDeclarationCannotHideReassignment(): void
    {
        $this->put('portal/index.php', '<?php function invoke_callback(callable $f): void { $f = $_GET["handler"]; $f(); }');
        self::assertStringContainsString('[portal-closure.dynamic-call]', implode("\n", $this->problems()));
    }

    public function testBuiltinParentUsesItsOwnSignatureWithoutExemptingCallbackParents(): void
    {
        $this->put('portal/index.php', '<?php class CallbackOwner { public function __construct(callable $callback) {} } class Problem extends \\RuntimeException { public function __construct() { parent::__construct("An ordinary error message."); } }');
        self::assertSame([], $this->problems());
        $this->put('portal/index.php', '<?php class CallbackOwner { public function __construct(callable $callback) {} } class Problem extends CallbackOwner { public function __construct() { parent::__construct("missing_parent_callback"); } }');
        self::assertStringContainsString('missing_parent_callback', implode("\n", $this->problems()));
        $this->put('portal/index.php', '<?php class Collection extends \\ArrayObject { public function order(): void { parent::uasort("missing_builtin_callback"); } }');
        self::assertStringContainsString('missing_builtin_callback', implode("\n", $this->problems()));
    }

    public function testNullableCallableDefaultRetainsItsContractButChecksTheFallback(): void
    {
        $this->put('portal/index.php', '<?php function invoke_callback(?callable $f = null): void { $f = $f ?? static fn () => 1; $f(); }');
        self::assertSame([], $this->problems());
        $this->put('portal/index.php', '<?php function invoke_callback(?callable $f = null): void { $f = $f ?? "missing_fallback"; $f(); }');
        self::assertStringContainsString('missing_fallback', implode("\n", $this->problems()));
    }

    public function testCallbackCollectionVariableChecksEveryNamedEntry(): void
    {
        $this->put('portal/index.php', '<?php /** @param array<string,callable> $callbacks */ function invoke_map(array $callbacks): void {} $map = ["first" => "missing_first", "second" => "missing_second"]; invoke_map($map);');
        $problems = implode("\n", $this->problems());
        self::assertStringContainsString('missing_first', $problems);
        self::assertStringContainsString('missing_second', $problems);
    }

    public function testOptionalHookIsExemptOnlyInsideItsOwnPositiveGuard(): void
    {
        $this->put('portal/index.php', '<?php if (function_exists("optional_hook")) { optional_hook(); }');
        self::assertSame([], $this->problems());
        $this->put('portal/index.php', '<?php if (function_exists("optional_hook")) { optional_hook(); } optional_hook();');
        self::assertStringContainsString('optional_hook', implode("\n", $this->problems()));
    }

    public function testUnknownDynamicIncludeFailsClosed(): void
    {
        $this->put('portal/index.php', '<?php require $_GET["module"];');
        $this->expectExceptionMessage('[portal-closure.dynamic-require]');
        $this->problems();
    }

    public function testConditionalRequireCannotPretendToLoadAnOwnerUnconditionally(): void
    {
        $this->put('portal/index.php', '<?php if (false) { require_once __DIR__ . "/../lib/bootstrap.php"; } app_helper();');
        self::assertStringContainsString('[portal-closure.missing-owner]', implode("\n", $this->problems()));
    }

    public function testAlternativeIncludeTargetCannotBeSilentlyDiscarded(): void
    {
        $this->put('portal/index.php', '<?php require ($_GET["choose"] ? __DIR__ . "/../lib/helper.php" : $_GET["module"]); app_helper();');
        $this->expectExceptionMessage('[portal-closure.dynamic-require]');
        $this->problems();
    }

    public function testRepeatedIncludeVariableAssignmentFailsClosed(): void
    {
        $this->put('portal/index.php', '<?php $module = $_GET["module"]; if (false) { $module = __DIR__ . "/../lib/helper.php"; } require $module; app_helper();');
        $this->expectExceptionMessage('[portal-closure.dynamic-require]');
        $this->problems();
    }

    public function testZeroEntrypointsAreAContractError(): void
    {
        unlink($this->fixture . '/portal/index.php');
        $this->written = array_values(array_filter($this->written, fn (string $path): bool => !str_ends_with($path, '/portal/index.php')));
        $this->expectExceptionMessage('[portal-closure.zero-match]');
        (new PortalClosureSource())->files($this->fixture . '/portal');
    }

    public function testLocalRequireDoesNotLeakFromAnUncalledFunction(): void
    {
        $this->put('portal/index.php', '<?php function load_helper(): void { require_once __DIR__ . "/../lib/helper.php"; app_helper(); } load_helper();');
        self::assertSame([], $this->problems());
        $this->put('portal/index.php', '<?php function load_helper(): void { require_once __DIR__ . "/../lib/helper.php"; } app_helper();');
        self::assertStringContainsString('[portal-closure.missing-owner]', implode("\n", $this->problems()));
    }

    public function testExistingRegistryIsCheckedAgainstFilesystemInBothDirections(): void
    {
        $this->put('lib/bootstrap.php', '<?php const PANELS = [["partial" => "helper.php"], ["partial" => "bootstrap.php"]];');
        $this->put('portal/index.php', '<?php require_once __DIR__ . "/../lib/bootstrap.php"; foreach (PANELS as $definition) { require __DIR__ . "/../lib/" . $definition["partial"]; }');
        self::assertSame([], $this->problems());
        $this->put('lib/unregistered.php', '<?php function unregistered(): void {}');
        $this->expectExceptionMessage('[portal-closure.registry-drift]');
        $this->problems();
    }

    public function testEmptyDynamicRegistryCannotPass(): void
    {
        $this->put('lib/bootstrap.php', '<?php const PANELS = [];');
        $this->put('portal/index.php', '<?php require_once __DIR__ . "/../lib/bootstrap.php"; foreach (PANELS as $definition) { require __DIR__ . "/../lib/" . $definition["partial"]; }');
        $this->expectExceptionMessage('[portal-closure.zero-match]');
        $this->problems();
    }

    public function testDashboardRegressionMutationRemovesItsRealModuleEdge(): void
    {
        $root = str_replace('\\', '/', dirname(__DIR__, 2));
        $path = $root . '/portal/dashboard.php';
        $original = (string) file_get_contents($path);
        $edge = "require_once __DIR__ . '/../lib/system_status_service_panel.php';";
        self::assertSame(1, substr_count($original, $edge));
        $source = new PortalClosureSource([$path => str_replace($edge, '', $original)]);
        $problems = implode("\n", $this->problems($source, $root, 'dashboard.php'));
        self::assertStringContainsString('[portal-closure.missing-owner]', $problems);
        self::assertStringContainsString('system_status_service_panel.php', $problems);
    }
}

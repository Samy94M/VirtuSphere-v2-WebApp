<?php

declare(strict_types=1);

use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\CloningVisitor;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\NodeVisitorAbstract;
use PhpParser\ParserFactory;
use PhpParser\ConstExprEvaluator;

require_once __DIR__ . '/PortalClosureCalls.php';

/**
 * Parses source only: never includes a portal, bootstrap or owner module.
 *
 * Each file is parsed once for the owner index (lib, portal, function.php) and
 * once when the closure walk first reaches it. That walk records the module
 * edges, the include targets and the call findings of the file, none of which
 * depend on the entry; every later entry reuses them (QL1: 4,327 parses of 383
 * files per run before, 105 to 137 s). No tree or file content outlives the
 * call that parsed it, so the PM1 memory budget holds without a tree cache.
 */
final class PortalClosureSource
{
    /** @var array<string,Node\Expr\Array_> */
    private array $constants = [];
    /** @var array<string,list<Node\Stmt\ClassMethod>> */
    private array $methods = [];
    /** @var array<string,array{file:string,node:Node\Stmt\Function_}>|null */
    private ?array $index = null;
    /** @var array<string,array<int,list<string>>> */
    private array $edges = [];
    /** @var array<string,list<string>> */
    private array $eager = [];
    /** @var array<string,array<string,true>> */
    private array $availableCache = [];
    /** @var array<string,list<string>> walked file => include targets in walk order */
    private array $targets = [];
    /** @var array<string,true> data catalogs already proven to hold data only */
    private array $catalogs = [];
    /** @var array<string,list<array{0:string,1:?string,2:array<string,true>}>|Throwable> walked, not yet resolved */
    private array $facts = [];
    /** @var array<string,array<string,?string>> message => owner file the entry must load, or null */
    private array $findings = [];
    private ?NodeTraverser $detach = null;
    private ?Node\Stmt\Function_ $plain = null;

    /** @param array<string,string> $overrides In-memory mutation fixtures only. */
    public function __construct(private array $overrides = []) {}

    /** @return array<Node> */
    private function nodes(string $file): array
    {
        $nodes = (new ParserFactory())->createForNewestSupportedVersion()->parse($this->overrides[$file] ?? (string) file_get_contents($file));
        return (new NodeTraverser(new NameResolver()))->traverse($nodes ?? []);
    }

    /** @return list<string> */
    public function files(string $dir): array
    {
        if (!is_dir($dir)) {
            throw new RuntimeException('[portal-closure.zero-match] Missing directory: ' . $dir);
        }
        $files = [];
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[] = str_replace('\\', '/', $file->getPathname());
            }
        }
        sort($files);
        if ($files === []) {
            throw new RuntimeException('[portal-closure.zero-match] No PHP sources: ' . $dir);
        }
        return $files;
    }

    /**
     * Problems of each entry's closure, in walk order and without repeats.
     *
     * @param list<string> $entries
     * @return list<string>
     */
    public function problems(string $root, array $entries): array
    {
        $this->owners($root);
        $problems = [];
        foreach ($entries as $entry) {
            $closure = $this->closure($entry);
            $loaded = $this->available($entry);
            foreach ($closure as $file) {
                if (str_contains($file, '/vendor/')) {
                    continue;
                }
                foreach ($this->findings($file) as $message => $owner) {
                    if ($owner === null || !isset($loaded[$owner])) {
                        $problems[] = $message;
                    }
                }
            }
        }
        return array_values(array_unique($problems));
    }

    /** @return array<string,array{file:string,node:Node\Stmt\Function_}> */
    public function owners(string $root): array
    {
        $owners = [];
        foreach (array_merge($this->files($root . '/lib'), $this->files($root . '/portal'), [$root . '/function.php']) as $file) {
            if (!is_file($file)) {
                continue;
            }
            $nodes = $this->nodes($file);
            $finder = new NodeFinder();
            foreach ($finder->findInstanceOf($nodes, Node\Stmt\Const_::class) as $declaration) {
                foreach ($declaration->consts as $constant) {
                    // registryPaths() reads array registries only.
                    $registry = $constant->value instanceof Node\Expr\Array_ ? $this->detached($constant->value) : null;
                    if ($registry instanceof Node\Expr\Array_) {
                        $this->constants[(string) ($constant->namespacedName ?? $constant->name)] = $registry;
                    }
                }
            }
            foreach ($finder->findInstanceOf($nodes, Node\Stmt\ClassMethod::class) as $method) {
                $this->methods[strtolower((string) $method->name)][] = $this->signature($method);
            }
            foreach ($finder->findInstanceOf($nodes, Node\Stmt\Function_::class) as $node) {
                $name = strtolower((string) ($node->namespacedName ?? $node->name));
                if (isset($owners[$name]) && $owners[$name]['file'] !== $file) {
                    throw new RuntimeException('[portal-closure.duplicate-owner] ' . $name);
                }
                $owners[$name] = ['file' => $file, 'node' => $this->signature($node)];
            }
        }
        if ($owners === []) {
            throw new RuntimeException('[portal-closure.zero-match] Empty function owner index');
        }
        return $this->index = $owners;
    }

    /**
     * PortalClosureCalls reads a signature only for callable parameters
     * (type or @param) and callable returns. A declaration without either
     * gets one shared empty signature: its checks find no callable position
     * with the full one either. The others are detached copies without body,
     * defaults and source positions. PM1: index of the library 22 to 7 MB.
     */
    private function signature(Node\Stmt\Function_|Node\Stmt\ClassMethod $node): Node\Stmt\Function_|Node\Stmt\ClassMethod
    {
        if (!$this->hasCallableContract($node)) {
            return $this->plain ??= new Node\Stmt\Function_('plain');
        }
        $signature = clone $node;
        $signature->stmts = [];
        $signature = $this->detached($signature);
        if (!$signature instanceof Node\Stmt\Function_ && !$signature instanceof Node\Stmt\ClassMethod) {
            throw new LogicException('[portal-closure.signature] cloning changed the declaration type');
        }
        foreach ($signature->params as $param) { $param->default = null; }
        $doc = $node->getDocComment();
        $signature->setAttributes($doc === null ? [] : ['comments' => [$doc]]);
        return $signature;
    }

    /** Superset of what PortalClosureCalls treats as callable: any mention, any case. */
    private function hasCallableContract(Node\Stmt\Function_|Node\Stmt\ClassMethod $node): bool
    {
        if (str_contains(strtolower((string) $node->getDocComment()), 'callable')) {
            return true;
        }
        $types = array_map(static fn (Node\Param $param): ?Node => $param->type, $node->getParams());
        $types[] = $node->getReturnType();
        foreach (array_filter($types) as $type) {
            $mention = (new NodeFinder())->findFirst([$type], static fn (Node $part): bool
                => ($part instanceof Node\Identifier || $part instanceof Node\Name) && str_contains(strtolower((string) $part), 'callable'));
            if ($mention !== null) {
                return true;
            }
        }
        return false;
    }

    /** Deep copy without attributes; the parsed tree stays untouched. */
    private function detached(Node $node): Node
    {
        $this->detach ??= new NodeTraverser(new CloningVisitor(), new class extends NodeVisitorAbstract {
            public function leaveNode(Node $node): null { $node->setAttributes([]); return null; }
        });
        return $this->detach->traverse([$node])[0];
    }

    /** @return list<string> */
    public function closure(string $entry): array
    {
        $seen = [];
        $pending = [$entry];
        while ($pending !== []) {
            $file = array_pop($pending);
            $real = realpath($file);
            if ($real === false) {
                throw new RuntimeException('[portal-closure.missing-require] ' . $file);
            }
            $file = str_replace('\\', '/', $real);
            if (isset($seen[$file])) {
                continue;
            }
            $seen[$file] = true;
            // Composer is a separate, lockfile-owned autoloader. Its global
            // functions are never treated as PHP built-ins by the call checker.
            if (str_contains($file, '/vendor/')) {
                continue;
            }
            $pending = array_merge($pending, $this->targets[$file] ??= $this->walk($file));
        }
        return array_keys($seen);
    }

    /**
     * The one parse of a reached file: module edges, include targets in the
     * order the walk visits them, and the file's call findings.
     *
     * @return list<string>
     */
    private function walk(string $file): array
    {
        $nodes = $this->nodes($file);
        $finder = new NodeFinder();
        $eagerIncludes = [];
        foreach ($nodes as $statement) {
            if ($statement instanceof Node\Stmt\Expression && $statement->expr instanceof Node\Expr\Include_) {
                $eagerIncludes[spl_object_id($statement->expr)] = true;
            }
            if ($statement instanceof Node\Stmt\Namespace_) {
                foreach ($statement->stmts as $child) {
                    if ($child instanceof Node\Stmt\Expression && $child->expr instanceof Node\Expr\Include_) {
                        $eagerIncludes[spl_object_id($child->expr)] = true;
                    }
                }
            }
        }
        $variables = [];
        $ambiguousVariables = [];
        foreach ($finder->findInstanceOf($nodes, Node\Expr\Assign::class) as $assignment) {
            if ($assignment->var instanceof Node\Expr\Variable && is_string($assignment->var->name)) {
                if (isset($variables[$assignment->var->name])) { $ambiguousVariables[$assignment->var->name] = true; }
                $variables[$assignment->var->name] = $assignment->expr;
            }
        }
        foreach ($finder->findInstanceOf($nodes, Node\Stmt\Foreach_::class) as $loop) {
            if ($loop->valueVar instanceof Node\Expr\Variable && is_string($loop->valueVar->name)) {
                if (isset($variables[$loop->valueVar->name])) { $ambiguousVariables[$loop->valueVar->name] = true; }
                $variables[$loop->valueVar->name] = $loop->expr;
            }
        }
        foreach ($ambiguousVariables as $name => $_) { unset($variables[$name]); }
        $targets = [];
        foreach ($finder->findInstanceOf($nodes, Node\Expr\Include_::class) as $include) {
            $registryPaths = $this->registryPaths($include->expr, dirname($file), $variables);
            if ($registryPaths !== null) {
                $this->recordEdges($file, $include, $registryPaths, isset($eagerIncludes[spl_object_id($include)]));
                $targets = array_merge($targets, $registryPaths);
                continue;
            }
            $path = $this->path($include->expr, dirname($file), $variables);
            if ($path === null) {
                throw new RuntimeException('[portal-closure.dynamic-require] ' . $file . ':' . $include->getStartLine());
            }
            if (str_contains($path, '*')) {
                $matches = glob($path) ?: [];
                if ($matches === []) {
                    throw new RuntimeException('[portal-closure.zero-match] ' . $path);
                }
                foreach ($matches as $match) {
                    $this->catalog($match);
                }
            } else {
                $this->recordEdges($file, $include, [$path], isset($eagerIncludes[spl_object_id($include)]));
                $targets[] = $path;
            }
        }
        if ($this->index !== null) {
            // A call check that fails surfaces where the per-entry check
            // surfaced it: after the walk, at this file's place in the closure.
            try {
                $this->facts[$file] = (new PortalClosureCalls($this->index, $this->methods, $this))->findings($nodes, $file);
            } catch (Throwable $error) {
                $this->facts[$file] = $error;
            }
        }
        return $targets;
    }

    /**
     * Dynamic catalogs may contain data only. A new function, call or
     * statement cannot silently escape owner indexing.
     */
    private function catalog(string $match): void
    {
        if (isset($this->catalogs[$match])) {
            return;
        }
        $nodes = $this->nodes($match);
        foreach ($nodes as $statement) {
            if (!$statement instanceof Node\Stmt\Return_ && !$statement instanceof Node\Stmt\Declare_) {
                throw new RuntimeException('[portal-closure.dynamic-code] ' . $match);
            }
            if ($statement instanceof Node\Stmt\Return_ && $statement->expr !== null) {
                try { (new ConstExprEvaluator())->evaluateDirectly($statement->expr); }
                catch (Throwable) { throw new RuntimeException('[portal-closure.dynamic-code] ' . $match); }
            }
            if ($statement instanceof Node\Stmt\Declare_ && $statement->stmts !== null) {
                throw new RuntimeException('[portal-closure.dynamic-code] ' . $match);
            }
        }
        if ((new NodeFinder())->findInstanceOf($nodes, Node\Expr\FuncCall::class) !== []) {
            throw new RuntimeException('[portal-closure.dynamic-code] ' . $match);
        }
        $this->catalogs[$match] = true;
    }

    /**
     * Findings of a walked file, resolved against its own unconditional
     * closure and the edges it passed before each call. What remains depends
     * only on the entry: a missing-owner finding keeps the owner file the
     * entry would have to load. Same message, same owner: the first occurrence
     * that stays open keeps its place.
     *
     * Valid once the walk of an entry containing $file is complete, because
     * available() follows edges of every file it reaches.
     *
     * @return array<string,?string>
     */
    private function findings(string $file): array
    {
        if (isset($this->findings[$file])) {
            return $this->findings[$file];
        }
        $facts = $this->facts[$file] ?? throw new LogicException('[portal-closure.order] owners() and closure() must run before findings: ' . $file);
        if ($facts instanceof Throwable) {
            throw $facts;
        }
        $own = $this->available($file);
        $open = [];
        foreach ($facts as [$message, $owner, $passed]) {
            if ($owner !== null) {
                if (isset($own[$owner])) {
                    continue;
                }
                foreach ($passed as $dependency => $_) {
                    if (isset($this->available($dependency)[$owner])) {
                        continue 2;
                    }
                }
            }
            if (!array_key_exists($message, $open)) {
                $open[$message] = $owner;
            }
        }
        unset($this->facts[$file]);
        return $this->findings[$file] = $open;
    }

    /** @param list<string> $paths */
    private function recordEdges(string $file, Node\Expr\Include_ $include, array $paths, bool $eager): void
    {
        $resolved = [];
        foreach ($paths as $path) {
            $real = realpath($path);
            if ($real === false) { throw new RuntimeException('[portal-closure.missing-require] ' . $path); }
            $resolved[] = str_replace('\\', '/', $real);
        }
        if (($this->edges[$file][$include->getStartFilePos()] ?? null) === $resolved) { return; }
        $this->availableCache = [];
        $this->edges[$file][$include->getStartFilePos()] = $resolved;
        if ($eager) { $this->eager[$file] = array_values(array_unique(array_merge($this->eager[$file] ?? [], $resolved))); }
    }

    /** @return list<string> */
    public function dependencies(string $file, Node\Expr\Include_ $include): array
    {
        return $this->edges[$file][$include->getStartFilePos()] ?? [];
    }

    /** @return array<string,true> Unconditional module edges only. */
    public function available(string $file): array
    {
        if (isset($this->availableCache[$file])) { return $this->availableCache[$file]; }
        $seen = [];
        $pending = [$file];
        while ($pending !== []) {
            $current = array_pop($pending);
            if (isset($seen[$current])) { continue; }
            $seen[$current] = true;
            $pending = array_merge($pending, $this->eager[$current] ?? []);
        }
        return $this->availableCache[$file] = $seen;
    }

    /** @param array<string,Node\Expr> $variables @return list<string>|null */
    private function registryPaths(Node\Expr $expr, string $dir, array $variables): ?array
    {
        $dimensions = (new NodeFinder())->findInstanceOf([$expr], Node\Expr\ArrayDimFetch::class);
        foreach ($dimensions as $dimension) {
            if (!$dimension->var instanceof Node\Expr\Variable || !is_string($dimension->var->name)) { continue; }
            $name = $dimension->var->name;
            $registry = $variables[$name] ?? null;
            if (!$registry instanceof Node\Expr\ConstFetch) { continue; }
            $values = $this->constants[(string) $registry->name] ?? null;
            if (!$values instanceof Node\Expr\Array_) { continue; }
            $paths = [];
            foreach ($values->items as $item) {
                $rowVariables = $variables;
                $rowVariables[$name] = $item->value;
                $path = $this->path($expr, $dir, $rowVariables);
                if ($path === null) { throw new RuntimeException('[portal-closure.registry-path] ' . $registry->name); }
                $paths[] = $path;
            }
            if ($paths === []) { throw new RuntimeException('[portal-closure.zero-match] ' . $registry->name); }
            $expected = array_map('basename', $paths);
            $actual = array_map('basename', glob(dirname($paths[0]) . '/*.php') ?: []);
            sort($expected);
            sort($actual);
            if ($expected !== $actual) { throw new RuntimeException('[portal-closure.registry-drift] ' . $registry->name); }
            return $paths;
        }
        return null;
    }

    /** @param array<string,Node\Expr> $variables */
    private function path(Node\Expr $expr, string $dir, array $variables, int $depth = 0, bool $glob = false): ?string
    {
        if ($depth > 12) {
            return null;
        }
        if ($expr instanceof Node\Scalar\String_) {
            return $expr->value;
        }
        if ($expr instanceof Node\Scalar\MagicConst\Dir) {
            return $dir;
        }
        if ($expr instanceof Node\Expr\Variable && is_string($expr->name)) {
            $value = isset($variables[$expr->name])
                ? $this->path($variables[$expr->name], $dir, $variables, $depth + 1, $glob)
                : null;
            return $value ?? ($glob ? '*' : null);
        }
        if ($expr instanceof Node\Expr\BinaryOp\Concat) {
            $left = $this->path($expr->left, $dir, $variables, $depth + 1, $glob);
            $right = $this->path($expr->right, $dir, $variables, $depth + 1, $glob);
            return $left !== null && $right !== null ? $left . $right : null;
        }
        if ($expr instanceof Node\Expr\ArrayDimFetch && $expr->var instanceof Node\Expr\Variable
            && is_string($expr->var->name) && $expr->dim instanceof Node\Scalar\String_) {
            $array = $variables[$expr->var->name] ?? null;
            if ($array instanceof Node\Expr\Array_) {
                foreach ($array->items as $item) {
                    if ($item->key instanceof Node\Scalar\String_ && $item->key->value === $expr->dim->value) {
                        return $this->path($item->value, $dir, $variables, $depth + 1, $glob);
                    }
                }
            }
        }
        if ($expr instanceof Node\Expr\BinaryOp\Coalesce || $expr instanceof Node\Expr\Ternary) {
            $first = $expr instanceof Node\Expr\Ternary ? $expr->cond : $expr->left;
            $last = $expr instanceof Node\Expr\Ternary ? $expr->else : $expr->right;
            // glob(...) ?: [] describes an empty-or-complete data catalog.
            // Other alternatives must resolve identically, never just take the
            // first branch and silently ignore a dynamic include target.
            if ($expr instanceof Node\Expr\Ternary && $expr->if === null
                && $first instanceof Node\Expr\FuncCall && $first->name instanceof Node\Name
                && (string) $first->name === 'glob' && $last instanceof Node\Expr\Array_ && $last->items === []) {
                return $this->path($first, $dir, $variables, $depth + 1, $glob);
            }
            $first = $expr instanceof Node\Expr\Ternary ? ($expr->if ?? $expr->cond) : $expr->left;
            $left = $this->path($first, $dir, $variables, $depth + 1, $glob);
            $right = $this->path($last, $dir, $variables, $depth + 1, $glob);
            return $left !== null && $left === $right ? $left : null;
        }
        if ($expr instanceof Node\Expr\FuncCall && $expr->name instanceof Node\Name && (string) $expr->name === 'glob') {
            return $this->path($expr->getArgs()[0]->value, $dir, $variables, $depth + 1, true);
        }
        return null;
    }
}

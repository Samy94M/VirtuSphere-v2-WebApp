<?php

declare(strict_types=1);

use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\ParserFactory;
use PhpParser\ConstExprEvaluator;

/** Parses source only: never includes a portal, bootstrap or owner module. */
final class PortalClosureSource
{
    /** @var array<string,array<Node>> */
    private array $cache = [];
    /** @var array<string,Node\Expr> */
    private array $constants = [];
    /** @var array<string,list<Node\Stmt\ClassMethod>> */
    public array $methods = [];
    /** @var array<string,array<int,list<string>>> */
    private array $edges = [];
    /** @var array<string,list<string>> */
    private array $eager = [];
    /** @var array<string,array<string,true>> */
    private array $availableCache = [];

    /** @param array<string,string> $overrides In-memory mutation fixtures only. */
    public function __construct(private array $overrides = []) {}

    /** @return array<Node> */
    public function nodes(string $file): array
    {
        if (!isset($this->cache[$file])) {
            $nodes = (new ParserFactory())->createForNewestSupportedVersion()->parse($this->overrides[$file] ?? (string) file_get_contents($file));
            $traverser = new NodeTraverser(new NameResolver());
            $this->cache[$file] = $traverser->traverse($nodes ?? []);
            // Keep the normal PHPUnit memory limit: owner indexing covers the
            // entire library, but only a small working set needs full bodies.
            if (count($this->cache) > 8) { array_shift($this->cache); }
        }
        return $this->cache[$file];
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

    /** @return array<string,array{file:string,node:Node\Stmt\Function_}> */
    public function owners(string $root): array
    {
        $owners = [];
        foreach (array_merge($this->files($root . '/lib'), $this->files($root . '/portal'), [$root . '/function.php']) as $file) {
            if (!is_file($file)) {
                continue;
            }
            foreach ((new NodeFinder())->findInstanceOf($this->nodes($file), Node\Stmt\Const_::class) as $declaration) {
                foreach ($declaration->consts as $constant) {
                    $this->constants[(string) ($constant->namespacedName ?? $constant->name)] = $constant->value;
                }
            }
            foreach ((new NodeFinder())->findInstanceOf($this->nodes($file), Node\Stmt\ClassMethod::class) as $method) {
                $signature = clone $method;
                $signature->stmts = [];
                $this->methods[strtolower((string) $method->name)][] = $signature;
            }
            foreach ((new NodeFinder())->findInstanceOf($this->nodes($file), Node\Stmt\Function_::class) as $node) {
                $name = strtolower((string) ($node->namespacedName ?? $node->name));
                if (isset($owners[$name]) && $owners[$name]['file'] !== $file) {
                    throw new RuntimeException('[portal-closure.duplicate-owner] ' . $name);
                }
                $signature = clone $node;
                $signature->stmts = [];
                $owners[$name] = ['file' => $file, 'node' => $signature];
            }
        }
        if ($owners === []) {
            throw new RuntimeException('[portal-closure.zero-match] Empty function owner index');
        }
        return $owners;
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
            foreach ($finder->findInstanceOf($nodes, Node\Expr\Include_::class) as $include) {
                $registryPaths = $this->registryPaths($include->expr, dirname($file), $variables);
                if ($registryPaths !== null) {
                    $this->recordEdges($file, $include, $registryPaths, isset($eagerIncludes[spl_object_id($include)]));
                    $pending = array_merge($pending, $registryPaths);
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
                    // Dynamic catalogs may contain data only. A new function,
                    // call or statement cannot silently escape owner indexing.
                    foreach ($matches as $match) {
                        foreach ($this->nodes($match) as $statement) {
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
                        if ($finder->findInstanceOf($this->nodes($match), Node\Expr\FuncCall::class) !== []) {
                            throw new RuntimeException('[portal-closure.dynamic-code] ' . $match);
                        }
                    }
                } else {
                    $this->recordEdges($file, $include, [$path], isset($eagerIncludes[spl_object_id($include)]));
                    $pending[] = $path;
                }
            }
        }
        return array_keys($seen);
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

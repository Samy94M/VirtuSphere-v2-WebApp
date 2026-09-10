<?php

declare(strict_types=1);

use PhpParser\Node;
use PhpParser\NodeFinder;

/** Function-call analysis; methods are never mistaken for global functions. */
final class PortalClosureCalls
{
    /** @var list<string> */
    private array $problems = [];
    /** @var array<string,true> */
    private array $builtins;

    /** @param array<string,array{file:string,node:Node\Stmt\Function_}> $owners */
    public function __construct(private array $owners, private array $available, private array $methods = [], private ?PortalClosureSource $source = null)
    {
        $this->builtins = array_fill_keys(get_defined_functions()['internal'], true);
    }

    /** @param array<Node> $nodes @return list<string> */
    public function check(array $nodes, string $file): array
    {
        $this->problems = [];
        $this->scope($nodes, $file, []);
        return array_values(array_unique($this->problems));
    }

    /** @param array<Node> $nodes @param array<string,list<Node\Expr>|true> $inherited */
    private function scope(array $nodes, string $file, array $inherited, ?Node\FunctionLike $owner = null, ?string $parentClass = null): void
    {
        $outerAvailable = $this->available;
        $symbols = $inherited;
        if ($owner !== null) {
            foreach ($owner->getParams() as $param) {
                if ($param->var instanceof Node\Expr\Variable && is_string($param->var->name)) {
                    $type = $this->typeText($param->type);
                    $doc = (string) $owner->getDocComment();
                    // Existing owner contracts are the declaration: a callable
                    // parameter or a documented callable collection. Caller
                    // arguments are checked below rather than allowlisting names.
                    if ($type === 'callable' || $type === 'Closure' || preg_match('/@param[^\r\n]*callable[^\r\n]*\$' . preg_quote($param->var->name, '/') . '\b/', $doc)) {
                        $symbols[$param->var->name] = true;
                    }
                }
            }
        }
        $collect = function (Node $node) use (&$collect, &$symbols): void {
            if ($node instanceof Node\FunctionLike || $node instanceof Node\Stmt\ClassLike) {
                return;
            }
            if ($node instanceof Node\Expr\Assign && $node->var instanceof Node\Expr\Variable && is_string($node->var->name)) {
                $key = $node->var->name;
                $value = $node->expr;
                if (($symbols[$key] ?? null) === true && $value instanceof Node\Expr\BinaryOp\Coalesce
                    && $value->left instanceof Node\Expr\Variable && $value->left->name === $key) {
                    // The declared nullable callable remains trusted on the
                    // left; the newly assigned fallback still needs proof.
                    $value = $value->right;
                }
                if (($symbols[$key] ?? null) === true) { $symbols[$key] = []; }
                $symbols[$key][] = $value;
            }
            foreach ($node->getSubNodeNames() as $key) {
                foreach (is_array($node->$key) ? $node->$key : [$node->$key] as $child) {
                    if ($child instanceof Node) {
                        $collect($child);
                    }
                }
            }
        };
        foreach ($nodes as $node) {
            $collect($node);
        }
        $visit = function (Node $node, array $guards = []) use (&$visit, $symbols, $file, $parentClass): void {
            if ($node instanceof Node\Stmt\Class_) {
                $this->scope($node->stmts, $file, [], null, $node->extends === null ? null : (string) $node->extends);
                return;
            }
            if ($node instanceof Node\Expr\Include_ && $this->source !== null) {
                foreach ($this->source->dependencies($file, $node) as $dependency) {
                    $this->available += $this->source->available($dependency);
                }
            }
            if ($node instanceof Node\FunctionLike) {
                $body = $node instanceof Node\Expr\ArrowFunction ? [$node->expr] : ($node->getStmts() ?? []);
                $capture = $node instanceof Node\Expr\ArrowFunction ? $symbols : [];
                if ($node instanceof Node\Expr\Closure) {
                    foreach ($node->uses as $use) {
                        if (is_string($use->var->name) && isset($symbols[$use->var->name])) {
                            $value = $symbols[$use->var->name];
                            // A selected comparator carries the callable-map
                            // contract across use(), not the map variable itself.
                            $collectionValue = is_array($value) && $value !== [];
                            foreach (is_array($value) ? $value : [] as $candidate) {
                                $collectionValue = $collectionValue && $candidate instanceof Node\Expr\ArrayDimFetch
                                    && $candidate->var instanceof Node\Expr\Variable && is_string($candidate->var->name)
                                    && ($symbols[$candidate->var->name] ?? null) === true;
                            }
                            $capture[$use->var->name] = $collectionValue ? true : $value;
                        }
                    }
                }
                $this->scope($body, $file, $capture, $node, $parentClass);
                return;
            }
            if (($node instanceof Node\Expr\MethodCall || $node instanceof Node\Expr\StaticCall)
                && $node->name instanceof Node\Identifier && !$node->isFirstClassCallable()) {
                $methods = $this->methods[strtolower((string) $node->name)] ?? [];
                // parent:: refers to this class's declared parent. A built-in
                // parent's signature cannot be borrowed from an unrelated user
                // method with the same name (notably callable constructors).
                if ($node instanceof Node\Expr\StaticCall && $node->class instanceof Node\Name
                    && strtolower((string) $node->class) === 'parent' && $parentClass !== null
                    && class_exists($parentClass, false) && (new ReflectionClass($parentClass))->isInternal()
                    && method_exists($parentClass, (string) $node->name)) {
                    $methods = [new ReflectionMethod($parentClass, (string) $node->name)];
                }
                foreach ($methods as $method) {
                    $this->arguments('@method:' . $node->name, $node->getArgs(), $symbols, $file, $method);
                }
            }
            if ($node instanceof Node\Expr\FuncCall) {
                if ($node->name instanceof Node\Name) {
                    $name = strtolower((string) $node->name);
                    $fallback = $node->name->getAttribute('namespacedName');
                    if ($fallback instanceof Node\Name && isset($this->owners[strtolower((string) $fallback)])) {
                        $name = strtolower((string) $fallback);
                    }
                    $this->named($name, $file, $node->getStartLine(), $guards);
                    if (!$node->isFirstClassCallable()) {
                        $this->arguments($name, $node->getArgs(), $symbols, $file);
                    }
                } elseif (!$this->callable($node->name, $symbols, $file, 0)) {
                    $this->problems[] = '[portal-closure.dynamic-call] ' . $file . ':' . $node->getStartLine();
                }
            }
            // Optional diagnostic hooks remain optional only inside the exact
            // positive function_exists branch; the same call elsewhere fails.
            if ($node instanceof Node\Stmt\If_ || $node instanceof Node\Expr\Ternary) {
                $branchAvailable = $this->available;
                $cond = $node->cond;
                if ($cond instanceof Node\Expr\FuncCall && $cond->name instanceof Node\Name && (string) $cond->name === 'function_exists'
                    && ($cond->getArgs()[0]->value ?? null) instanceof Node\Scalar\String_) {
                    $guardName = strtolower($cond->getArgs()[0]->value->value);
                    $visit($cond, $guards);
                    $branch = $node instanceof Node\Stmt\If_ ? $node->stmts : [$node->if];
                    foreach ($branch as $child) {
                        if ($child instanceof Node) {
                            $visit($child, $guards + [$guardName => true]);
                        }
                    }
                    $this->available = $branchAvailable;
                    if ($node instanceof Node\Stmt\If_) {
                        foreach ($node->elseifs as $child) { $visit($child, $guards); }
                        if ($node->else !== null) { $visit($node->else, $guards); }
                    } else { $visit($node->else, $guards); }
                    $this->available = $branchAvailable;
                    return;
                }
            }
            $branching = $node instanceof Node\Stmt\If_ || $node instanceof Node\Stmt\ElseIf_
                || $node instanceof Node\Expr\Ternary || $node instanceof Node\Stmt\Switch_
                || $node instanceof Node\Stmt\Case_ || $node instanceof Node\Stmt\Foreach_
                || $node instanceof Node\Stmt\For_ || $node instanceof Node\Stmt\While_
                || $node instanceof Node\Stmt\Do_ || $node instanceof Node\Stmt\TryCatch
                || $node instanceof Node\Stmt\Catch_ || $node instanceof Node\Expr\Match_
                || $node instanceof Node\MatchArm;
            $beforeBranch = $this->available;
            foreach ($node->getSubNodeNames() as $key) {
                foreach (is_array($node->$key) ? $node->$key : [$node->$key] as $child) {
                    if ($child instanceof Node) { $visit($child, $guards); }
                }
                if ($branching) { $this->available = $beforeBranch; }
            }
        };
        foreach ($nodes as $node) { $visit($node); }
        if ($owner !== null && str_contains($this->typeText($owner->getReturnType()), 'callable')) {
            foreach ((new NodeFinder())->findInstanceOf($nodes, Node\Stmt\Return_::class) as $return) {
                if ($return->expr !== null && !$this->callable($return->expr, $symbols, $file, 0)) {
                    $this->problems[] = '[portal-closure.callback-return] ' . $file . ':' . $return->getStartLine();
                }
            }
        }
        $this->available = $outerAvailable;
    }

    /** @param array<string,true> $guards */
    private function named(string $name, string $file, int $line, array $guards = []): void
    {
        $name = strtolower(ltrim($name, '\\'));
        if (isset($guards[$name]) || isset($this->builtins[$name])) { return; }
        if (!isset($this->owners[$name])) {
            $this->problems[] = '[portal-closure.unknown-function] ' . $file . ':' . $line . ' ' . $name;
        } elseif (!isset($this->available[$this->owners[$name]['file']])) {
            $this->problems[] = '[portal-closure.missing-owner] ' . $file . ':' . $line . ' ' . $name . ' requires ' . $this->owners[$name]['file'];
        }
    }

    /** @param list<Node\Arg> $args @param array<string,list<Node\Expr>|true> $symbols */
    private function arguments(string $name, array $args, array $symbols, string $file, Node\FunctionLike|ReflectionFunctionAbstract|null $method = null): void
    {
        $positions = [];
        $parameterNames = [];
        if (isset($this->builtins[$name]) || $method instanceof ReflectionFunctionAbstract) {
            $reflection = $method instanceof ReflectionFunctionAbstract ? $method : new ReflectionFunction($name);
            foreach ($reflection->getParameters() as $position => $param) {
                if (str_contains((string) $param->getType(), 'callable')) { $positions[$position] = false; }
                $parameterNames[$position] = $param->getName();
            }
        } elseif (isset($this->owners[$name]) || $method !== null) {
            $owner = $method ?? $this->owners[$name]['node'];
            foreach ($owner->getParams() as $position => $param) {
                $parameterNames[$position] = $param->var instanceof Node\Expr\Variable ? $param->var->name : null;
                if (str_contains($this->typeText($param->type), 'callable')) { $positions[$position] = false; }
                if ($param->var instanceof Node\Expr\Variable && is_string($param->var->name)
                    && preg_match('/@param[^\r\n]*callable[^\r\n]*\$' . preg_quote($param->var->name, '/') . '\b/', (string) $owner->getDocComment())) {
                    $positions[$position] = $this->typeText($param->type) === 'array';
                }
            }
        }
        foreach ($positions as $position => $collection) {
            $argument = isset($args[$position]) && $args[$position]->name === null ? $args[$position] : null;
            foreach ($args as $candidate) {
                if ($candidate->name !== null && (string) $candidate->name === ($parameterNames[$position] ?? null)) { $argument = $candidate; }
            }
            if ($argument === null) {
                foreach ($args as $candidate) {
                    if ($candidate->unpack) { $this->problems[] = '[portal-closure.callback-unpack] ' . $file . ':' . $candidate->getStartLine(); }
                }
                continue;
            }
            if ($argument->unpack) {
                $this->problems[] = '[portal-closure.callback-unpack] ' . $file . ':' . $argument->getStartLine();
                continue;
            }
            $expr = $argument->value;
            $valid = $collection ? $this->collection($expr, $symbols, $file, 0) : $this->callable($expr, $symbols, $file, 0);
            if (!$valid) {
                $this->problems[] = '[portal-closure.callback] ' . $file . ':' . $expr->getStartLine() . ' ' . $name;
            }
        }
    }

    /** @param array<string,list<Node\Expr>|true> $symbols */
    private function collection(Node\Expr $expr, array $symbols, string $file, int $depth): bool
    {
        if ($depth > 12) { return false; }
        if ($expr instanceof Node\Expr\Array_) {
            foreach ($expr->items as $item) {
                if ($item->unpack || !$this->callable($item->value, $symbols, $file, $depth + 1)) { return false; }
            }
            return true;
        }
        if ($expr instanceof Node\Expr\Variable && is_string($expr->name) && isset($symbols[$expr->name])) {
            if ($symbols[$expr->name] === true) { return true; }
            foreach ($symbols[$expr->name] as $value) {
                if (!$this->collection($value, $symbols, $file, $depth + 1)) { return false; }
            }
            return true;
        }
        return false;
    }

    /** @param array<string,list<Node\Expr>|true> $symbols */
    private function callable(Node\Expr $expr, array $symbols, string $file, int $depth): bool
    {
        if ($depth > 12) { return false; }
        if ($expr instanceof Node\Scalar\String_) {
            if (!str_contains($expr->value, '::')) { $this->named($expr->value, $file, $expr->getStartLine()); }
            return true;
        }
        if ($expr instanceof Node\Expr\Closure || $expr instanceof Node\Expr\ArrowFunction) { return true; }
        if ($expr instanceof Node\Expr\ConstFetch && strtolower((string) $expr->name) === 'null') { return true; }
        if ($expr instanceof Node\Expr\Variable && is_string($expr->name) && isset($symbols[$expr->name])) {
            if ($symbols[$expr->name] === true) { return true; }
            foreach ($symbols[$expr->name] as $value) {
                if (!$this->callable($value, $symbols, $file, $depth + 1)) { return false; }
            }
            return true;
        }
        if ($expr instanceof Node\Expr\ArrayDimFetch) { return $this->callable($expr->var, $symbols, $file, $depth + 1); }
        if ($expr instanceof Node\Expr\FuncCall && $expr->name instanceof Node\Name) {
            if ($expr->isFirstClassCallable()) { return true; }
            $owner = $this->owners[strtolower((string) $expr->name)]['node'] ?? null;
            return $owner !== null && str_contains($this->typeText($owner->returnType), 'callable');
        }
        if ($expr instanceof Node\Expr\Ternary) {
            return $expr->if !== null && $this->callable($expr->if, $symbols, $file, $depth + 1)
                && $this->callable($expr->else, $symbols, $file, $depth + 1);
        }
        if ($expr instanceof Node\Expr\Array_ && count($expr->items) === 2) {
            // A method callback is a method, not a global function. Its body is
            // still walked when its declaring module belongs to this closure.
            return $expr->items[1]->value instanceof Node\Scalar\String_;
        }
        return false;
    }

    private function typeText(?Node $type): string
    {
        if ($type instanceof Node\NullableType) { return $this->typeText($type->type); }
        if ($type instanceof Node\UnionType || $type instanceof Node\IntersectionType) {
            return implode('|', array_map(fn (Node $part): string => $this->typeText($part), $type->types));
        }
        return $type instanceof Node\Name || $type instanceof Node\Identifier ? (string) $type : '';
    }
}

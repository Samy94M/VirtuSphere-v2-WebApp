<?php

declare(strict_types=1);

/** Shared static closure analysis for CLI and portal entrypoints. */
trait RequireClosureAnalysis
{
    /**
     * @param list<string> $entrypoints
     * @param array<string,list<string>> $definedSomewhere
     * @param array<string,string> $guarded
     * @return list<string>
     */
    private function analyse(string $root, array $entrypoints, array $definedSomewhere, array $guarded): array
    {
        $problems = [];
        foreach ($entrypoints as $relative) {
            $entry = $root . '/' . $relative;
            self::assertFileExists($entry, $relative . ' is registered as an entrypoint but does not exist.');

            $closure = $this->requireClosure($entry);

            $available = [];
            foreach ($closure as $file) {
                $available += $this->definedFunctions($file);
            }

            foreach ($closure as $file) {
                foreach (array_keys($this->calledFunctions($file)) as $function) {
                    if (isset($available[$function]) || !isset($definedSomewhere[$function]) || isset($guarded[$function])) {
                        continue;
                    }
                    $problems[] = sprintf(
                        '%s (via %s) calls %s(), which lives in %s and is in no require of that closure',
                        $this->relative($root, $file),
                        $relative,
                        $function,
                        implode(', ', $definedSomewhere[$function])
                    );
                }
            }
        }

        $problems = array_values(array_unique($problems));
        sort($problems);

        return $problems;
    }

    private function root(): string
    {
        return dirname(__DIR__, 2);
    }

    private function relative(string $root, string $path): string
    {
        return str_replace([$root . DIRECTORY_SEPARATOR, $root . '/', '\\'], ['', '', '/'], $path);
    }

    /** @return list<string> absolute paths of every .php file below $dir */
    private function phpFilesIn(string $dir): array
    {
        $out = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $out[] = $file->getPathname();
            }
        }

        return $out;
    }

    /**
     * @return list<string> absolute paths, entrypoint first
     */
    private function requireClosure(string $file): array
    {
        $seen = [];
        $this->walkRequires($file, $seen);

        return array_keys($seen);
    }

    /** @param array<string, true> $seen */
    private function walkRequires(string $file, array &$seen): void
    {
        $real = realpath($file);
        if ($real === false || isset($seen[$real])) {
            return;
        }
        $seen[$real] = true;

        $source = (string) file_get_contents($real);
        if (preg_match_all('/require(?:_once)?\s+__DIR__\s*\.\s*[\'"]([^\'"]+)[\'"]/', $source, $matches) === 0) {
            return;
        }
        foreach ($matches[1] as $suffix) {
            $this->walkRequires(dirname($real) . $suffix, $seen);
        }
    }

    /**
     * Top-level function declarations. Methods carry a visibility keyword and are
     * therefore not matched; `h()` is declared inside a function_exists() guard
     * and is matched on purpose, because loading that file does define it.
     *
     * @return array<string, true> lowercased names
     */
    private function definedFunctions(string $file): array
    {
        $out = [];
        if (preg_match_all('/^\s*function\s+([A-Za-z_][A-Za-z0-9_]*)\s*\(/m', (string) file_get_contents($file), $matches) !== 0) {
            foreach ($matches[1] as $name) {
                $out[strtolower($name)] = true;
            }
        }

        return $out;
    }

    /**
     * Calls this file makes, minus what it defines itself. Method calls, static
     * calls and variable calls are excluded by the lookbehind; `new Foo(` is
     * excluded explicitly. Anything left that lib/ does not define (language
     * constructs, PHP builtins) is filtered by the caller against the index.
     *
     * @return array<string, true> lowercased names
     */
    private function calledFunctions(string $file): array
    {
        $source = (string) file_get_contents($file);
        // Comments carry example calls and prose; only real code counts.
        $source = (string) preg_replace(['!/\*.*?\*/!s', '!^\s*//.*$!m'], '', $source);

        $out = [];
        if (preg_match_all('/(?<![>$:\w])(?<!new\s)([A-Za-z_][A-Za-z0-9_]*)\s*\(/', $source, $matches) !== 0) {
            foreach ($matches[1] as $name) {
                $out[strtolower($name)] = true;
            }
        }

        return array_diff_key($out, $this->definedFunctions($file));
    }

    /**
     * Every name lib/ defines, and where. This is the SSoT for "our function":
     * a prefix list here would be a second, silently incomplete copy of it.
     *
     * @return array<string, list<string>> function => files that define it
     */
    private function functionIndex(string $root): array
    {
        $index = [];
        foreach ($this->phpFilesIn($root . '/lib') as $path) {
            foreach (array_keys($this->definedFunctions($path)) as $name) {
                $index[$name][] = $this->relative($root, $path);
            }
        }

        return $index;
    }
}

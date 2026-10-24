<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/lib/vm_timeline.php';

/**
 * Every status note a writer stores is in the catalog that turns it into words
 * (VIRTUSPHERE_VM_STATUS_NOTES and VIRTUSPHERE_VM_STATUS_JOB_NOTES in
 * lib/vm_timeline.php). A note missing from the catalog shows its raw technical
 * text in the VM timeline, and nothing else notices.
 *
 * The notes are DERIVED from the writers' source, not listed. The scan reads the
 * calls of the status writers and the worker's 'deploy job <id> <suffix>' notes
 * wherever they are written, so a writer added tomorrow is checked without this
 * file changing. A hand-written list goes stale the day the next writer appears,
 * and the stale list then hides the new note.
 *
 * Scanned: lib/ recursively and the files directly in the WebAPI root (the
 * machine API and the worker entry points). Not scanned: tests/ and vendor/.
 *
 * What counts as a note, deliberately narrow:
 * - a complete single-quoted string that contains a space and starts with a letter;
 * - a string followed by a concatenation operator is a piece of a worker note and
 *   is left to the 'deploy job' rule, which requires the id variable to be there;
 * - SQL statements are not notes;
 * - a function's own definition is not a call.
 */
final class VmStatusNoteCatalogContractTest extends TestCase
{
    /** The functions whose argument list carries a status note. */
    private const WRITER_CALLS = [
        'repo_record_vm_status_event',
        'repo_set_vm_state_forward',
        'repo_set_vm_state',
        'repo_clone_mission_vms',
        'deploy_worker_restore_locked_vm_journal',
        'deploy_worker_apply_locked_vm_journal',
    ];

    /** A direct status row write, for files that do not go through a writer function. */
    private const STATUS_INSERT = 'INSERT INTO deploy_vm_status_events';

    /**
     * The worker note: 'deploy job ' . $<id> . ' <suffix>'. The literal after the
     * id starts with exactly one space, which is not part of the catalog key.
     */
    private const JOB_NOTE = '/\'deploy job \'\s*\.\s*\$[A-Za-z_]\w*\s*\.\s*\' ([^\'\n]*)\'/';

    public function testEveryStatusNoteAWriterStoresIsInTheCatalog(): void
    {
        $found = $this->scan();

        $violations = [];
        foreach ($found['notes'] as $note => $locations) {
            if (!array_key_exists($note, VIRTUSPHERE_VM_STATUS_NOTES)) {
                foreach ($locations as $location) {
                    $violations[] = $location . ' stores "' . $note . '"';
                }
            }
        }
        foreach ($found['jobs'] as $suffix => $locations) {
            if (!array_key_exists($suffix, VIRTUSPHERE_VM_STATUS_JOB_NOTES)) {
                foreach ($locations as $location) {
                    $violations[] = $location . ' stores the worker note "deploy job <id> ' . $suffix . '"';
                }
            }
        }

        self::assertSame(
            [],
            $violations,
            "status notes missing from the catalog (VIRTUSPHERE_VM_STATUS_NOTES / VIRTUSPHERE_VM_STATUS_JOB_NOTES in lib/vm_timeline.php):\n"
            . implode("\n", $violations)
        );
    }

    /**
     * The guard must not pass because it found nothing: the scan has to see the
     * known notes of every shape it reads (a direct call literal, a default
     * parameter, a plain assignment, a worker note with its id variable).
     */
    public function testTheScanStillFindsTheKnownNotesOfEveryShape(): void
    {
        self::assertNotSame([], $this->scannedFiles(), 'the scan found no PHP files, so it would prove nothing');

        $found = $this->scan();

        foreach ([
            'created from portal',
            'ansible mac import',
            'mecm update id',
            'cloned from template',
        ] as $note) {
            self::assertArrayHasKey($note, $found['notes'], 'the scan found no writer of "' . $note . '", so the catalog check would prove nothing');
        }
        foreach (['started', 'failed'] as $suffix) {
            self::assertArrayHasKey($suffix, $found['jobs'], 'the scan found no worker note ending in "' . $suffix . '", so the catalog check would prove nothing');
        }
    }

    /**
     * @return array{notes:array<string,list<string>>, jobs:array<string,list<string>>}
     *   the note text and the worker suffix, each with the locations that write it
     */
    private function scan(): array
    {
        $found = ['notes' => [], 'jobs' => []];

        foreach ($this->scannedFiles() as $file) {
            $source = (string) file_get_contents($file);
            $where = $this->relative($file);

            // (a) worker notes, wherever they are written.
            preg_match_all(self::JOB_NOTE, $source, $jobs, PREG_OFFSET_CAPTURE);
            foreach ($jobs[1] as $index => [$suffix, $offset]) {
                $found['jobs'][$suffix][] = $where . ':' . $this->lineOf($source, $jobs[0][$index][1]);
            }

            // (b) literal notes in the argument lists of the writer calls.
            foreach (self::WRITER_CALLS as $function) {
                foreach ($this->callOpenings($source, $function) as $open) {
                    foreach ($this->callArguments($source, $open)['literals'] as $literal) {
                        if ($this->isNote($literal['text']) && !$this->isConcatenated($source, $literal['end'])) {
                            $found['notes'][$literal['text']][] = $where . ':' . $this->lineOf($source, $open);
                        }
                    }
                }
            }

            // (b) the default value of the reason parameter of the clone writer.
            foreach ($this->definitionOpenings($source, 'repo_clone_mission_vms') as $open) {
                foreach ($this->callArguments($source, $open)['literals'] as $literal) {
                    if ($this->isNote($literal['text']) && !$this->isConcatenated($source, $literal['end'])) {
                        $found['notes'][$literal['text']][] = $where . ':' . $this->lineOf($source, $open);
                    }
                }
            }

            // (b) a note assigned to a variable, in a file that writes status rows.
            if (!$this->writesStatusRows($source)) {
                continue;
            }
            preg_match_all('/\$note\s*=\s*\'([^\'\n]*)\'\s*;/', $source, $assigned, PREG_OFFSET_CAPTURE);
            foreach ($assigned[1] as $index => [$text]) {
                if ($this->isNote($text)) {
                    $found['notes'][$text][] = $where . ':' . $this->lineOf($source, $assigned[0][$index][1]);
                }
            }
            preg_match_all('/\$note\s*=[^;]*?\?\s*\'([^\'\n]*)\'\s*:\s*\'([^\'\n]*)\'\s*;/', $source, $ternary, PREG_OFFSET_CAPTURE);
            foreach ($ternary[0] as $index => [, $offset]) {
                foreach ([$ternary[1][$index][0], $ternary[2][$index][0]] as $text) {
                    if ($this->isNote($text)) {
                        $found['notes'][$text][] = $where . ':' . $this->lineOf($source, $offset);
                    }
                }
            }
        }

        return $found;
    }

    /** A complete note: a space inside, a letter first, and not a SQL statement. */
    private function isNote(string $text): bool
    {
        return str_contains($text, ' ')
            && preg_match('/^[A-Za-z]/', $text) === 1
            && preg_match('/^(SELECT|INSERT|UPDATE|DELETE)\b/', $text) !== 1;
    }

    /** True when the string at $end is the left part of a concatenation. */
    private function isConcatenated(string $source, int $end): bool
    {
        return preg_match('/\G\s*\./', $source, $match, 0, $end + 1) === 1;
    }

    private function writesStatusRows(string $source): bool
    {
        if (str_contains($source, self::STATUS_INSERT)) {
            return true;
        }
        foreach (self::WRITER_CALLS as $function) {
            if ($this->callOpenings($source, $function) !== []) {
                return true;
            }
        }

        return false;
    }

    /**
     * Offsets of the '(' of every CALL of the function. The definition is not a
     * call, and neither is a longer name that merely starts the same way.
     *
     * @return list<int>
     */
    private function callOpenings(string $source, string $function): array
    {
        preg_match_all('/\b' . preg_quote($function, '/') . '\s*\(/', $source, $matches, PREG_OFFSET_CAPTURE);
        $openings = [];
        foreach ($matches[0] as [$text, $offset]) {
            if (preg_match('/function\s+$/', substr($source, 0, $offset)) === 1) {
                continue;
            }
            $openings[] = $offset + strlen($text) - 1;
        }

        return $openings;
    }

    /** @return list<int> offsets of the '(' of the function's definition */
    private function definitionOpenings(string $source, string $function): array
    {
        preg_match_all('/\bfunction\s+' . preg_quote($function, '/') . '\s*\(/', $source, $matches, PREG_OFFSET_CAPTURE);
        $openings = [];
        foreach ($matches[0] as [$text, $offset]) {
            $openings[] = $offset + strlen($text) - 1;
        }

        return $openings;
    }

    /**
     * The argument list opened at $open (a '(') up to its matching ')', and the
     * single-quoted strings inside it. Double-quoted strings and comments are
     * stepped over, so a parenthesis or quote inside them cannot end the list.
     *
     * @return array{end:int, literals:list<array{text:string,end:int}>}
     */
    private function callArguments(string $source, int $open): array
    {
        $depth = 0;
        $literals = [];
        $length = strlen($source);
        for ($i = $open; $i < $length; $i++) {
            $char = $source[$i];
            if ($char === '\'' || $char === '"') {
                $close = $this->stringEnd($source, $i);
                if ($char === '\'') {
                    $literals[] = ['text' => substr($source, $i + 1, $close - $i - 1), 'end' => $close];
                }
                $i = $close;
            } elseif ($char === '/' && ($source[$i + 1] ?? '') === '/') {
                $newline = strpos($source, "\n", $i);
                $i = $newline === false ? $length : $newline;
            } elseif ($char === '/' && ($source[$i + 1] ?? '') === '*') {
                $end = strpos($source, '*/', $i + 2);
                $i = $end === false ? $length : $end + 1;
            } elseif ($char === '(') {
                $depth++;
            } elseif ($char === ')') {
                $depth--;
                if ($depth === 0) {
                    return ['end' => $i, 'literals' => $literals];
                }
            }
        }

        self::fail('unbalanced parentheses after offset ' . $open);
    }

    /** The offset of the quote that closes the string opened at $start. */
    private function stringEnd(string $source, int $start): int
    {
        $quote = $source[$start];
        $length = strlen($source);
        for ($i = $start + 1; $i < $length; $i++) {
            if ($source[$i] === '\\') {
                $i++;
                continue;
            }
            if ($source[$i] === $quote) {
                return $i;
            }
        }

        self::fail('unterminated string after offset ' . $start);
    }

    private function lineOf(string $source, int $offset): int
    {
        return substr_count($source, "\n", 0, $offset) + 1;
    }

    private function root(): string
    {
        return dirname(__DIR__, 2);
    }

    /** @return list<string> the PHP files of lib/ (recursively) and of the WebAPI root */
    private function scannedFiles(): array
    {
        $files = [];
        $library = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->root() . '/lib', FilesystemIterator::SKIP_DOTS)
        );
        foreach ($library as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }
        foreach (new FilesystemIterator($this->root(), FilesystemIterator::SKIP_DOTS) as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }
        sort($files);

        return $files;
    }

    private function relative(string $file): string
    {
        return str_replace('\\', '/', substr($file, strlen($this->root()) + 1));
    }
}

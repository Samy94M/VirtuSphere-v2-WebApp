<?php

declare(strict_types=1);

// The process operations the supervisor performs, behind one seam (Etappe 14C).
//
// Injectable for the same reason DeployWorkerDbOperations is: the supervisor's
// whole contract is what it does around a child that will not die, and there is
// no honest way to make a real process hang on demand inside a unit test.
//
// Two mechanics are load-bearing and neither is arbitrary:
//
//  - `isRunning()` asks proc_get_status(), which performs the waitpid this
//    class exists to guarantee: it reaps the zombie and reports the truth.
//    A flag remembered from "we sent it a signal" is NOT that truth, and using
//    one is exactly how a supervisor ends up with two children.
//  - Signals go out through posix_kill() on the child's pid. pcntl is not
//    needed to SEND one; it is needed to RECEIVE one, which is the entrypoint's
//    problem, not this class's.

class DeploySupervisorProcess
{
    // The two signal numbers, spelled out rather than taken from SIGTERM/SIGKILL.
    //
    // Those constants come from pcntl, and pcntl is needed for RECEIVING a
    // signal, which is the entrypoint's job, not this class's. Depending on the
    // extension here would make the seam unusable exactly where it is most
    // useful: in a plain test image that has no pcntl. The numbers are fixed by
    // POSIX and by Linux on every architecture this product runs on.
    private const SIGNAL_TERM = 15;
    private const SIGNAL_KILL = 9;

    /** @var resource|null */
    private $handle = null;

    private ?int $pid = null;

    /**
     * Starts the one child.
     *
     * Refuses a second one while a handle is open, rather than trusting the
     * caller. The policy already guarantees the ordering; this is the second,
     * independent reason a second child cannot happen, and two reasons means
     * neither has to be perfect on its own.
     *
     * @param list<string> $command
     */
    public function start(array $command): int
    {
        if ($this->handle !== null) {
            throw new RuntimeException('A supervised child is already open; reap it before starting another.');
        }
        // Pipes are deliberately inherited (`file` descriptors of this process)
        // rather than captured: the child's output is the container log, and a
        // pipe nobody drains is a child that blocks on a full buffer.
        $descriptors = [0 => ['file', '/dev/null', 'r'], 1 => STDOUT, 2 => STDERR];
        $pipes = [];
        $handle = proc_open($command, $descriptors, $pipes);
        if (!is_resource($handle)) {
            throw new RuntimeException('The supervised child could not be started.');
        }
        $status = proc_get_status($handle);
        $this->handle = $handle;
        $this->pid = (int) $status['pid'];

        return $this->pid;
    }

    /**
     * Whether the child is still running, established by waitpid rather than
     * assumed. Once it reports false the handle is closed and the pid is
     * forgotten, which is what makes a later `start()` legal.
     */
    public function isRunning(): bool
    {
        if ($this->handle === null) {
            return false;
        }
        $status = proc_get_status($this->handle);
        if ($status['running']) {
            return true;
        }
        // proc_close() would wait; the process is already reaped by the status
        // call above, so this only releases the handle.
        proc_close($this->handle);
        $this->handle = null;
        $this->pid = null;

        return false;
    }

    public function pid(): ?int
    {
        return $this->pid;
    }

    /** Asks the child to stop. One signal per call, never a repeat. */
    public function terminate(): void
    {
        $this->signal(self::SIGNAL_TERM);
    }

    /** Ends the child. Anything that survives this is not a userspace problem. */
    public function kill(): void
    {
        $this->signal(self::SIGNAL_KILL);
    }

    private function signal(int $signal): void
    {
        if ($this->pid === null) {
            return;
        }
        // A failing kill() is not fatal here: the most common reason is that the
        // child died between the status call and this one, which the next
        // isRunning() establishes properly.
        @posix_kill($this->pid, $signal);
    }
}

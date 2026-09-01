<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/lib/ansible.php';
require_once dirname(__DIR__, 2) . '/lib/deploy_create_constants.php';

/**
 * What the per-VM create control playbooks may and may not do (Etappe 14B).
 *
 * The properties pinned here are the ones a green run cannot show. A playbook
 * that loops over the whole selection still works; it just leaves the worker
 * without a persistence boundary again, which is the defect this stage exists
 * to remove. A debug task that prints a registered object still works; it just
 * writes the ESXi password into a job log that every deploy reader can open.
 */
final class CreateFlowPlaybookContractTest extends TestCase
{
    /**
     * The source with its comment lines removed.
     *
     * Every scan below is about what a playbook DOES. A comment that says "no
     * rm -rf here" is the opposite of the defect it would otherwise trip, and a
     * contract that forces the explanation out of the file makes the file worse.
     */
    private function tasksOnly(string $source): string
    {
        $lines = array_filter(
            preg_split('/\R/', $source) ?: [],
            static fn (string $line): bool => !str_starts_with(ltrim($line), '#')
        );

        return implode("\n", $lines);
    }

    /** @return array<string, string> file name => source */
    private function controlFiles(): array
    {
        $sources = [];
        foreach (VIRTUSPHERE_CREATE_ARTIFACTS as $file) {
            if (!str_ends_with($file, '.yml')) {
                continue;
            }
            $path = ansible_source_dir() . DIRECTORY_SEPARATOR . $file;
            self::assertFileExists($path, $file . ' is registered as a create artifact but missing.');
            $sources[$file] = $this->tasksOnly((string) file_get_contents($path));
        }
        self::assertNotSame([], $sources, 'No create control files found (zero-match must not pass).');

        return $sources;
    }

    public function testEveryRegisteredCreateArtifactIsUploadedWithTheJob(): void
    {
        // A file that is dispatched but not uploaded dies on the host with
        // "could not be found", and the error categorizer then blames ESXi for
        // a file that never left this container. That happened once with the
        // inventory playbook; this keeps the create files out of that class.
        $required = ansible_required_files();
        foreach (VIRTUSPHERE_CREATE_ARTIFACTS as $file) {
            self::assertContains($file, $required, $file . ' is not part of the uploaded job artifacts.');
            self::assertFileExists(ansible_source_dir() . DIRECTORY_SEPARATOR . $file);
        }
    }

    public function testNoControlPlaybookLoopsOverTheSelection(): void
    {
        foreach ($this->controlFiles() as $name => $source) {
            self::assertStringNotContainsString(
                'loop: "{{ vm_configurations }}"',
                $source,
                $name . ' loops over the whole selection; a control call owns exactly one VM.'
            );
        }
    }

    public function testTheMutatingPlaybookTouchesExactlyOneVmAndRunsItAsync(): void
    {
        $launch = $this->tasksOnly((string) file_get_contents(ansible_source_dir() . DIRECTORY_SEPARATOR . VIRTUSPHERE_CREATE_PLAYBOOK_LAUNCH));

        self::assertSame(1, substr_count($launch, 'community.vmware.vmware_guest:'), 'exactly one mutating module call');
        self::assertStringContainsString('poll: 0', $launch);
        self::assertStringContainsString('async: "{{ vs_async_timeout | int }}"', $launch);
        // Since ansible-core 2.12 the async directory is chosen through this
        // variable. ANSIBLE_ASYNC_DIR in a task environment is silently ignored,
        // which would put the state file in the user's home and make it
        // unfindable for the handle that is supposed to own it.
        self::assertStringContainsString('ansible_async_dir: "{{ vs_async_dir }}"', $launch);
        self::assertStringNotContainsString('ANSIBLE_ASYNC_DIR', $launch);
    }

    public function testTheMutatingPlaybookSelectsByUuidWhenTheVmAlreadyExists(): void
    {
        $launch = $this->tasksOnly((string) file_get_contents(ansible_source_dir() . DIRECTORY_SEPARATOR . VIRTUSPHERE_CREATE_PLAYBOOK_LAUNCH));

        // The two closed branches. Ansible ignores a uuid when it creates, so an
        // existing VM that disappeared between the check and the call must not
        // be addressable by name any more, or it would silently be recreated.
        self::assertStringContainsString('use_instance_uuid:', $launch);
        self::assertMatchesRegularExpression('/name:\s*"\{\{ vs_target\.vm_name if not \(vs_existed_before \| bool\) else omit \}\}"/', $launch);
        self::assertMatchesRegularExpression('/uuid:\s*"\{\{ vs_precheck_instance_uuid if \(vs_existed_before \| bool\) else omit \}\}"/', $launch);
    }

    public function testNoControlFilePrintsARegisteredObjectOrModuleArguments(): void
    {
        foreach ($this->controlFiles() as $name => $source) {
            // A registered result carries invocation.module_args, and those
            // carry the ESXi password. One debug line is enough to put it in
            // the job log of every deploy.
            self::assertStringNotContainsString('module_args', $source, $name);
            self::assertDoesNotMatchRegularExpression(
                '/debug:\s*\R\s*var:\s*vs_/',
                $source,
                $name . ' prints a registered object.'
            );
            self::assertStringNotContainsString('no_log: false', $source, $name . ' disables secret suppression.');
        }
    }

    public function testEveryResultFileIsWrittenWithRestrictiveMode(): void
    {
        foreach ($this->controlFiles() as $name => $source) {
            $writes = substr_count($source, 'dest: "{{ vs_result_file }}"');
            if ($writes === 0) {
                continue;
            }
            self::assertSame(
                $writes,
                substr_count($source, 'mode: "0600"'),
                $name . ' writes a result file without 0600.'
            );
        }
    }

    public function testTheIdentityMatrixExistsExactlyOnce(): void
    {
        // Three playbooks ask the same question. Three copies of the answer
        // would be three answers, and the one that decides before a mutation is
        // the one that matters.
        $identity = $this->tasksOnly((string) file_get_contents(ansible_source_dir() . DIRECTORY_SEPARATOR . VIRTUSPHERE_CREATE_IDENTITY_TASKS));
        self::assertStringContainsString('vs_precheck_instance_uuid', $identity);

        $includers = 0;
        foreach ($this->controlFiles() as $name => $source) {
            if ($name === VIRTUSPHERE_CREATE_IDENTITY_TASKS) {
                continue;
            }
            self::assertStringNotContainsString(
                'selectattr(\'guest_name\'',
                $source,
                $name . ' keeps its own copy of the identity matrix.'
            );
            if (str_contains($source, 'include_tasks: ./' . VIRTUSPHERE_CREATE_IDENTITY_TASKS)) {
                $includers++;
            }
        }
        self::assertSame(3, $includers, 'prepare, launch and terminal status share the one identity check');
    }

    public function testTheStatusPlaybookDecidesOnTheStateFileRatherThanOnProse(): void
    {
        $status = $this->tasksOnly((string) file_get_contents(ansible_source_dir() . DIRECTORY_SEPARATOR . VIRTUSPHERE_CREATE_PLAYBOOK_STATUS));

        // Measured, not assumed (gate ansible-create-async): async_status
        // answers a vanished job id with finished=1 and, under
        // failed_when: false, with failed=false. A lost job would look like a
        // finished one, so the structural check comes first.
        self::assertStringContainsString('stat:', $status);
        self::assertStringContainsString('{{ vs_async_dir }}/{{ vs_async_jid }}', $status);
        self::assertStringContainsString('async_state_missing', $status);
        self::assertStringNotContainsString('could not find job', $status, 'the decision must not read the module message');
    }

    public function testTheCleanupPlaybookCannotRemoveAnythingButOneStateFile(): void
    {
        $cleanup = $this->tasksOnly((string) file_get_contents(ansible_source_dir() . DIRECTORY_SEPARATOR . VIRTUSPHERE_CREATE_PLAYBOOK_CLEANUP));

        self::assertStringContainsString('mode: cleanup', $cleanup);
        foreach (['rm -rf', 'file:', 'shell:', '$HOME', '~'] as $forbidden) {
            self::assertStringNotContainsString($forbidden, $cleanup, 'cleanup must not delete by path: ' . $forbidden);
        }
        self::assertStringContainsString("vs_async_dir is match('^/')", $cleanup);
    }
}

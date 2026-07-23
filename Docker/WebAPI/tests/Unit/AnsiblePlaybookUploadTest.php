<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Every playbook a job can dispatch must actually be shipped to the Ansible
 * host, and must exist in the source tree.
 *
 * It did not. There are two playbook maps -- VIRTUSPHERE_PLAYBOOKS for the
 * mission modes and VIRTUSPHERE_SYSTEM_PLAYBOOKS for the ESXi inventory --
 * and ansible_required_files() derived from the first one only, under a
 * docblock claiming the two "never drift". So every ESXi inventory pull
 * uploaded five playbooks, ran a sixth, and died on
 *
 *     ERROR! the playbook: inventoryESXi_playbook.yml could not be found
 *
 * On the operator's screen that became "the host answered unexpectedly" plus
 * an empty inventory, which sends them to look at ESXi, the network and the
 * credentials -- none of which were involved. Nothing caught it because no
 * test connected the dispatch map to the upload list, and the local dev stack
 * has no real Ansible host to fail against.
 */
final class AnsiblePlaybookUploadTest extends TestCase
{
    /** @return array<string, string> mode => playbook filename */
    private function everyDispatchablePlaybook(): array
    {
        return array_merge(VIRTUSPHERE_PLAYBOOKS, VIRTUSPHERE_SYSTEM_PLAYBOOKS);
    }

    public function testEveryDispatchablePlaybookIsUploaded(): void
    {
        require_once dirname(__DIR__, 2) . '/lib/ansible_paths.php';
        $uploaded = ansible_required_files();

        foreach ($this->everyDispatchablePlaybook() as $mode => $playbook) {
            self::assertContains(
                $playbook,
                $uploaded,
                'mode "' . $mode . '" runs ' . $playbook . ', but it is never copied to the Ansible host'
            );
        }
    }

    public function testEveryUploadedFileExistsInTheSourceTree(): void
    {
        require_once dirname(__DIR__, 2) . '/lib/ansible_paths.php';
        // ansible_source_dir() is the resolver the deploy path itself uses, so
        // this asserts against the very directory a job would copy from.
        $sourceDir = ansible_source_dir();
        self::assertDirectoryExists($sourceDir);

        foreach (ansible_required_files() as $file) {
            self::assertFileExists(
                $sourceDir . DIRECTORY_SEPARATOR . $file,
                $file . ' is on the upload list but missing from the playbook source dir'
            );
        }
    }

    public function testTheInventoryPlaybookIsCoveredByBothChecks(): void
    {
        require_once dirname(__DIR__, 2) . '/lib/ansible_paths.php';
        // Named explicitly: it is the one that was missing, and a future
        // refactor that drops the system map again must fail here loudly.
        $inventory = VIRTUSPHERE_SYSTEM_PLAYBOOKS[VIRTUSPHERE_DEPLOY_MODE_INVENTORY];
        self::assertSame('inventoryESXi_playbook.yml', $inventory);
        self::assertContains($inventory, ansible_required_files());
    }

    public function testNoDuplicatesOnTheUploadList(): void
    {
        $uploaded = ansible_required_files();
        self::assertSame(
            array_values(array_unique($uploaded)),
            array_values($uploaded),
            'a file would be uploaded twice; the two playbook maps overlap'
        );
    }
}

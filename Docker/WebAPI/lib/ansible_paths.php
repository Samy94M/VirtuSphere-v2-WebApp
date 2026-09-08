<?php

declare(strict_types=1);

require_once __DIR__ . '/constants.php';
require_once __DIR__ . '/deploy_constants.php';
require_once __DIR__ . '/envboot.php';
require_once __DIR__ . '/log_redaction.php';
require_once __DIR__ . '/ssh_transport_exceptions.php';

/**
 * Filesystem and naming for deploy artifacts: where the source playbooks live,
 * where a job's work dir is created, how remote dirs and slugs are named, and
 * the safe write/cleanup of the tree. Split out of ansible.php by domain
 * (ADR-0006 file-size discipline); no behaviour change.
 */

const VIRTUSPHERE_ANSIBLE_UPLOAD_SCRIPT = 'upload_mac_list.py';

/**
 * Files that must be copied into every deploy work dir: every playbook a job
 * can run, plus the MAC upload script.
 *
 * Both playbook maps, not just the mission modes. There are two, and this
 * derived only from the first, so the ESXi inventory playbook was executed but
 * never uploaded: the run died on "the playbook: inventoryESXi_playbook.yml
 * could not be found", which the error categorizer then reported to the
 * operator as "the host answered unexpectedly" -- a sentence pointing at ESXi
 * for a file that never left this container. A mode that can be dispatched must
 * have its playbook here, which is what AnsiblePlaybookUploadTest pins.
 *
 * @return string[]
 */
function ansible_required_files(): array
{
    // Deduplicated since Etappe 14B-E, and the overlap it removes is exactly
    // one entry: VIRTUSPHERE_PLAYBOOKS['create'] and the launch playbook of
    // VIRTUSPHERE_CREATE_ARTIFACTS are the same file. The mode map names it
    // because that is what makes a mode create VMs; the create contract names
    // it because that is the list its extra-vars are keyed on. Neither may drop
    // it, so the list is made unique here rather than by taking a side.
    return array_values(array_unique(array_merge(
        array_values(VIRTUSPHERE_PLAYBOOKS),
        array_values(VIRTUSPHERE_SYSTEM_PLAYBOOKS),
        // The per-VM create control files (Etappe 14B). Only the launch playbook
        // belongs to a mode; the other five would be the exact repeat of the
        // inventory-playbook defect if they were only dispatched.
        VIRTUSPHERE_CREATE_ARTIFACTS,
        [VIRTUSPHERE_ANSIBLE_UPLOAD_SCRIPT]
    )));
}

function ansible_source_dir(): string
{
    $candidates = [];
    $configured = trim(envboot_optional('ANSIBLE_SOURCE_DIR', ''));
    if ($configured !== '') {
        $candidates[] = $configured;
    }

    $candidates[] = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'ansible-src';
    $candidates[] = dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'Ansible';

    foreach ($candidates as $candidate) {
        $real = realpath($candidate);
        if ($real !== false && is_dir($real)) {
            return $real;
        }
    }

    throw new SshTransportConfigurationException('Ansible source directory not found. Set ANSIBLE_SOURCE_DIR for the worker.');
}

function ansible_create_job_work_dir(int $jobId, string $missionName): string
{
    $root = ansible_work_root();
    if (!is_dir($root) && !mkdir($root, 0770, true) && !is_dir($root)) {
        throw new RuntimeException('Cannot create deploy work directory.');
    }

    $dir = $root . DIRECTORY_SEPARATOR . 'job-' . $jobId . '-' . ansible_slug($missionName) . '-' . bin2hex(random_bytes(4));
    if (!mkdir($dir, 0770, true) && !is_dir($dir)) {
        throw new RuntimeException('Cannot create deploy job work directory.');
    }

    return $dir;
}

function ansible_work_root(): string
{
    $configured = trim(envboot_optional('VIRTUSPHERE_DEPLOY_WORKDIR', ''));
    if ($configured !== '') {
        return rtrim($configured, DIRECTORY_SEPARATOR);
    }

    return rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'virtusphere-deploy';
}

function ansible_copy_source_files(string $sourceDir, string $workDir): void
{
    foreach (ansible_required_files() as $file) {
        $source = $sourceDir . DIRECTORY_SEPARATOR . $file;
        if (!is_file($source) || !is_readable($source)) {
            throw new RuntimeException('Required Ansible file missing: ' . $file);
        }

        if (!copy($source, $workDir . DIRECTORY_SEPARATOR . $file)) {
            throw new RuntimeException('Cannot copy Ansible file: ' . $file);
        }
    }
}

function ansible_write_file(string $path, string $contents): void
{
    $bytes = file_put_contents($path, $contents, LOCK_EX);
    if ($bytes === false) {
        throw new RuntimeException('Cannot write Ansible artifact: ' . $path);
    }
}

function ansible_cleanup_artifacts(?string $dir): void
{
    if ($dir === null || $dir === '') {
        return;
    }

    $root = realpath(ansible_work_root());
    $target = realpath($dir);
    if ($root === false || $target === false || !str_starts_with($target, $root . DIRECTORY_SEPARATOR)) {
        return;
    }

    ansible_delete_tree($target);
}

function ansible_delete_tree(string $path): void
{
    if (is_file($path) || is_link($path)) {
        try {
            $deleted = unlink($path);
        } catch (Throwable $exception) {
            error_log(virtusphere_redact_log_text('[ansible_cleanup] Cannot delete file ' . $path . ': ' . $exception->getMessage()));
            return;
        }

        if (!$deleted && file_exists($path)) {
            error_log('[ansible_cleanup] Cannot delete file: ' . $path);
        }
        return;
    }

    try {
        $items = scandir($path);
    } catch (Throwable $exception) {
        error_log(virtusphere_redact_log_text('[ansible_cleanup] Cannot scan directory ' . $path . ': ' . $exception->getMessage()));
        return;
    }

    if ($items === false) {
        error_log('[ansible_cleanup] Cannot scan directory: ' . $path);
        return;
    }

    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        ansible_delete_tree($path . DIRECTORY_SEPARATOR . $item);
    }

    try {
        $removed = rmdir($path);
    } catch (Throwable $exception) {
        error_log(virtusphere_redact_log_text('[ansible_cleanup] Cannot remove directory ' . $path . ': ' . $exception->getMessage()));
        return;
    }

    if (!$removed && is_dir($path)) {
        error_log('[ansible_cleanup] Cannot remove directory: ' . $path);
    }
}

function ansible_remote_dir(int $jobId, string $missionName): string
{
    return '/tmp/virtusphere-job-' . $jobId . '-' . ansible_slug($missionName);
}

function ansible_slug(string $value): string
{
    $slug = preg_replace('/[^A-Za-z0-9._-]+/', '_', trim($value));
    $slug = trim((string) $slug, '._-');

    return substr($slug !== '' ? $slug : 'mission', 0, 80);
}

<?php

declare(strict_types=1);

require_once __DIR__ . '/../lib/bootstrap.php';
require_once __DIR__ . '/../lib/layout.php';
require_once __DIR__ . '/../lib/forms.php';
require_once __DIR__ . '/../lib/repo/package_run_reads.php';
require_once __DIR__ . '/../lib/repo/package_run_search.php';
require_once __DIR__ . '/../lib/package_report_filter.php';
require_once __DIR__ . '/../lib/package_report_page.php';

/** @var mysqli $connection Provided by bootstrap.php. */
$user = portal_require_user($connection);
$filter = package_report_filter($_GET);
$lookupId = $_GET['run_id'] ?? '';
$lookupError = '';
if (!is_string($lookupId)) {
    $lookupError = __t('package_report.invalid_run_id');
} elseif ($lookupId !== '') {
    $location = repo_package_run_location($connection, $lookupId);
    if ($location === null) {
        $lookupError = __t(repo_package_run_guid_valid($lookupId)
            ? 'package_report.not_available' : 'package_report.invalid_run_id');
    } elseif ($filter['scope'] === 'package') {
        $run = repo_package_run_for_vm($connection, $location['vm_id'], $lookupId);
        if ($run === null || $run['project_name'] !== $filter['project'] || $run['package_version'] !== $filter['version']) {
            $lookupError = __t('package_report.not_available');
        }
    }
    if ($lookupError === '') {
        redirect_to(package_report_detail_url($location['mission_id'], $location['vm_id'], $lookupId));
    }
}
$usable = $filter['errors'] === [] && $lookupError === '';
$result = $usable ? repo_package_run_search($connection, $filter)
    : ['rows' => [], 'run_count' => 0, 'vm_count' => 0, 'next' => null];
$resetFilter = $filter['scope'] === 'package'
    ? ['scope' => 'package', 'project' => $filter['project'], 'version' => $filter['version']]
    : [];
$resetUrl = 'package_reports.php' . ($resetFilter !== [] ? '?' . http_build_query($resetFilter, '', '&', PHP_QUERY_RFC3986) : '');
$today = (new DateTimeImmutable('now', new DateTimeZone(portal_timezone())))->format('Y-m-d');
$todayUrl = 'package_reports.php?' . package_report_filter_query($filter, [
    'from' => $today, 'to' => $today, 'before_at' => null, 'before_id' => null,
]);

layout_header(__t('package_report.list_heading'), $user, 'packages', 'packages');
?>
<div class="stack">
    <section class="panel">
        <h1><?php echo h(__t('package_report.list_heading')); ?></h1>
        <p class="muted"><?php echo h(__t('package_report.list_hint')); ?></p>
        <?php if ($filter['scope'] === 'package') { ?>
            <p><?php echo h(__t('package_report.fixed_package', [
                'package' => $filter['project'], 'version' => $filter['version'],
            ])); ?></p>
            <p><a href="package_reports.php"><?php echo h(__t('package_report.global_view')); ?></a></p>
        <?php } ?>
        <?php if (!$usable) { ?>
            <p class="alert alert-error" role="alert"><?php echo h($lookupError !== '' ? $lookupError : __t('package_report.invalid_filter')); ?></p>
        <?php } ?>
        <form method="get" action="package_reports.php" class="form-grid">
            <?php if ($filter['exclude_vm'] !== null) { ?>
                <input type="hidden" name="exclude_vm" value="<?php echo h((string) $filter['exclude_vm']); ?>">
                <p><?php echo h(__t('package_report.excluded_vm', ['vm' => $filter['exclude_vm']])); ?></p>
            <?php } ?>
            <?php if ($filter['scope'] === 'package') { ?>
                <input type="hidden" name="scope" value="package">
                <input type="hidden" name="project" value="<?php echo h($filter['project']); ?>">
                <input type="hidden" name="version" value="<?php echo h($filter['version']); ?>">
            <?php } else { ?>
                <?php foreach (['project' => 'project_filter', 'version' => 'version_filter'] as $key => $label) {
                    $error = isset($filter['errors'][$key]) ? __t($filter['errors'][$key]) : ''; ?>
                    <div><label for="<?php echo h(form_element_id('package_report_filter', $key)); ?>"><?php echo h(__t('package_report.' . $label)); ?></label>
                        <input type="text" name="<?php echo h($key); ?>" value="<?php echo h($filter[$key]); ?>" maxlength="255"<?php echo form_control_attrs('package_report_filter', $key, null, false, $error); ?>>
                        <?php echo form_error_html('package_report_filter', $key, null, $error); ?>
                    </div>
                <?php } ?>
            <?php } ?>
            <?php foreach (['name' => 'name_filter', 'from' => 'from_filter', 'to' => 'to_filter'] as $key => $label) {
                $error = isset($filter['errors'][$key]) ? __t($filter['errors'][$key]) : ''; ?>
                <div><label for="<?php echo h(form_element_id('package_report_filter', $key)); ?>"><?php echo h(__t('package_report.' . $label)); ?></label>
                    <input type="<?php echo $key === 'name' ? 'text' : 'date'; ?>" name="<?php echo h($key); ?>" value="<?php echo h($filter[$key]); ?>"<?php echo $key === 'name' ? ' maxlength="100"' : ''; ?><?php echo form_control_attrs('package_report_filter', $key, null, false, $error); ?>>
                    <?php echo form_error_html('package_report_filter', $key, null, $error); ?>
                </div>
            <?php } ?>
            <div><label for="<?php echo h(form_element_id('package_report_filter', 'state')); ?>"><?php echo h(__t('package_report.state_filter')); ?></label>
                <select name="state"<?php echo form_control_attrs('package_report_filter', 'state', null, false,
                    isset($filter['errors']['state']) ? __t($filter['errors']['state']) : ''); ?>>
                    <?php foreach (['all', 'error', 'no_completion'] as $state) { ?>
                        <option value="<?php echo h($state); ?>"<?php echo $state === $filter['state'] ? ' selected' : ''; ?>><?php echo h(__t('package_report.state_' . $state)); ?></option>
                    <?php } ?>
                </select>
                <?php echo form_error_html('package_report_filter', 'state', null,
                    isset($filter['errors']['state']) ? __t($filter['errors']['state']) : ''); ?>
            </div>
            <?php foreach (['error_category' => 'error_category_filter', 'child_exit' => 'child_exit_filter',
                'wrapper_exit' => 'wrapper_exit_filter'] as $key => $label) {
                $error = isset($filter['errors'][$key]) ? __t($filter['errors'][$key]) : ''; ?>
                <div><label for="<?php echo h(form_element_id('package_report_filter', $key)); ?>"><?php echo h(__t('package_report.' . $label)); ?></label>
                    <input type="<?php echo $key === 'error_category' ? 'text' : 'number'; ?>" name="<?php echo h($key); ?>" value="<?php echo h((string) ($filter[$key] ?? '')); ?>"<?php echo $key === 'error_category' ? ' maxlength="255"' : ''; ?><?php echo form_control_attrs('package_report_filter', $key, null, false, $error); ?>>
                    <?php echo form_error_html('package_report_filter', $key, null, $error); ?>
                </div>
            <?php } ?>
            <div class="actions"><button class="button" type="submit"><?php echo h(__t('package_report.apply')); ?></button>
                <a href="<?php echo h($todayUrl); ?>"><?php echo h(__t('package_report.today')); ?></a>
                <a href="<?php echo h($resetUrl); ?>"><?php echo h(__t('package_report.reset')); ?></a></div>
        </form>
        <?php if ($filter['scope'] !== 'package') { ?>
            <form method="get" action="package_reports.php" class="inline-form">
                <label for="<?php echo h(form_element_id('package_report_lookup', 'run_id')); ?>"><?php echo h(__t('package_report.run_id_open')); ?></label>
                <input type="text" name="run_id" value="<?php echo h(is_string($lookupId) ? $lookupId : ''); ?>"<?php echo form_control_attrs('package_report_lookup', 'run_id', null, false, $lookupError); ?>>
                <?php echo form_error_html('package_report_lookup', 'run_id', null, $lookupError); ?>
                <button class="button button-secondary" type="submit"><?php echo h(__t('package_report.open')); ?></button>
            </form>
        <?php } ?>
    </section>
    <section class="panel">
        <?php if ($usable) { ?>
            <p><?php echo h(__t('package_report.counts', [
                'runs' => $result['run_count'], 'devices' => $result['vm_count'],
            ])); ?></p>
            <?php if ($result['rows'] === []) { ?>
                <p class="muted"><?php echo h(__t('package_report.no_matches')); ?></p>
            <?php } else { ?>
                <div class="table-wrap" tabindex="0"><table>
                    <thead><tr><th><?php echo h(__t('package_report.vm')); ?></th><th><?php echo h(__t('package_report.package')); ?></th><th><?php echo h(__t('package_report.revision')); ?></th><th><?php echo h(__t('package_report.first_received')); ?></th><th><?php echo h(__t('package_report.received')); ?></th><th><?php echo h(__t('package_report.result')); ?></th><th><?php echo h(__t('package_report.details')); ?></th></tr></thead>
                    <tbody><?php foreach ($result['rows'] as $run) { ?>
                        <tr>
                            <td><?php echo h((string) $run['vm_name']); ?></td>
                            <td><?php echo h((string) $run['project_name'] . ' ' . (string) $run['package_version']); ?></td>
                            <td><?php echo h((string) $run['rollout_revision']); ?></td>
                            <td><?php echo h(portal_format_timestamp((string) $run['first_received_at'])); ?></td>
                            <td><?php echo h(portal_format_timestamp((string) $run['last_evidence_at'])); ?></td>
                            <td><?php echo package_report_result_badge($run); ?></td>
                            <td><a href="<?php echo h(package_report_detail_url((int) $run['mission_id'], (int) $run['vm_id'], (string) $run['run_id'], [], $filter, $filter)); ?>"><?php echo h(__t('package_report.details')); ?></a></td>
                        </tr>
                    <?php } ?></tbody>
                </table></div>
                <?php if ($result['next'] !== null) {
                    $nextUrl = 'package_reports.php?' . package_report_filter_query($filter, [
                        'before_at' => $result['next']['at'], 'before_id' => $result['next']['id'],
                    ]); ?>
                    <p><a href="<?php echo h($nextUrl); ?>"><?php echo h(__t('package_report.more')); ?></a></p>
                <?php } ?>
            <?php } ?>
        <?php } ?>
    </section>
</div>
<?php layout_footer(); ?>

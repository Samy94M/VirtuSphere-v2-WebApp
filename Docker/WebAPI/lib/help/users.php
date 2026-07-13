<?php
// Help panel partial, included by portal/help.php. Uses $canUsers from the page
// scope. Not directly reachable (nginx denies /lib/).
declare(strict_types=1);

// The two password sentences quote the *configured* minimum, not the 12 the
// policy happens to default to: an admin who raised it would otherwise read a
// help text that contradicts the form. portal/help.php requires the module.
$passwordMinLength = password_policy_min_length(db());
?>
    <div class="stack" id="panel-users" role="tabpanel" aria-labelledby="tab-users" tabindex="0" data-tab-panel>
        <section class="panel">
            <h2><?php echo h(__t('help.roles_heading')); ?></h2>
            <p><?php echo h(__t('help.roles_p1')); ?></p>
            <div class="table-wrap"><table>
                <thead>
                    <tr>
                        <th><?php echo h(__t('help.roles_matrix_th_feature')); ?></th>
                        <th><?php echo h(role_label(VIRTUSPHERE_ROLE_USER)); ?></th>
                        <th><?php echo h(role_label(VIRTUSPHERE_ROLE_ADMIN)); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td><?php echo h(__t('help.roles_matrix_view')); ?></td>
                        <td><?php echo portal_badge('success', __t('common.yes')); ?></td>
                        <td><?php echo portal_badge('success', __t('common.yes')); ?></td>
                    </tr>
                    <?php foreach (VIRTUSPHERE_PERMISSIONS as $permission): ?>
                        <tr>
                            <td><?php echo h(__t('help.perm_' . str_replace('.', '_', $permission))); ?></td>
                            <?php foreach ([VIRTUSPHERE_ROLE_USER, VIRTUSPHERE_ROLE_ADMIN] as $matrixRole): ?>
                                <?php $allowed = role_has_permission($matrixRole, $permission); ?>
                                <td><?php echo portal_badge($allowed ? 'success' : 'neutral', $allowed ? __t('common.yes') : __t('common.no')); ?></td>
                            <?php endforeach; ?>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table></div>
        </section>

        <?php if ($canUsers): ?>
        <section class="panel">
            <h2><?php echo h(__t('help.usersmgmt_heading')); ?></h2>
            <p><?php echo h(__t('help.usersmgmt_p1')); ?></p>
        </section>

        <section class="panel">
            <h2><?php echo h(__t('help.usersmgmt_create_heading')); ?></h2>
            <p><?php echo h(__t('help.usersmgmt_create_p1', ['min' => $passwordMinLength])); ?></p>
            <p><?php echo h(__t('help.usersmgmt_create_p2')); ?></p>
        </section>

        <section class="panel">
            <h2><?php echo h(__t('help.usersmgmt_actions_heading')); ?></h2>
            <ul>
                <li><?php echo h(__t('help.usersmgmt_action_role')); ?></li>
                <li><?php echo h(__t('help.usersmgmt_action_active')); ?></li>
                <li><?php echo h(__t('help.usersmgmt_action_reset', ['min' => $passwordMinLength])); ?></li>
            </ul>
        </section>

        <section class="panel">
            <h2><?php echo h(__t('help.usersmgmt_safety_heading')); ?></h2>
            <ul>
                <li><?php echo h(__t('help.usersmgmt_safety_1')); ?></li>
                <li><?php echo h(__t('help.usersmgmt_safety_2')); ?></li>
                <li><?php echo h(__t('help.usersmgmt_safety_3', ['minutes' => VIRTUSPHERE_LOGIN_LOCKOUT_MINUTES])); ?></li>
                <li><?php echo h(__t('help.usersmgmt_safety_4')); ?></li>
            </ul>
            <p><?php echo h(__t('help.usersmgmt_audit_p1')); ?></p>
        </section>
        <?php endif; ?>
    </div>

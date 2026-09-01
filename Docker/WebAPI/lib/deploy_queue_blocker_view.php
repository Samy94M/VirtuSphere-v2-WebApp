<?php

declare(strict_types=1);

require_once __DIR__ . '/deploy_preflight_bounds.php';

/**
 * How a queue decision is shown and serialized: the blocker/warning render, the
 * permission filter on a structured action, and the JSON shape the live
 * endpoint returns.
 *
 * Split from deploy_blockers.php so that the DECISION module stays free of
 * presentation. Both sides read the same complete union; only this one is
 * allowed to shorten a list, and only for display.
 */

/**
 * Renders the queue decision. `$blockers` is the COMPLETE list, because the
 * count, the disabled state and the jump target are derived from it; only how
 * many boxes are painted is bounded, and the omitted line says by how much.
 *
 * @param list<array<string,mixed>> $blockers
 */
function deploy_render_blockers(array $blockers, array $user, array $warnings = []): void
{
    $count = count($blockers);
    $shown = deploy_preflight_bounded_findings($blockers, VIRTUSPHERE_DEPLOY_PREFLIGHT_INITIAL_LIMIT);
    $shownWarnings = deploy_preflight_bounded_findings($warnings, VIRTUSPHERE_DEPLOY_PREFLIGHT_INITIAL_LIMIT);
    ?>
    <div data-deploy-blockers data-endpoint="deploy_blockers.php" data-error-message="<?php echo h(__t('deploy.blocker_refresh_failed')); ?>" data-hard-network-modes="<?php echo h(json_encode(deploy_modes_with_hard_network_gate(), JSON_THROW_ON_ERROR)); ?>" data-initial-limit="<?php echo h((string) VIRTUSPHERE_DEPLOY_PREFLIGHT_INITIAL_LIMIT); ?>" aria-live="polite">
        <p data-deploy-blocker-summary<?php echo $count === 0 ? ' hidden' : ''; ?>>
            <strong><?php echo h(__t($count === 1 ? 'deploy.blocker_count_one' : 'deploy.blocker_count_many', ['count' => $count])); ?></strong>
            <a href="#deploy-blocker-1" data-deploy-blocker-jump><?php echo h(__t('deploy.blocker_jump')); ?></a>
        </p>
        <div data-deploy-blocker-list>
        <?php foreach ($shown['items'] as $index => $blocker) {
            $id = (string) ($blocker['target_id'] ?? ('deploy-blocker-' . ($index + 1)));
            $kind = (string) ($blocker['kind'] ?? '');
            $action = deploy_blocker_action_for_user($blocker, $user);
            if ($kind === VIRTUSPHERE_DEPLOY_BLOCKER_PREREQUISITE || $kind === VIRTUSPHERE_DEPLOY_BLOCKER_EMPTY_MISSION || $kind === VIRTUSPHERE_DEPLOY_BLOCKER_VM_NETWORK_MAPPING) { ?>
                <?php
                // Follow-ups go into the shared .alert-actions row, not after the
                // sentence: two underlined links separated by one space read as a
                // single long link, and the scope blocker is the first one here
                // that has two. The row opens only when something fills it, and
                // the middle dot appears only between two of them, because a lone
                // link would otherwise start the row with a separator to its left.
                $help = is_array($blocker['help'] ?? null) ? $blocker['help'] : null;
                ?>
                <div class="alert alert-error" id="<?php echo h($id); ?>" data-deploy-blocker>
                    <strong><?php echo h(__t('deploy.blocker_prefix')); ?></strong>
                    <?php echo h((string) $blocker['message']); ?>
                    <?php if ($action !== null && (string) $action['type'] !== 'link') {
                        throw new LogicException('Unknown deploy blocker action for ' . $kind . ': ' . (string) $action['type']);
                    } ?>
                    <?php if ($action !== null || $help !== null) { ?>
                        <div class="alert-actions">
                            <?php if ($action !== null) { ?><a href="<?php echo h((string) $action['url']); ?>"><?php echo h((string) $action['label']); ?></a><?php } ?>
                            <?php if ($action !== null && $help !== null) { ?><span class="muted" aria-hidden="true">&middot;</span><?php } ?>
                            <?php if ($help !== null) { ?><a href="<?php echo h((string) $help['url']); ?>" data-deploy-blocker-help><?php echo h((string) $help['label']); ?></a><?php } ?>
                        </div>
                    <?php } ?>
                </div>
            <?php } elseif ($kind === VIRTUSPHERE_DEPLOY_BLOCKER_IDENTITY_CONFLICT) {
                ?>
                <div class="alert alert-error" id="<?php echo h($id); ?>" data-deploy-blocker>
                    <p><strong><?php echo h(__t('deploy.blocker_prefix')); ?></strong> <?php echo h((string) $blocker['message']); ?></p>
                    <?php if ($action !== null && (string) $action['type'] === 'adopt') { ?>
                        <form class="inline-form" method="post" action="<?php echo h((string) $action['url']); ?>">
                            <?php echo csrf_field(); ?>
                            <input type="hidden" name="action" value="adopt_vm">
                            <?php foreach ($action['fields'] as $field => $value) { ?>
                                <input type="hidden" name="<?php echo h((string) $field); ?>" value="<?php echo h((string) $value); ?>">
                            <?php } ?>
                            <button class="button button-secondary" type="submit" data-confirm="<?php echo h((string) $action['confirm']); ?>"><?php echo h((string) $action['label']); ?></button>
                        </form>
                    <?php } elseif ($action !== null && (string) $action['type'] === 'link') { ?>
                        <a href="<?php echo h((string) $action['url']); ?>"><?php echo h((string) $action['label']); ?></a>
                    <?php } elseif ($action !== null) {
                        throw new LogicException('Unknown deploy identity action: ' . (string) $action['type']);
                    } ?>
                </div>
            <?php } else {
                throw new LogicException('Unknown deploy blocker kind: ' . $kind);
            }
        } ?>
        </div>
        <?php if ($shown['omitted_count'] > 0) { ?>
            <p class="muted" data-deploy-blocker-omitted><?php echo h(__t('deploy.blocker_omitted', ['count' => $shown['omitted_count']])); ?></p>
        <?php } ?>
        <div data-deploy-warning-list>
        <?php foreach ($shownWarnings['items'] as $warning) { $action = deploy_blocker_action_for_user($warning, $user); ?>
            <div class="alert alert-warning" data-deploy-network-warning>
                <strong><?php echo h(__t('deploy.warning_prefix')); ?></strong> <?php echo h((string) $warning['message']); ?>
                <?php if ($action !== null) { ?> <a href="<?php echo h((string) $action['url']); ?>"><?php echo h((string) $action['label']); ?></a><?php } ?>
            </div>
        <?php } ?>
        </div>
        <?php if ($shownWarnings['omitted_count'] > 0) { ?>
            <p class="muted" data-deploy-warning-omitted><?php echo h(__t('deploy.blocker_omitted', ['count' => $shownWarnings['omitted_count']])); ?></p>
        <?php } ?>
    </div>
    <?php
}

/** @return null|array<string,mixed> */
function deploy_blocker_action_for_user(array $blocker, array $user): ?array
{
    $action = $blocker['action'] ?? null;
    if (!is_array($action)) {
        return null;
    }
    $permission = (string) ($action['permission'] ?? '');
    if ($permission !== '' && !can($permission, $user)) {
        return null;
    }

    return $action;
}

/** @return array<string,mixed> */
function deploy_blocker_json(array $blocker, array $user): array
{
    $result = [
        'kind' => (string) $blocker['kind'],
        'code' => (string) $blocker['code'],
        'message' => (string) $blocker['message'],
        'target_id' => (string) ($blocker['target_id'] ?? ''),
    ];
    $action = deploy_blocker_action_for_user($blocker, $user);
    if ($action !== null) {
        unset($action['permission']);
        $result['action'] = $action;
    }
    // The help follow-up travels too, or the live list would drop it on the
    // first refresh and the page would quietly show less than it did on load.
    if (is_array($blocker['help'] ?? null)) {
        $result['help'] = $blocker['help'];
    }
    return $result;
}

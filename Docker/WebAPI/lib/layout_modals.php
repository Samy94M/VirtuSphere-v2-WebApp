<?php

declare(strict_types=1);

/**
 * The portal's two modal dialogs (ADR-0013).
 *
 * Both are native <dialog class="modal"> elements sharing the .modal base in
 * components.css, so the browser owns the top layer, the focus trap and Escape.
 * They are rendered once per page by layout_footer(); nothing else may build a
 * modal, a focus trap or a z-index stack beside them.
 *
 * Included from lib/layout.php, which supplies h(), __t(), csrf_field() and
 * virtusphere_csp_nonce(). Those resolve when the functions below are called,
 * not when this file is included, so the include order stays free.
 */

/**
 * Portal-wide confirmation dialog: the one rendering of every [data-confirm]
 * prompt (missions, templates, VMs, VLANs, operating systems, credentials,
 * deploy jobs, MECM resets, machine-API allowlist, report token, password
 * resets).
 *
 * The message and the accept label are filled in from the trigger by core.js, so
 * no translatable string has to live in JavaScript (ADR-0014). method="dialog"
 * sets returnValue from the pressed button without needing a submit handler.
 * The dismiss button is autofocused: the safe choice takes the Enter key.
 */
function layout_confirm_dialog(): void
{
    // aria-modal is implied by <dialog> + showModal(); setting it explicitly is discouraged.
    ?>
<dialog class="modal modal-confirm" data-confirm-dialog role="alertdialog" aria-labelledby="confirm-dialog-title" aria-describedby="confirm-dialog-msg">
    <div class="modal-box">
        <div class="modal-icon" aria-hidden="true">&#9888;</div>
        <h3 class="modal-title" id="confirm-dialog-title"><?php echo h(__t('common.confirm')); ?></h3>
        <p class="modal-msg" id="confirm-dialog-msg" data-confirm-msg></p>
        <form class="modal-actions" method="dialog">
            <button class="button button-ghost" type="submit" value="cancel" autofocus><?php echo h(__t('common.cancel')); ?></button>
            <button class="button" type="submit" value="confirm" data-confirm-accept><?php echo h(__t('common.confirm')); ?></button>
        </form>
    </div>
</dialog>
    <?php
}

/**
 * Session-expiry warning, plus the two things only it uses: the hidden logout
 * form it posts on timeout and the CSP-nonced JSON island carrying the
 * countdown template (no translatable string in core.js, ADR-0014).
 *
 * Unlike the confirm dialog this one is not dismissable: core.js cancels Escape,
 * because the timer would reopen it a second later anyway.
 */
function layout_session_modal(): void
{
    ?>
<dialog class="modal" data-session-modal role="alertdialog" aria-labelledby="session-modal-title">
    <div class="modal-box">
        <div class="modal-icon" aria-hidden="true">&#9203;</div>
        <h3 class="modal-title" id="session-modal-title"><?php echo h(__t('layout.session_expiring_title')); ?></h3>
        <p class="modal-msg" data-session-modal-msg></p>
        <div class="modal-actions">
            <button class="button" type="button" data-session-extend><?php echo h(__t('layout.session_extend')); ?></button>
            <button class="button button-ghost" type="button" data-session-logout-now><?php echo h(__t('layout.logout_now')); ?></button>
        </div>
    </div>
</dialog>
<form class="session-logout-form" method="post" action="logout.php" data-session-logout-form hidden>
    <?php echo csrf_field(); ?>
</form>
<script type="application/json" data-i18n-session nonce="<?php echo h(virtusphere_csp_nonce()); ?>"><?php
    echo json_encode(['countdown_html' => __t('layout.session_countdown_html')], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
?></script>
    <?php
}

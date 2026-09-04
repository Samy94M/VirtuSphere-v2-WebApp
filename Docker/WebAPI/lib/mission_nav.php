<?php

declare(strict_types=1);

require_once __DIR__ . '/deploy_urls.php';

/**
 * The two mission navigations, each rendered from one place.
 *
 * Both are pairs of pages, and both used to be written out at their call sites:
 * the list switch twice inside missions.php's markup, the detail/VM pair once on
 * each of the two pages it spans. A pair whose two halves are written
 * separately is a pair that drifts - one side gains an entry, gets a different
 * label, or keeps pointing at a mission id the other has already left - and the
 * failure is invisible, because each page on its own still renders a complete,
 * plausible navigation. Here the entries and their order exist once and each
 * page says only which of them it is.
 *
 * The shape itself (links, exactly one aria-current, no tab widget semantics)
 * belongs to portal_page_nav() in lib/layout_presenters.php.
 */

/** Missions or templates: the two list pages. */
function mission_list_nav(string $type): string
{
    return portal_page_nav(__t('missions.nav_label_lists'), [
        ['href' => 'missions.php?type=missions', 'label' => __t('missions.tab_missions'), 'current' => $type === 'missions'],
        ['href' => 'missions.php?type=templates', 'label' => __t('missions.tab_templates'), 'current' => $type === 'templates'],
    ]);
}

/**
 * Details and VMs: the two pages of ONE mission.
 *
 * The VM editor is deliberately not an entry. It is opened per row and means
 * nothing without one, so it stays a sub-page of the VM list rather than a
 * third place the operator can be.
 *
 * $current is 'details' or 'vms'; anything else marks neither, which is the
 * honest answer for a page that is not one of the two.
 */
function mission_detail_nav(int $missionId, bool $isTemplate, string $current): string
{
    return portal_page_nav(
        $isTemplate ? __t('missions.nav_label_template_detail') : __t('missions.nav_label_mission_detail'),
        [
            ['href' => mission_details_url($missionId), 'label' => __t('missions.nav_details'), 'current' => $current === 'details'],
            ['href' => 'vms.php?mission_id=' . $missionId, 'label' => __t('common.vms'), 'current' => $current === 'vms'],
        ]
    );
}

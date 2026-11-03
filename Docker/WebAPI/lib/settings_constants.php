<?php

declare(strict_types=1);

// settings.php link registry: the tab and section ids a settings link may name.
// Kept next to its only URL owner, settings_url() in lib/settings_page.php
// (ADR-0006 split out of lib/constants.php, 16.10.2026).

// The same idea as the system status anchors in lib/constants.php, one page
// over: settings.php spreads its forms over tabs, and a
// link that names the page without its panel lands on the first tab, which is
// not the one holding the field the message just named. Nothing errors, so the
// operator reads the message as wrong rather than the link. These are the ids
// settings.php actually renders; settings_url() (lib/settings_page.php) is the
// only place allowed to turn one into a URL, and
// tests/Static/SettingsDeepLinkContractTest.php pins both directions.
const VIRTUSPHERE_SETTINGS_TAB_DEPLOY = 'deploy';
const VIRTUSPHERE_SETTINGS_TAB_MACHINE_API = 'machine-api';
const VIRTUSPHERE_SETTINGS_TAB_CATALOG = 'catalog';
const VIRTUSPHERE_SETTINGS_TAB_HTTPS = 'https';
const VIRTUSPHERE_SETTINGS_TAB_SYSTEM = 'system';
// Read-only release overview: does every component run the portal's release?
const VIRTUSPHERE_SETTINGS_TAB_VERSION = 'version';

const VIRTUSPHERE_SETTINGS_TABS = [
    VIRTUSPHERE_SETTINGS_TAB_DEPLOY,
    VIRTUSPHERE_SETTINGS_TAB_MACHINE_API,
    VIRTUSPHERE_SETTINGS_TAB_CATALOG,
    VIRTUSPHERE_SETTINGS_TAB_HTTPS,
    VIRTUSPHERE_SETTINGS_TAB_SYSTEM,
    VIRTUSPHERE_SETTINGS_TAB_VERSION,
];

// Sections inside a tab that a link may target directly. core.js opens the
// owning tab and scrolls to them, so they are valid anchors even though they
// are not tabpanels; the dashboard's backup banner uses one.
const VIRTUSPHERE_SETTINGS_SECTIONS = [
    'time',
    'session',
    'password-policy',
    'retention',
    'backup',
];

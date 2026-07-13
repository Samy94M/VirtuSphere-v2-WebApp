// The portal page inventory (TESTPLAN Appendix A), as the single source the
// health matrix iterates. `access` is the required visibility for a signed-in
// non-admin ('user' role has missions.write/vms.write/deploy.run, not the admin
// permissions); 'admin' means the page denies a plain user.
//
// Pages that need a query parameter to render a concrete object carry a `query`
// builder run against seeded ids; the health matrix uses the list/landing form
// where an id is optional.

const PORTAL_PAGES = [
  { path: 'dashboard.php', access: 'user', title: /Dashboard|Übersicht/i },
  { path: 'missions.php?type=missions', access: 'user' },
  { path: 'vms.php', access: 'user', note: 'redirects to missions without a mission_id; that is expected' },
  { path: 'os.php', access: 'user' },
  { path: 'packages.php', access: 'user' },
  { path: 'vlans.php', access: 'user' },
  { path: 'deploy.php', access: 'user' },
  { path: 'account.php', access: 'user' },
  { path: 'help.php', access: 'user' },

  { path: 'credentials.php', access: 'admin' },
  // integrations.php is viewable by any signed-in user: it uses
  // portal_require_user() and gates the privileged actions per feature (inventory
  // refresh needs deploy.run, which the user role has; VLAN reassign needs
  // missions.write+vms.write; the log link needs users.manage). It renders
  // integration status, never a credential secret, so it is a user page like the
  // read-only catalog pages, not an admin page. Verified against the guards in
  // portal/integrations.php.
  { path: 'integrations.php', access: 'user' },
  { path: 'users.php', access: 'admin' },
  { path: 'settings.php', access: 'admin' },
  { path: 'logs.php', access: 'admin' },
];

// Endpoints that are not full HTML pages: excluded from the visual/health
// matrix, characterized separately (login/logout/session_ping/health/index are
// covered by the PHPUnit + curl contracts).
const NON_PAGE_ENDPOINTS = [
  'login.php',
  'logout.php',
  'session_ping.php',
  'index.php',
  'health.php',
];

module.exports = { PORTAL_PAGES, NON_PAGE_ENDPOINTS };

// The portal page inventory (TESTPLAN Appendix A), as the single source the
// health matrix iterates. `access` is the required visibility for a signed-in
// non-admin ('user' role has missions.write/vms.write/deploy.run, not the admin
// permissions); 'admin' means the page denies a plain user.
//
// Pages that need a query parameter to render a concrete object carry a `query`
// builder, called with the ids from lib/matrix-seed.js and returning the query
// string without its "?". Use `pageUrl(pageDef, ids)` to build the address, and
// `pageLabel(pageDef)` for the test title, because one path may appear twice
// with different queries.
//
// This used to be described here and implemented nowhere: both consumers read
// pageDef.path only, so the two editor pages were simply absent from the matrix
// and deploy.php was scanned in the one state where it renders neither its
// storage table nor its warning boxes.

const PORTAL_PAGES = [
  { path: 'dashboard.php', access: 'user', title: /Dashboard|Übersicht/i },
  { path: 'missions.php?type=missions', access: 'user' },
  { path: 'vms.php', access: 'user', redirects: true, note: 'redirects to missions without a mission_id; that is expected' },
  { path: 'os.php', access: 'user' },
  { path: 'packages.php', access: 'user' },
  { path: 'vlans.php', access: 'user' },
  { path: 'deploy.php', access: 'user' },
  // The same page with a mission and a chosen ESXi credential. Without both, the
  // storage table, the capacity bars and the two warning boxes do not exist in
  // the DOM at all, so neither axe nor the health check ever saw them.
  {
    path: 'deploy.php',
    label: 'deploy.php (mission)',
    access: 'user',
    query: (ids) => `mission_id=${ids.missionId}&credential_esxi_id=${ids.esxiId}`,
  },
  { path: 'account.php', access: 'user' },
  { path: 'help.php', access: 'user' },

  // The two editor pages. Both were missing from the inventory entirely: no axe
  // scan, no i18n check, no console-error or off-host proof, while help.php in
  // this same list renders help_system_status.* keys.
  { path: 'mission_details.php', access: 'user', query: (ids) => `id=${ids.missionId}` },
  // vm_edit.php needs BOTH ids: it redirects to the mission list without a
  // mission_id and to the VM list without a vm_id, and a redirect still answers
  // 200 from the page it lands on, so a wrong parameter name would leave the
  // matrix scanning missions.php under this page's name.
  { path: 'vm_edit.php', access: 'user', query: (ids) => `mission_id=${ids.missionId}&vm_id=${ids.vmId}` },

  { path: 'credentials.php', access: 'admin' },
  // system_status.php is viewable by any signed-in user: it uses
  // portal_require_user() and gates the privileged actions per feature (inventory
  // refresh needs deploy.run, which the user role has; VLAN reassign needs
  // missions.write+vms.write; the log link needs users.manage). It renders
  // integration status, never a credential secret, so it is a user page like the
  // read-only catalog pages, not an admin page. Verified against the guards in
  // portal/system_status.php.
  { path: 'system_status.php', access: 'user' },
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

/**
 * The address to navigate to. Throws rather than silently dropping the query
 * when a page declares one and no ids were seeded: a page quietly scanned in its
 * empty state is the failure this mechanism exists to fix.
 *
 * @param {{path: string, query?: (ids: object) => string}} pageDef
 * @param {object|null} ids
 */
function pageUrl(pageDef, ids) {
  if (!pageDef.query) {
    return pageDef.path;
  }
  if (!ids) {
    throw new Error(`${pageDef.path} declares a query builder but no seeded ids were passed`);
  }
  const query = pageDef.query(ids);
  if (!query) {
    throw new Error(`${pageDef.path}: query builder returned nothing`);
  }

  return pageDef.path + (pageDef.path.includes('?') ? '&' : '?') + query;
}

/** Test title. A path can appear more than once, so a label wins where set. */
function pageLabel(pageDef) {
  return pageDef.label || pageDef.path;
}

module.exports = { PORTAL_PAGES, NON_PAGE_ENDPOINTS, pageUrl, pageLabel };

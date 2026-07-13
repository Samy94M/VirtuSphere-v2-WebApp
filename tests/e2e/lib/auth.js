// Role fixtures and the storageState paths the auth setup writes and the specs
// reuse. Credentials come from the environment with the documented dev defaults
// (portal-screenshot-setup); the plain-user account is seeded by auth.setup.js.

const path = require('node:path');

const AUTH_DIR = path.join(__dirname, '..', '.auth');

const ROLES = {
  admin: {
    username: process.env.VIRTUSPHERE_ADMIN_USER || 'admin',
    password: process.env.VIRTUSPHERE_ADMIN_PASS || 'admin12345678',
    storageState: path.join(AUTH_DIR, 'admin.json'),
    seeded: false,
  },
  user: {
    username: 'e2e_user',
    password: 'E2eUser-12345',
    storageState: path.join(AUTH_DIR, 'user.json'),
    seeded: true,
  },
};

module.exports = { ROLES, AUTH_DIR };

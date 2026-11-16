'use strict';

// Runs INSIDE the visual browser container (VC1), never on the host. metadata.js
// pipes this file to `docker exec -i <container> node - <queries>` on stdin: the
// container holds no source and no bind mount, so the probe arrives as data and
// leaves nothing behind. It may require only Node built-ins and the server's own
// playwright-core under the image WORKDIR (Docker/qa/visual-browser/Dockerfile).
//
// Everything it reports is measured in the container that renders the images:
// the platform, the distribution release, the fonts fontconfig hands Chromium
// for the contract's queries, a digest over the whole installed font set, and
// the Chromium install the server will launch.

const { execFileSync } = require('node:child_process');
const crypto = require('node:crypto');
const fs = require('node:fs');
const path = require('node:path');

const PLAYWRIGHT_ROOT = '/opt/visual/node_modules/playwright-core';

function sha256(data) {
  return crypto.createHash('sha256').update(data).digest('hex');
}

function fontconfig(args) {
  return execFileSync(args[0], args.slice(1), { encoding: 'utf8', stdio: ['ignore', 'pipe', 'ignore'] });
}

function matchFont(query) {
  try {
    const file = fontconfig(['fc-match', '--format=%{file}', query]).trim();
    return path.isAbsolute(file) && fs.existsSync(file) ? file : null;
  } catch (_) {
    return null;
  }
}

function probe(queries) {
  // One line per installed font file; the digest covers path and content, so an
  // added, removed or changed font anywhere in the image changes it.
  const files = [...new Set(fontconfig(['fc-list', '--format=%{file}\\n']).split('\n').filter(Boolean))].sort();
  const fontSetLines = files.map((file) => `${sha256(fs.readFileSync(file))}  ${file}\n`).join('');
  const chromium = require(PLAYWRIGHT_ROOT).chromium.executablePath();
  return {
    platform: process.platform,
    arch: process.arch,
    osReleaseText: fs.readFileSync('/etc/os-release', 'utf8'),
    playwrightVersion: require(path.join(PLAYWRIGHT_ROOT, 'package.json')).version,
    chromiumExecutable: chromium,
    chromiumExists: fs.existsSync(chromium),
    fonts: queries.map((query) => {
      const file = matchFont(query);
      return { query, file: file === null ? null : path.basename(file), sha256: file === null ? null : sha256(fs.readFileSync(file)) };
    }),
    fontSet: { count: files.length, sha256: sha256(fontSetLines) },
  };
}

if (require.main === module || require.main === undefined) {
  process.stdout.write(JSON.stringify(probe(JSON.parse(process.argv[2] || '[]'))));
}

module.exports = { probe };

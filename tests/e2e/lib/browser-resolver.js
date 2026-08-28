'use strict';

const fs = require('node:fs');
const path = require('node:path');
const playwright = require('playwright-core');

const SUPPORTED_ENGINES = Object.freeze(['chromium', 'firefox', 'webkit']);

function resolveBrowser(engine, options = {}) {
  if (!SUPPORTED_ENGINES.includes(engine)) {
    throw new Error(`unsupported Playwright engine: ${engine}`);
  }

  const env = options.env || process.env;
  const exists = options.exists || fs.existsSync;
  const browserTypes = options.browserTypes || playwright;
  const explicit = engine === 'chromium' ? env.PLAYWRIGHT_CHROMIUM : '';
  const executablePath = path.resolve(explicit || browserTypes[engine].executablePath());
  const revisionMatch = executablePath.match(/[\\/](?:chromium|firefox|webkit)(?:_headless_shell)?-(\d+)[\\/]/);

  return Object.freeze({
    engine,
    executablePath,
    exists: exists(executablePath),
    source: explicit ? 'PLAYWRIGHT_CHROMIUM' : 'playwright-core',
    revision: revisionMatch ? revisionMatch[1] : null,
  });
}

function main(argv) {
  const engineIndex = argv.indexOf('--engine');
  const engine = engineIndex >= 0 ? argv[engineIndex + 1] : 'chromium';
  try {
    const resolved = resolveBrowser(engine);
    process.stdout.write(JSON.stringify(resolved) + '\n');
    return resolved.exists ? 0 : 2;
  } catch (error) {
    process.stderr.write(`browser resolver: ${error.message}\n`);
    return 2;
  }
}

if (require.main === module) {
  process.exitCode = main(process.argv.slice(2));
}

module.exports = { SUPPORTED_ENGINES, resolveBrowser, main };

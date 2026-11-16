'use strict';

// The runner identity a reviewed image is bound to, MEASURED in the one place
// that renders it: the digest-pinned visual browser container (VC1). Until VC1
// the identity was the Windows host's own Segoe UI and kernel build, and three
// Windows updates forced a re-capture by exception; the CI on Linux could never
// match it. Now the host runs the test runner, seed and comparison, and only the
// browser runs in the container, so every value here comes from there:
//   * platform, arch, distribution release, the Chromium install and the fonts
//     fontconfig resolves, through container-probe.js on `docker exec`;
//   * the base image, from the running container's label;
//   * the Chromium version and the platform font it renders the portal's body
//     stack with, from the connected browser (CDP CSS.getPlatformFontsForNode),
//     and the switches its process really carries, from /proc in the container.
// A run without the container measures nothing and therefore mismatches: that
// is the infrastructure_error the contract wants, never a guessed success.

const { execFileSync } = require('node:child_process');
const fs = require('node:fs');
const path = require('node:path');
const { chromium } = require('playwright-core');
const { revisionFromExecutablePath } = require('../lib/browser-resolver');

const BASE_IMAGE_LABEL = 'org.opencontainers.image.base.name';
const DOCKER_TIMEOUT_MS = 60000;

function platformRelease(platform, fallback, releaseText) {
  if (platform !== 'linux') return fallback;
  const fields = Object.fromEntries(
    String(releaseText || '')
      .split(/\r?\n/)
      .filter((line) => /^[A-Z0-9_]+=/.test(line))
      .map((line) => {
        const separator = line.indexOf('=');
        return [line.slice(0, separator), line.slice(separator + 1).replace(/^['"]|['"]$/g, '')];
      })
  );
  // The distribution release, not the kernel: a hosted runner changes its
  // kernel independently of the userspace that supplies Chromium and fontconfig.
  if (fields.ID && fields.VERSION_ID) return `${fields.ID}-${fields.VERSION_ID}`;
  return fallback;
}

function compareMetadata(expected, actual) {
  const mismatches = [];
  const visit = (want, got, key) => {
    if (Array.isArray(want)) {
      if (!Array.isArray(got) || want.length !== got.length) {
        mismatches.push(`${key}: expected ${want.length} entries`);
        return;
      }
      want.forEach((value, index) => visit(value, got[index], `${key}[${index}]`));
      return;
    }
    if (want && typeof want === 'object') {
      if (!got || typeof got !== 'object') {
        mismatches.push(`${key}: missing object`);
        return;
      }
      Object.keys(want).forEach((child) => visit(want[child], got[child], key ? `${key}.${child}` : child));
      return;
    }
    if (want !== got) {
      mismatches.push(`${key}: expected ${JSON.stringify(want)}, got ${JSON.stringify(got)}`);
    }
  };
  visit(expected, actual, '');
  return mismatches;
}

/** One docker exec for the whole probe and one inspect for the label. */
function probeContainer(container, queries, exec = execFileSync) {
  const script = fs.readFileSync(path.join(__dirname, 'container-probe.js'), 'utf8');
  const measured = JSON.parse(exec('docker', ['exec', '-i', container, 'node', '-', JSON.stringify(queries)], {
    input: script,
    encoding: 'utf8',
    timeout: DOCKER_TIMEOUT_MS,
    stdio: ['pipe', 'pipe', 'pipe'],
  }));
  const labels = JSON.parse(exec('docker', ['inspect', '--format', '{{json .Config.Labels}}', container], {
    encoding: 'utf8',
    timeout: DOCKER_TIMEOUT_MS,
    stdio: ['ignore', 'pipe', 'pipe'],
  })) || {};
  return { ...measured, image: labels[BASE_IMAGE_LABEL] || null };
}

// The portal's own body font stack, read from the stylesheet the pages load, so
// the rendered-font probe asks Chromium the question the captured pages ask.
const PORTAL_BASE_CSS = path.join(__dirname, '..', '..', '..', 'Docker', 'WebAPI', 'portal', 'assets', 'css', 'base.css');

function portalFontStack(css = fs.readFileSync(PORTAL_BASE_CSS, 'utf8')) {
  const match = css.match(/font-family:\s*(system-ui[^;]*);/);
  if (!match) throw new Error(`portal body font stack not found in ${PORTAL_BASE_CSS}`);
  return match[1].trim();
}

/**
 * The browser main process's arguments, read from /proc inside the container
 * while the probe holds the browser open. CDP only returns the command line
 * with --enable-automation, which the contract does not set, and the process
 * table is the measurement that cannot be told something else.
 */
function readBrowserProcessArgs(container, exec = execFileSync) {
  const script = [
    "const fs=require('fs');const out=[];",
    "for(const pid of fs.readdirSync('/proc').filter(n=>/^\\d+$/.test(n))){",
    "try{out.push(fs.readFileSync('/proc/'+pid+'/cmdline','utf8').split('\\0').filter(Boolean))}catch(_){}}",
    'process.stdout.write(JSON.stringify(out))',
  ].join('');
  const processes = JSON.parse(exec('docker', ['exec', container, 'node', '-e', script], {
    encoding: 'utf8',
    timeout: DOCKER_TIMEOUT_MS,
    stdio: ['ignore', 'pipe', 'pipe'],
  }));
  const browsers = processes.filter((argv) => argv.includes('--remote-debugging-pipe') && !argv.some((arg) => arg.startsWith('--type=')));
  if (browsers.length !== 1) throw new Error(`expected one browser process in ${container}, found ${browsers.length}`);
  return browsers[0];
}

/**
 * Connects exactly the way the visual Playwright project does (launch options
 * in the header the test runner sends) and measures, while connected, the
 * browser version, the switches the process really carries and the platform
 * font Chromium renders the portal's body stack with (regular and bold). A
 * server that dropped a switch, or a font set that resolves the stack to
 * another face, would otherwise change pixels without any value here changing.
 */
async function probeBrowser(endpoint, launchArgs, { readProcessArgs, fontStack }, browserType = chromium) {
  const browser = await browserType.connect(endpoint, {
    timeout: DOCKER_TIMEOUT_MS,
    headers: { 'x-playwright-launch-options': JSON.stringify({ headless: true, args: launchArgs }) },
  });
  try {
    const commandLine = readProcessArgs();
    const page = await browser.newPage();
    await page.setContent(
      `<body style="margin:0;font-family:${fontStack.replace(/"/g, '&quot;')}">`
        + '<p id="regular">Prüfung fällig 27.08.2026</p><p id="bold" style="font-weight:700">Prüfung fällig 27.08.2026</p></body>'
    );
    const cdp = await page.context().newCDPSession(page);
    await cdp.send('DOM.enable');
    await cdp.send('CSS.enable');
    const { root } = await cdp.send('DOM.getDocument');
    const renderedFonts = [];
    for (const weight of ['regular', 'bold']) {
      const { nodeId } = await cdp.send('DOM.querySelector', { nodeId: root.nodeId, selector: `#${weight}` });
      const { fonts } = await cdp.send('CSS.getPlatformFontsForNode', { nodeId });
      renderedFonts.push({
        weight,
        fonts: fonts.map((font) => ({ familyName: font.familyName, postScriptName: font.postScriptName, isCustomFont: font.isCustomFont })),
      });
    }
    return { version: browser.version(), commandLine, renderedFonts };
  } finally {
    await browser.close();
  }
}

/**
 * Builds the actual identity from what was measured; anything that could not be
 * measured stays null and is named in `errors`, so the comparison fails with
 * the reason instead of a bare "got null".
 */
function buildActual(contract, { container, browser, clientVersion }) {
  const errors = [];
  if (container.error) errors.push(`visual browser container not measurable: ${container.error}`);
  if (browser.error) errors.push(`visual browser not reachable: ${browser.error}`);
  const probe = container.value || null;
  const live = browser.value || null;
  if (probe && !probe.chromiumExists) errors.push(`container Chromium missing: ${probe.chromiumExecutable}`);
  const missingArgs = live ? contract.launchArgs.filter((arg) => !live.commandLine.includes(arg)) : [];
  if (missingArgs.length > 0) errors.push(`browser started without contract switches: ${missingArgs.join(' ')}`);
  const serverVersion = probe ? probe.playwrightVersion : null;

  return {
    errors,
    actual: {
      ...contract,
      platform: probe ? probe.platform : null,
      arch: probe ? probe.arch : null,
      osRelease: probe ? platformRelease(probe.platform, null, probe.osReleaseText) : null,
      container: { image: probe ? probe.image : null },
      browser: {
        engine: 'chromium',
        revision: probe ? revisionFromExecutablePath(probe.chromiumExecutable) : null,
        version: live ? live.version : null,
      },
      // Client and server must be the same build; a split names both.
      playwrightVersion: serverVersion === clientVersion ? clientVersion : `client ${clientVersion}, container ${serverVersion}`,
      fonts: contract.fonts.map((font) => {
        const found = probe ? probe.fonts.find((entry) => entry.query === font.query) : null;
        return found || { query: font.query, file: null, sha256: null };
      }),
      fontSet: probe ? probe.fontSet : null,
      renderedFonts: live ? live.renderedFonts : null,
      launchArgs: live ? contract.launchArgs.filter((arg) => live.commandLine.includes(arg)) : null,
    },
  };
}

async function collectMetadata(contract, env = process.env, deps = {}) {
  const container = env.VIRTUSPHERE_VISUAL_BROWSER_CONTAINER;
  const endpoint = env.VIRTUSPHERE_VISUAL_BROWSER_ENDPOINT;
  const measureContainer = deps.probeContainer || probeContainer;
  const measureBrowser = deps.probeBrowser || probeBrowser;
  const containerResult = {};
  const browserResult = {};
  if (!container || !endpoint) {
    containerResult.error = 'VIRTUSPHERE_VISUAL_BROWSER_CONTAINER and VIRTUSPHERE_VISUAL_BROWSER_ENDPOINT are required';
  } else {
    try {
      containerResult.value = measureContainer(container, contract.fonts.map((font) => font.query));
    } catch (error) {
      containerResult.error = String(error.message || error).split('\n')[0];
    }
    try {
      browserResult.value = await measureBrowser(endpoint, contract.launchArgs, {
        readProcessArgs: () => (deps.readBrowserProcessArgs || readBrowserProcessArgs)(container),
        fontStack: portalFontStack(),
      });
    } catch (error) {
      browserResult.error = String(error.message || error).split('\n')[0];
    }
  }
  const clientVersion = deps.clientVersion || require('@playwright/test/package.json').version;
  return buildActual(contract, { container: containerResult, browser: browserResult, clientVersion });
}

async function validateMetadata(contract, env = process.env, deps = {}) {
  const { actual, errors } = await collectMetadata(contract, env, deps);
  const mismatches = [...errors, ...compareMetadata(contract, actual)];
  return { actual, mismatches, ok: mismatches.length === 0 };
}

module.exports = {
  BASE_IMAGE_LABEL,
  buildActual,
  collectMetadata,
  compareMetadata,
  platformRelease,
  portalFontStack,
  probeBrowser,
  probeContainer,
  readBrowserProcessArgs,
  validateMetadata,
};

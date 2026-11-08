'use strict';

// Playwright reporter that merges the per-worker exec records (lib/exec-profile.js)
// into test-results/exec-profile.json when the run ends, and keeps a copy in the
// run's HTML report folder. test-results/ belongs to the run: the next Playwright
// run, even the visual one inside the same gate, empties it. The report folder
// is per gate (VIRTUSPHERE_E2E_REPORT_DIR), so the copy there survives the gates
// that follow. This reporter is listed after the HTML reporter, which clears its
// folder when it writes.

const fs = require('node:fs');
const path = require('node:path');
const { readParts, summarize, PROFILE_FILE, RESULTS_DIR } = require('./exec-profile');

const E2E_DIR = path.resolve(__dirname, '..');

class ExecProfileReporter {
  onBegin(config, suite) {
    this.startedAt = Date.now();
    this.projects = suite.suites.map((s) => s.title);
  }

  onEnd(result) {
    const profile = summarize(readParts(), {
      projects: this.projects,
      runStatus: result.status,
      runMs: Date.now() - this.startedAt,
    });
    const json = JSON.stringify(profile, null, 2) + '\n';
    fs.mkdirSync(RESULTS_DIR, { recursive: true });
    fs.writeFileSync(PROFILE_FILE, json);
    const reportDir = path.resolve(E2E_DIR, process.env.VIRTUSPHERE_E2E_REPORT_DIR || 'playwright-report');
    let copy = '';
    try {
      fs.mkdirSync(reportDir, { recursive: true });
      fs.writeFileSync(path.join(reportDir, 'exec-profile.json'), json);
      copy = `, copy in ${path.relative(E2E_DIR, reportDir)}`;
    } catch (error) {
      copy = `, no copy in the report folder (${error.message})`;
    }
    const t = profile.total;
    process.stdout.write(
      `exec-profile: ${t.calls} seed/proof calls, ${t.dockerExec} docker exec, `
      + `${(t.totalMs / 1000).toFixed(1)} s waited (p50 ${t.p50Ms} ms, max ${t.maxMs} ms), `
      + `${t.failed} failed, channel ${profile.channel} -> ${path.relative(E2E_DIR, PROFILE_FILE)}${copy}\n`,
    );
  }

  printsToStdio() {
    return false;
  }
}

module.exports = ExecProfileReporter;

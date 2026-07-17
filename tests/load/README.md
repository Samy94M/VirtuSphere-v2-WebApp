# Portal load probes (dev-host only)

k6 load tests for the portal (TESTPLAN 4.7). Dev-host tooling, like the Playwright layer (ADR-0028): not vendored, not in CI, run on demand against the running stack. k6 executes the scripts directly, so there is nothing to install into the repo.

Two profiles:

- **`portal-read.js`** — session-based and read-heavy: **each VU signs in as its own operator**, then polls the pages an operator actually sits on — dashboard, the mission and VM lists, and `health.php`. A second scenario models the monitor: `constant-arrival-rate` against `health.php`, with a `dropped_iterations` threshold so a rate k6 could not sustain fails the run instead of quietly measuring a gentler one. LAN reality is a handful of operators, so this asks whether the portal stays quick and error-free under a realistic-plus concurrency, not whether it can be stressed to breaking.
- **`portal-write.js`** — the write path, **throwaway stacks only**: each arrival creates, edits and deletes its own mission through the real portal forms (CSRF and all), and proves that a stale double-delete is a refusal, never a 500. Two locks keep it away from real data: `BASE` has no default, and the run refuses to start without `VS_ALLOW_WRITES=1`. Deploy enqueue/cancel/retry live in the Playwright deploy spec, which has the seeded credentials and worker choreography a load iteration cannot carry.

Both profiles carry a `checks` threshold: a k6 check without one is reported but cannot fail the run, and every check here is a false-green detector.

## Run

k6 runs from a container that shares the web server's network namespace, so it reaches nginx on its internal port (8080) without publishing anything:

```bash
docker run --rm --network container:virtusphere-v2-webapp-webserver-1 \
  -v "$(pwd)/tests/load:/scripts" grafana/k6 run /scripts/portal-read.js

# write profile: ONLY against a throwaway stack (for example the QA stack)
docker run --rm --network container:virtusphere-qa-webserver-1 \
  -v "$(pwd)/tests/load:/scripts" \
  -e BASE=http://localhost:8080/portal -e VS_ALLOW_WRITES=1 \
  grafana/k6 run /scripts/portal-write.js
```

On Git Bash prefix it with `MSYS_NO_PATHCONV=1 MSYS2_ARG_CONV_EXCL='*'`, or the mount path is mangled into a Windows path and k6 cannot find the script.

Override `BASE`, `VS_USER`, `VS_PASS` via `-e` if needed. The thresholds are the contract: `http_req_failed` under 1 %, `checks` above 99 %, p95 under 800 ms for the list pages, 300 ms for health, 1 s for the write actions, and zero dropped iterations in the arrival-rate scenarios. A run that trips a threshold names the page or action.

## Two traps that make a load test lie

Both were live in the first version of this script, and both produced a *confidently green* run:

1. **One session for all VUs.** The original logged in once in `setup()` and handed the same `PHPSESSID` to every VU. PHP's file session handler holds an exclusive lock on the session file for the whole request (no first-party code calls `session_write_close()`), so 30 VUs queued behind one lock. The measured latency was mostly VUs waiting for each other. It also modelled something impossible: thirty operators do not share one browser session. Each VU now signs in for itself.

2. **A status-code check on a page that redirects.** The portal answers an unauthenticated request with a redirect to the login form — which is a 200, and k6 follows redirects. A check of `status < 400` therefore passes while the run measures nothing but the login page. Every check now asserts a *signed-in* page (the layout's logout form), not a status code.

A related detail worth keeping: k6 empties the per-VU cookie jar between iterations, so the session is put back into the jar each iteration (`jar.set`) rather than passed as a `Cookie` header. A hand-set header is not carried across a redirect, and `vms.php` answers with a 302 — the header version silently went out anonymous on exactly that page.

## Baseline (2026-07-13, dev stack)

Ramp to 30 concurrent VUs (3× a realistic LAN peak), one minute: **0 % errors over 3530 requests, all checks green**, all p95 thresholds met with a wide margin:

| Page | p95 | Threshold |
|---|---|---|
| dashboard.php | 61 ms | 800 ms |
| missions.php | 54 ms | 800 ms |
| vms.php | 50 ms | 800 ms |
| health.php | 27 ms | 300 ms |

**This supersedes the 2026-07-12 baseline** (dashboard 601 ms, health 121 ms, missions 773 ms, vms 463 ms), which was measured with the shared session above and was therefore mostly session-lock contention, not portal work. `missions.php` was recorded as "the slowest page, closest to its threshold, the first place to look if list latency regresses" — that conclusion was an artefact of the test and is withdrawn.

The numbers are still taken against a small dev dataset. Volume behaviour (1000+ VMs, 100+ missions, 10000+ log rows, TESTPLAN 4.4) is a separate question and is not answered here.

## Baseline (2026-07-17, dev stack)

Hardened read profile (operators ramp to 30 VUs plus the 5/s monitor scenario, one minute): **0 % errors over 3740 requests, 0 dropped iterations**, p95 dashboard 74 ms, missions 70 ms, vms 63 ms, health 39 ms — all far under their thresholds. Write profile (2 arrivals/s, one minute, self-owned rows): **0 % errors over 1008 requests, 121 complete create→update→delete→double-delete iterations**, p95 create 146 ms, update 48 ms, delete 49 ms.

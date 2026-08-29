<?php

declare(strict_types=1);

// How many retained job-log rows a read may return, and the two numbers the
// browser needs to behave like a log viewer.
//
// Its own file because it is its own domain: the writer budgets (line size,
// per-job volume, the outage spool) belong to lib/deploy_job_output.php and
// lib/deploy_constants.php respectively and answer "what may be stored", while
// every constant here answers "how much of it may be read at once". They change
// for different reasons, and lib/deploy_constants.php reached the ADR-0006
// budget carrying both.
//
// Loaded by lib/deploy_constants.php, so no caller changed.

// Job-log read windows (Etappe 10A). Writers keep their separate byte budgets;
// these constants only bound how retained rows reach PHP and the browser.
const VIRTUSPHERE_DEPLOY_LOG_INITIAL_TAIL_LIMIT = 1000;
const VIRTUSPHERE_DEPLOY_LOG_FORWARD_LIMIT = 500;
const VIRTUSPHERE_DEPLOY_LOG_OLDER_LIMIT = 500;
const VIRTUSPHERE_DEPLOY_LOG_QUERY_LIMIT_MAX = 1000;
const VIRTUSPHERE_DEPLOY_LOG_DOM_WINDOW = 1500;

// How far from the bottom still counts as "the reader is at the end" (Etappe
// 13). It is not a comfort margin: `scrollTop` is fractional on a zoomed or
// fractionally scaled display while `scrollHeight` and `clientHeight` are
// rounded integers, so `scrollTop + clientHeight === scrollHeight` is false at
// the actual bottom often enough to matter. Without the tolerance the follow
// mode would silently pause itself on exactly those displays, and the operator
// would see a log that stopped moving for no visible reason. It lives here so
// the browser reads it from an attribute instead of carrying a second number.
const VIRTUSPHERE_DEPLOY_LOG_BOTTOM_TOLERANCE_PX = 4;

// How long the throttled log status waits before it speaks again. A screen
// reader must hear "42 new lines", not forty-two separate lines, and a batch
// arriving every two seconds must not restart the sentence each time.
const VIRTUSPHERE_DEPLOY_LOG_STATUS_THROTTLE_MS = 4000;

// Filtered reads (Etappe 13). The search page is deliberately smaller than a
// drain batch: it is read by a person looking for one line, not by a client
// catching up, and a filtered view that says "the first N hits" has to keep N
// small enough that the sentence is worth reading. The marker limit bounds the
// phase timeline; a run writes one pair per playbook step, so it is reached
// only by a job whose sequence is itself broken.
const VIRTUSPHERE_DEPLOY_LOG_SEARCH_LIMIT = 200;
const VIRTUSPHERE_DEPLOY_LOG_MARKER_LIMIT = 200;
const VIRTUSPHERE_DEPLOY_LOG_RAW_BATCH_SIZE = 500;

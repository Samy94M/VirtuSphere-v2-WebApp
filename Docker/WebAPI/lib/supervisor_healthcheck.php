<?php

declare(strict_types=1);

// Compose healthcheck for the deploy supervisor (Etappe 14C): exit 0 while the
// SUPERVISOR's heartbeat is fresh, 1 otherwise.
//
// It judges supervisor liveness and nothing else. Not the child, not the queue,
// not the database. A deliberately paused but responsive service is `healthy`
// here, because "is this container alive" and "is work getting through" are two
// questions, and answering the second one in the first one's place is how an
// operator ends up restarting a service that was doing exactly what it was
// told. Whether jobs are being taken up is the claim axis on the System status
// page, which has a person reading it.
//
// Docker `unhealthy` is consequently never a restart claim anywhere in this
// product; it is a report.

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/supervisor_heartbeat.php';

exit(supervisor_heartbeat_is_fresh() ? 0 : 1);

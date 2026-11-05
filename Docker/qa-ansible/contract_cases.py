"""Run independent QA contract cases on a small, bounded thread pool (QL2).

Every case of the Ansible contract scripts owns its temporary directory, its
environment and its own ansible-playbook process, so cases can run side by
side. Most of a case is waiting for that process, which is why threads are
enough.

Progress follows the repository contract (A22) per case: `[n/total] RUN <case>`
is printed right before the case starts and `[n/total] <result> <case>` right
after it ends. With more than one worker the lines of different cases
interleave and the result numbers are not in order; every number still appears
once with RUN and once with its result, and each line is written whole under a
lock. After the first failure no further case starts; cases already running
finish and report, like the serial loop that stopped at the first failure.

VIRTUSPHERE_QA_CONTRACT_WORKERS sets the worker count, 1 to 8, default 4.
1 runs the cases one after another in the calling thread.
"""
from __future__ import annotations

from concurrent.futures import ThreadPoolExecutor
import os
import threading
import traceback
from typing import Callable, Mapping, Sequence

WORKERS_ENV = 'VIRTUSPHERE_QA_CONTRACT_WORKERS'
DEFAULT_WORKERS = 4
MAX_WORKERS = 8


def worker_count(environ: Mapping[str, str] = os.environ) -> int:
    raw = environ.get(WORKERS_ENV, '').strip()
    if raw == '':
        return DEFAULT_WORKERS
    if not raw.isdigit() or not 1 <= int(raw) <= MAX_WORKERS:
        raise SystemExit(f'{WORKERS_ENV} must be a whole number from 1 to {MAX_WORKERS}, got {raw!r}')
    return int(raw)


def run_cases(cases: Sequence[tuple[str, Callable[[], None]]], *, passed: str = 'pass', failed: str = 'fail',
              error_on_fail_line: bool = False, workers: int | None = None) -> int:
    """Run (label, body) pairs; return 0 when every case passed, otherwise 1.

    error_on_fail_line puts the exception text on the fail line itself
    (`[n/total] FAIL <case>: <error>`); otherwise the traceback follows the
    fail line, written together with it.
    """
    total = len(cases)
    count = worker_count() if workers is None else workers
    lock = threading.Lock()
    stop = threading.Event()
    failures: list[int] = []

    def emit(text: str) -> None:
        with lock:
            print(text, flush=True)

    def unit(position: int, label: str, body: Callable[[], None]) -> None:
        if stop.is_set():
            return
        emit(f'[{position}/{total}] RUN {label}')
        try:
            body()
        except Exception as exc:
            stop.set()
            with lock:
                failures.append(position)
            if error_on_fail_line:
                emit(f'[{position}/{total}] {failed} {label}: {exc}')
            else:
                emit(f'[{position}/{total}] {failed} {label}\n' + traceback.format_exc().rstrip())
            return
        emit(f'[{position}/{total}] {passed} {label}')

    emit(f'contract cases: {total}, workers: {count}')
    if count == 1:
        for position, (label, body) in enumerate(cases, 1):
            unit(position, label, body)
            if stop.is_set():
                break
    else:
        with ThreadPoolExecutor(max_workers=count) as pool:
            futures = [pool.submit(unit, position, label, body) for position, (label, body) in enumerate(cases, 1)]
            for future in futures:
                future.result()
    return 1 if failures else 0

'use strict';

// Stand-in for the PHP loop of lib/php-channel-loop.js in exec-channel.test.js:
// the same frame protocol, with payloads that act out the cases the client has
// to survive. Never used against a container.

const mode = process.argv[2] || 'normal';

if (mode === 'no-start') {
  process.stderr.write('Error response from daemon: No such container: fake\n');
  process.exit(1);
}
if (mode === 'bad-ready') {
  process.stdout.write('HELLO\n');
} else if (mode !== 'silent') {
  process.stdout.write(`READY 1 ${process.pid}\n`);
}

let buf = Buffer.alloc(0);
let pending = null;

function answer(exit, stdout, stderr, status = 'done') {
  const out = Buffer.from(stdout, 'utf8');
  const err = Buffer.from(stderr, 'utf8');
  process.stdout.write(Buffer.concat([Buffer.from(`RES ${exit} ${out.length} ${err.length} ${status}\n`), out, err]));
}

function run(payload, timeoutMs) {
  const [cmd, ...rest] = payload.split(' ');
  const arg = rest.join(' ');
  switch (cmd) {
    case 'echo': return answer(0, arg, '');
    case 'pid': return answer(0, String(process.pid), '');
    case 'warn': return answer(0, 'out', arg);
    case 'fail': return answer(Number(rest[0]), 'partial', rest.slice(1).join(' '));
    case 'big': return answer(0, 'é'.repeat(Number(arg)), '');
    case 'sleep': {
      const ms = Number(arg);
      if (ms > timeoutMs) return setTimeout(() => answer(-1, 'started', '', 'timeout'), timeoutMs);
      return setTimeout(() => answer(0, `slept ${ms}`, ''), ms);
    }
    case 'hang': return undefined;
    case 'crash':
      process.stderr.write('boom\n');
      return process.exit(5);
    case 'garbage': return process.stdout.write('XYZ\n');
    case 'extra':
      answer(0, 'first', '');
      return process.stdout.write('stray\n');
    default: return answer(99, '', `unknown payload ${payload}`);
  }
}

function pump() {
  for (;;) {
    if (!pending) {
      const nl = buf.indexOf(0x0a);
      if (nl < 0) return;
      const m = buf.toString('utf8', 0, nl).match(/^REQ (\d+) (\d+)$/);
      if (!m) {
        process.stderr.write('protocol error\n');
        process.exit(3);
      }
      pending = { bytes: Number(m[1]), timeoutMs: Number(m[2]) };
      buf = buf.subarray(nl + 1);
    }
    if (buf.length < pending.bytes) return;
    const payload = buf.toString('utf8', 0, pending.bytes);
    buf = buf.subarray(pending.bytes);
    const { timeoutMs } = pending;
    pending = null;
    run(payload, timeoutMs);
  }
}

process.stdin.on('data', (data) => {
  buf = Buffer.concat([buf, data]);
  pump();
});
process.stdin.on('end', () => process.exit(0));

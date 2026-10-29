#!/usr/bin/env node

import fs from 'node:fs';
import http from 'node:http';
import https from 'node:https';
import os from 'node:os';
import { spawnSync } from 'node:child_process';
import { performance } from 'node:perf_hooks';

const profiles = {
  smoke: [{ vus: 2, seconds: 10 }],
  load: [{ vus: 100, seconds: 300 }],
  stress: [50, 100, 250, 500, 750, 1000].map((vus) => ({ vus, seconds: 120 })),
  soak: [{ vus: 50, seconds: 7200 }],
};

function argumentsFrom(argv) {
  const result = {};
  for (let index = 0; index < argv.length; index += 1) {
    const key = argv[index];
    if (!key.startsWith('--')) continue;
    const value = argv[index + 1]?.startsWith('--') ? true : argv[++index] ?? true;
    result[key.slice(2)] = value;
  }
  return result;
}

function percentile(values, rank) {
  if (values.length === 0) return 0;
  const sorted = [...values].sort((a, b) => a - b);
  return Number(sorted[Math.max(0, Math.ceil((rank / 100) * sorted.length) - 1)].toFixed(3));
}

function createStats() {
  return { requests: 0, succeeded: 0, failed: 0, durations: [], statusCodes: new Map(), errors: new Map() };
}

function record(stats, sample) {
  stats.requests += 1;
  stats[sample.ok ? 'succeeded' : 'failed'] += 1;
  stats.statusCodes.set(sample.status, (stats.statusCodes.get(sample.status) || 0) + 1);
  if (!sample.ok) {
    const error = sample.error || `HTTP ${sample.status}`;
    stats.errors.set(error, (stats.errors.get(error) || 0) + 1);
  }
  const limit = 100000;
  if (stats.durations.length < limit) stats.durations.push(sample.durationMs);
  else {
    const candidate = Math.floor(Math.random() * stats.requests);
    if (candidate < limit) stats.durations[candidate] = sample.durationMs;
  }
}

function summarize(stats, elapsedMs) {
  const durations = stats.durations;
  return {
    requests: stats.requests,
    succeeded: stats.succeeded,
    failed: stats.failed,
    error_rate: stats.requests ? Number((stats.failed / stats.requests).toFixed(6)) : 1,
    throughput_rps: Number((stats.requests / Math.max(0.001, elapsedMs / 1000)).toFixed(2)),
    latency_ms: {
      min: durations.length ? Number(Math.min(...durations).toFixed(3)) : 0,
      mean: durations.length ? Number((durations.reduce((a, b) => a + b, 0) / durations.length).toFixed(3)) : 0,
      p50: percentile(durations, 50),
      p95: percentile(durations, 95),
      p99: percentile(durations, 99),
      max: durations.length ? Number(Math.max(...durations).toFixed(3)) : 0,
    },
    latency_sample_count: durations.length,
    latency_sampling: stats.requests > durations.length ? 'reservoir_sampling_max_100000' : 'all_requests',
    status_codes: Object.fromEntries([...stats.statusCodes.entries()].sort(([left], [right]) => left - right)),
    errors: Object.fromEntries([...stats.errors.entries()].sort((left, right) => right[1] - left[1]).slice(0, 20)),
  };
}

function request(url, options = {}) {
  return new Promise((resolve, reject) => {
    const target = new URL(url);
    const transport = target.protocol === 'https:' ? https : http;
    const timings = {};
    const started = performance.now();
    const req = transport.request(target, { method: options.method || 'GET', headers: options.headers || {}, timeout: options.timeout || 30000, agent: options.agent }, (res) => {
      timings.ttfbMs = performance.now() - started;
      const chunks = [];
      res.on('data', (chunk) => chunks.push(chunk));
      res.on('end', () => resolve({
        status: res.statusCode || 0,
        headers: res.headers,
        body: Buffer.concat(chunks).toString('utf8'),
        bytes: chunks.reduce((sum, chunk) => sum + chunk.length, 0),
        durationMs: performance.now() - started,
        timings,
      }));
    });
    req.on('socket', (socket) => {
      if (socket.connecting) {
        socket.once('lookup', () => { timings.dnsMs = performance.now() - started; });
        socket.once('connect', () => { timings.tcpMs = performance.now() - started; });
        socket.once('secureConnect', () => { timings.tlsMs = performance.now() - started; });
      }
    });
    req.on('timeout', () => req.destroy(new Error('request timeout')));
    req.on('error', reject);
    if (options.body) req.write(options.body);
    req.end();
  });
}

function cookiesFrom(headers, current = {}) {
  const raw = headers['set-cookie'] || [];
  for (const cookie of raw) {
    const [pair] = cookie.split(';');
    const separator = pair.indexOf('=');
    if (separator > 0) current[pair.slice(0, separator)] = pair.slice(separator + 1);
  }
  return current;
}

function cookieHeader(jar) {
  return Object.entries(jar).map(([name, value]) => `${name}=${value}`).join('; ');
}

async function authenticate(baseUrl, email, password) {
  const jar = {};
  const login = await request(new URL('/login', baseUrl));
  cookiesFrom(login.headers, jar);
  const token = login.body.match(/name=["']_token["'][^>]*value=["']([^"']+)/)?.[1];
  if (!token) throw new Error('Token CSRF login tidak ditemukan. Pastikan target adalah aplikasi LogistikKu.');
  const body = new URLSearchParams({ _token: token, email, password }).toString();
  const response = await request(new URL('/login', baseUrl), {
    method: 'POST',
    body,
    headers: { 'content-type': 'application/x-www-form-urlencoded', 'content-length': Buffer.byteLength(body), cookie: cookieHeader(jar) },
  });
  cookiesFrom(response.headers, jar);
  if (![302, 303].includes(response.status)) throw new Error(`Login benchmark gagal dengan HTTP ${response.status}.`);
  return cookieHeader(jar);
}

function cpuSnapshot() {
  return os.cpus().reduce((total, cpu) => {
    total.idle += cpu.times.idle;
    total.all += Object.values(cpu.times).reduce((sum, value) => sum + value, 0);
    return total;
  }, { idle: 0, all: 0 });
}

function processRssMb(pid) {
  if (!pid) return null;
  try {
    if (process.platform === 'win32') {
      const result = spawnSync('powershell', ['-NoProfile', '-Command', `(Get-Process -Id ${Number(pid)} -ErrorAction Stop).WorkingSet64`], { encoding: 'utf8', timeout: 5000 });
      const bytes = Number(result.stdout.trim());
      return Number((bytes / 1048576).toFixed(2));
    }
    const status = fs.readFileSync(`/proc/${Number(pid)}/status`, 'utf8');
    const kb = Number(status.match(/^VmRSS:\s+(\d+)/m)?.[1]);
    return Number((kb / 1024).toFixed(2));
  } catch {
    return null;
  }
}

async function runStage(baseUrl, paths, cookie, stage, timeout, serverPid) {
  const stats = createStats();
  const resources = [];
  let cursor = 0;
  const stopAt = performance.now() + stage.seconds * 1000;
  const cpuStart = cpuSnapshot();
  const sampleResource = () => resources.push({
    at: new Date().toISOString(),
    system_used_memory_mb: Number(((os.totalmem() - os.freemem()) / 1048576).toFixed(2)),
    server_rss_mb: processRssMb(serverPid),
  });
  sampleResource();
  const sampleResources = setInterval(sampleResource, 5000);
  const started = performance.now();
  await Promise.all(Array.from({ length: stage.vus }, async () => {
    while (performance.now() < stopAt) {
      const path = paths[cursor++ % paths.length];
      const requestStarted = performance.now();
      try {
        const response = await request(new URL(path, baseUrl), { headers: cookie ? { cookie } : {}, timeout });
        record(stats, { status: response.status, ok: response.status >= 200 && response.status < 400, durationMs: performance.now() - requestStarted });
      } catch (error) {
        record(stats, { status: 0, ok: false, durationMs: performance.now() - requestStarted, error: error.message });
      }
    }
  }));
  clearInterval(sampleResources);
  sampleResource();
  const elapsedMs = performance.now() - started;
  const cpuEnd = cpuSnapshot();
  const cpuBusyPct = 100 * (1 - (cpuEnd.idle - cpuStart.idle) / Math.max(1, cpuEnd.all - cpuStart.all));
  return {
    vus: stage.vus,
    duration_seconds: Number((elapsedMs / 1000).toFixed(3)),
    ...summarize(stats, elapsedMs),
    resources: {
      system_cpu_busy_pct: Number(cpuBusyPct.toFixed(2)),
      system_memory_peak_mb: resources.length ? Math.max(...resources.map((row) => row.system_used_memory_mb)) : null,
      server_rss_peak_mb: resources.some((row) => row.server_rss_mb !== null) ? Math.max(...resources.map((row) => row.server_rss_mb || 0)) : null,
      server_rss_growth_mb: resources.filter((row) => row.server_rss_mb !== null).length > 1 ? Number((resources.filter((row) => row.server_rss_mb !== null).at(-1).server_rss_mb - resources.find((row) => row.server_rss_mb !== null).server_rss_mb).toFixed(2)) : null,
      samples: resources,
    },
  };
}

async function main() {
  const args = argumentsFrom(process.argv.slice(2));
  const profile = String(args.profile || 'smoke');
  if (!profiles[profile]) throw new Error(`Profil tidak dikenal: ${profile}. Gunakan smoke, load, stress, atau soak.`);
  if (profile !== 'smoke' && process.env.BENCHMARK_ALLOW_LOAD !== 'true') {
    throw new Error('Set BENCHMARK_ALLOW_LOAD=true setelah pemilik target menyetujui load test.');
  }
  const baseUrl = new URL(String(args['base-url'] || process.env.BENCHMARK_BASE_URL || 'http://127.0.0.1:8000'));
  const paths = String(args.paths || '/up,/login').split(',').map((path) => path.trim()).filter(Boolean);
  const stages = args.vus || args.seconds
    ? [{ vus: Number(args.vus || 2), seconds: Number(args.seconds || 10) }]
    : profiles[profile];
  const email = args.email || process.env.BENCHMARK_EMAIL;
  const password = args.password || process.env.BENCHMARK_PASSWORD;
  const cookie = email && password ? await authenticate(baseUrl, String(email), String(password)) : '';
  const probeStarted = performance.now();
  const probe = await request(new URL(paths[0], baseUrl), { headers: cookie ? { cookie } : {} });
  const networkProbe = {
    url: new URL(paths[0], baseUrl).toString(), protocol: baseUrl.protocol,
    status: probe.status, bytes: probe.bytes, total_ms: Number((performance.now() - probeStarted).toFixed(3)),
    dns_ms: probe.timings.dnsMs ? Number(probe.timings.dnsMs.toFixed(3)) : 0,
    tcp_ms: probe.timings.tcpMs ? Number(probe.timings.tcpMs.toFixed(3)) : 0,
    tls_ms: probe.timings.tlsMs ? Number(probe.timings.tlsMs.toFixed(3)) : null,
    ttfb_ms: Number(probe.timings.ttfbMs.toFixed(3)),
  };
  const results = [];
  for (const stage of stages) results.push(await runStage(baseUrl, paths, cookie, stage, Number(args.timeout || 30000), args['server-pid']));
  const thresholds = { p95_ms: Number(args['max-p95'] || 1000), error_rate: Number(args['max-error-rate'] || 0.01) };
  const violations = results.flatMap((result) => [
    ...(result.latency_ms.p95 > thresholds.p95_ms ? [`${result.vus} VU: p95 ${result.latency_ms.p95} ms > ${thresholds.p95_ms} ms`] : []),
    ...(result.error_rate > thresholds.error_rate ? [`${result.vus} VU: error rate ${result.error_rate} > ${thresholds.error_rate}`] : []),
  ]);
  const output = { schema_version: 1, generated_at: new Date().toISOString(), profile, target: baseUrl.origin, paths, network_probe: networkProbe, thresholds, stages: results, passed: violations.length === 0, violations };
  const json = JSON.stringify(output, null, 2);
  if (args.output) fs.writeFileSync(String(args.output), `${json}\n`, 'utf8');
  process.stdout.write(`${json}\n`);
  if (violations.length) process.exitCode = 2;
}

main().catch((error) => { console.error(`Benchmark gagal: ${error.message}`); process.exitCode = 1; });

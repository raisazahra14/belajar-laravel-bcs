#!/usr/bin/env node

import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import { spawn, spawnSync } from 'node:child_process';

function argsFrom(argv) {
  const result = {};
  for (let index = 0; index < argv.length; index += 1) {
    if (!argv[index].startsWith('--')) continue;
    result[argv[index].slice(2)] = argv[index + 1]?.startsWith('--') ? true : argv[++index] ?? true;
  }
  return result;
}

function lighthouseExecutable() {
  const cli = path.resolve('node_modules', 'lighthouse', 'cli', 'index.js');
  if (fs.existsSync(cli)) return cli;
  throw new Error('Lighthouse belum terpasang. Jalankan npm ci setelah dependency benchmark ditambahkan.');
}

function chromeExecutable() {
  const candidates = [
    process.env.CHROME_PATH,
    'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe',
    'C:\\Program Files (x86)\\Microsoft\\Edge\\Application\\msedge.exe',
    'C:\\Program Files\\Microsoft\\Edge\\Application\\msedge.exe',
    '/usr/bin/google-chrome', '/usr/bin/chromium', '/usr/bin/chromium-browser',
  ].filter(Boolean);
  return candidates.find((candidate) => fs.existsSync(candidate));
}

function request(url, options = {}) {
  return fetch(url, { redirect: 'manual', ...options });
}

async function login(baseUrl, email, password) {
  const first = await request(new URL('/login', baseUrl));
  const rawCookies = first.headers.getSetCookie?.() || [first.headers.get('set-cookie')].filter(Boolean);
  const jar = Object.fromEntries(rawCookies.map((cookie) => cookie.split(';')[0].split(/=(.*)/s).slice(0, 2)));
  const html = await first.text();
  const token = html.match(/name=["']_token["'][^>]*value=["']([^"']+)/)?.[1];
  if (!token) throw new Error('Token CSRF login tidak ditemukan.');
  const body = new URLSearchParams({ _token: token, email, password });
  const response = await request(new URL('/login', baseUrl), { method: 'POST', headers: { cookie: Object.entries(jar).map(([key, value]) => `${key}=${value}`).join('; '), 'content-type': 'application/x-www-form-urlencoded' }, body });
  for (const cookie of response.headers.getSetCookie?.() || [response.headers.get('set-cookie')].filter(Boolean)) {
    const [key, value] = cookie.split(';')[0].split(/=(.*)/s).slice(0, 2);
    jar[key] = value;
  }
  if (![302, 303].includes(response.status)) throw new Error(`Login gagal dengan HTTP ${response.status}.`);
  return Object.entries(jar).map(([key, value]) => `${key}=${value}`).join('; ');
}

function auditValue(report, id) {
  const audit = report.audits[id];
  return audit?.numericValue === undefined ? null : Number(audit.numericValue.toFixed(3));
}

function runLighthouse(commandArgs, timeout) {
  return new Promise((resolve, reject) => {
    const child = spawn(process.execPath, commandArgs, { env: process.env, windowsHide: true });
    let stdout = '';
    let stderr = '';
    let timedOut = false;
    child.stdout.on('data', (chunk) => { stdout += chunk; });
    child.stderr.on('data', (chunk) => { stderr += chunk; });
    child.on('error', reject);
    const timer = setTimeout(() => {
      timedOut = true;
      if (process.platform === 'win32') spawnSync('taskkill', ['/PID', String(child.pid), '/T', '/F'], { windowsHide: true });
      else child.kill('SIGKILL');
    }, timeout);
    child.on('close', (status) => {
      clearTimeout(timer);
      if (timedOut) reject(new Error(`Lighthouse melewati timeout ${timeout} ms`));
      else if (status !== 0) reject(new Error(String(stderr || stdout || `exit ${status}`).trim()));
      else resolve();
    });
  });
}

async function main() {
  const args = argsFrom(process.argv.slice(2));
  const baseUrl = new URL(String(args['base-url'] || process.env.BENCHMARK_BASE_URL || 'http://127.0.0.1:8000'));
  const pages = String(args.paths || '/login').split(',').map((entry) => entry.trim()).filter(Boolean);
  const runs = Number(args.runs || 3);
  const chrome = chromeExecutable();
  if (!chrome) throw new Error('Chrome/Chromium/Edge tidak ditemukan. Set CHROME_PATH ke executable browser.');
  process.env.CHROME_PATH = chrome;
  const executable = lighthouseExecutable();
  const email = args.email || process.env.BENCHMARK_EMAIL;
  const password = args.password || process.env.BENCHMARK_PASSWORD;
  const cookie = email && password ? await login(baseUrl, String(email), String(password)) : '';
  const temp = fs.mkdtempSync(path.join(os.tmpdir(), 'logistikku-lh-'));
  const results = [];
  try {
    for (const page of pages) {
      const pageRuns = [];
      for (let run = 1; run <= runs; run += 1) {
        const reportPath = path.join(temp, `report-${results.length}-${run}.json`);
        const extraHeaders = cookie ? JSON.stringify({ Cookie: cookie }) : null;
        const commandArgs = [executable, new URL(page, baseUrl).toString(), '--quiet', '--output=json', `--output-path=${reportPath}`, '--only-categories=performance', '--preset=desktop', '--max-wait-for-load=60000', '--chrome-flags=--headless=new --no-sandbox --disable-gpu --disable-background-timer-throttling --disable-renderer-backgrounding --disable-backgrounding-occluded-windows'];
        if (extraHeaders) commandArgs.push(`--extra-headers=${extraHeaders}`);
        try {
          await runLighthouse(commandArgs, Number(args.timeout || 180000));
        } catch (error) {
          throw new Error(`Lighthouse gagal pada ${page}: ${error.message}`);
        }
        const report = JSON.parse(fs.readFileSync(reportPath, 'utf8'));
        pageRuns.push({
          run,
          performance_score: Number(((report.categories.performance.score || 0) * 100).toFixed(1)),
          fcp_ms: auditValue(report, 'first-contentful-paint'),
          lcp_ms: auditValue(report, 'largest-contentful-paint'),
          cls: auditValue(report, 'cumulative-layout-shift'),
          tbt_ms: auditValue(report, 'total-blocking-time'),
          speed_index_ms: auditValue(report, 'speed-index'),
          server_response_ms: auditValue(report, 'server-response-time'),
        });
      }
      const median = (key) => [...pageRuns].sort((a, b) => a[key] - b[key])[Math.floor(pageRuns.length / 2)][key];
      results.push({ path: page, url: new URL(page, baseUrl).toString(), runs: pageRuns, median: Object.fromEntries(['performance_score', 'fcp_ms', 'lcp_ms', 'cls', 'tbt_ms', 'speed_index_ms', 'server_response_ms'].map((key) => [key, median(key)])) });
    }
  } finally {
    fs.rmSync(temp, { recursive: true, force: true });
  }
  const thresholds = { performance_score: Number(args['min-score'] || 80), lcp_ms: Number(args['max-lcp'] || 2500), cls: Number(args['max-cls'] || 0.1), tbt_ms: Number(args['max-tbt'] || 200) };
  const violations = results.flatMap((result) => [
    ...(result.median.performance_score < thresholds.performance_score ? [`${result.path}: performance score ${result.median.performance_score}`] : []),
    ...(result.median.lcp_ms > thresholds.lcp_ms ? [`${result.path}: LCP ${result.median.lcp_ms} ms`] : []),
    ...(result.median.cls > thresholds.cls ? [`${result.path}: CLS ${result.median.cls}`] : []),
    ...(result.median.tbt_ms > thresholds.tbt_ms ? [`${result.path}: TBT ${result.median.tbt_ms} ms`] : []),
  ]);
  const output = { schema_version: 1, generated_at: new Date().toISOString(), target: baseUrl.origin, browser: chrome, note: 'Lighthouse menghasilkan metrik lab. INP lapangan memerlukan RUM/CrUX dan tidak boleh disamakan dengan TBT.', thresholds, pages: results, passed: violations.length === 0, violations };
  const json = JSON.stringify(output, null, 2);
  if (args.output) fs.writeFileSync(String(args.output), `${json}\n`, 'utf8');
  process.stdout.write(`${json}\n`);
  if (violations.length) process.exitCode = 2;
}

main().catch((error) => { console.error(`Benchmark gagal: ${error.message}`); process.exitCode = 1; });

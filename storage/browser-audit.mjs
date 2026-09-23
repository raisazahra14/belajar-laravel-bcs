import { writeFileSync } from 'node:fs';

const port = Number(process.argv[2]);
const baseUrl = process.argv[3];
const browserName = process.argv[4];

const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms));
const version = await fetch(`http://127.0.0.1:${port}/json/version`).then((response) => response.json());
const isFirefox = version.Browser.startsWith('Firefox/');
const targets = await fetch(`http://127.0.0.1:${port}/json/list`).then((response) => response.json());
const pageTarget = targets.find((target) => target.type === 'page');
if (!pageTarget) throw new Error('No debuggable page target');
const socket = new WebSocket(isFirefox ? version.webSocketDebuggerUrl : pageTarget.webSocketDebuggerUrl);
await new Promise((resolve, reject) => {
  socket.addEventListener('open', resolve, { once: true });
  socket.addEventListener('error', reject, { once: true });
});

let sequence = 0;
let sessionId = null;
const pending = new Map();
const events = new Map();
const consoleErrors = [];
const failedRequests = [];

socket.addEventListener('message', (message) => {
  const payload = JSON.parse(message.data);
  if (payload.id) {
    const waiter = pending.get(payload.id);
    if (!waiter) return;
    pending.delete(payload.id);
    if (payload.error) waiter.reject(new Error(payload.error.message));
    else waiter.resolve(payload.result);
    return;
  }
  if (payload.method === 'Runtime.exceptionThrown') {
    consoleErrors.push(payload.params.exceptionDetails.text);
  }
  if (payload.method === 'Runtime.consoleAPICalled' && payload.params.type === 'error') {
    consoleErrors.push(payload.params.args.map((arg) => arg.value || arg.description).join(' '));
  }
  if (payload.method === 'Network.loadingFailed' && !payload.params.canceled) {
    failedRequests.push(payload.params.errorText);
  }
  const listeners = events.get(payload.method) || [];
  listeners.splice(0).forEach((resolve) => resolve(payload.params));
});

function command(method, params = {}, root = false) {
  const id = ++sequence;
  const payload = { id, method, params };
  if (sessionId && !root) payload.sessionId = sessionId;
  socket.send(JSON.stringify(payload));
  return new Promise((resolve, reject) => pending.set(id, { resolve, reject }));
}

function once(method) {
  return new Promise((resolve) => {
    if (!events.has(method)) events.set(method, []);
    events.get(method).push(resolve);
  });
}

async function evaluate(expression) {
  const response = await command('Runtime.evaluate', {
    expression,
    returnByValue: true,
    awaitPromise: true,
  });
  if (response.exceptionDetails) throw new Error(response.exceptionDetails.text);
  return response.result.value;
}

async function navigate(path) {
  const loaded = once('Page.loadEventFired');
  await command('Page.navigate', { url: baseUrl + path });
  await loaded;
  await sleep(250);
}

async function waitFor(expression, timeout = 8000) {
  const started = Date.now();
  while (Date.now() - started < timeout) {
    if (await evaluate(expression)) return;
    await sleep(100);
  }
  const diagnostic = await evaluate(`({url: location.href, text: document.body?.innerText?.slice(0, 1200)})`);
  throw new Error(`Timeout waiting for: ${expression}\n${JSON.stringify(diagnostic)}`);
}

function assert(condition, message) {
  if (!condition) throw new Error(message);
}

async function submit(expression) {
  await evaluate(expression);
  await waitFor(`document.readyState === 'complete'`);
  await sleep(350);
}

if (isFirefox) {
  const attached = await command('Target.attachToTarget', { targetId: pageTarget.id, flatten: true }, true);
  sessionId = attached.sessionId;
}

await command('Page.enable');
await command('Runtime.enable');
await command('Network.enable');
await command('Network.clearBrowserCookies');

const checks = [];
const check = async (name, action) => {
  await action();
  checks.push(name);
};

await check('login', async () => {
  await navigate('/login');
  const loginReady = await evaluate(`Boolean(document.querySelector('input[name=email]') && document.querySelector('input[name=password]'))`);
  assert(loginReady, 'Login form not rendered');
  await submit(`(() => { const form=document.querySelector('form'); form.email.value='admin@logistikku.test'; form.password.value='password'; form.requestSubmit(); return true; })()`);
  await waitFor(`location.pathname === '/barang'`);
});

await check('dashboard-chart-notifications', async () => {
  const dashboard = await evaluate(`({chart: Boolean(window.Chart && document.querySelector('#stock-activity-chart')), notification: Boolean(document.querySelector('#app-notifications')), heading: document.querySelector('h1')?.textContent || ''})`);
  assert(dashboard.chart, 'Dashboard chart runtime unavailable');
  assert(dashboard.notification, 'Notification UI unavailable');
});

await check('responsive-desktop-tablet-mobile', async () => {
  for (const [width, height] of [[1440, 900], [834, 1112], [390, 844]]) {
    await command('Emulation.setDeviceMetricsOverride', { width, height, deviceScaleFactor: 1, mobile: width === 390 });
    await sleep(200);
    const layout = await evaluate(`({overflow: document.documentElement.scrollWidth > document.documentElement.clientWidth + 1, main: Boolean(document.querySelector('#main-content')), stockAction: Boolean(document.querySelector('.stock-management-action'))})`);
    assert(!layout.overflow, `Page overflow at ${width}px`);
    assert(layout.main && layout.stockAction, `Primary content/action missing at ${width}px`);
  }
  await command('Emulation.clearDeviceMetricsOverride');
});

await check('pagination-search', async () => {
  const pagination = await evaluate(`Boolean(document.querySelector('[data-inventory-pagination] a'))`);
  assert(pagination, 'Pagination not rendered');
  await evaluate(`(() => { const input=document.querySelector('#search'); input.value='Audit Browser 01'; input.dispatchEvent(new Event('input',{bubbles:true})); return true; })()`);
  await waitFor(`location.search.includes('Audit+Browser+01') || location.search.includes('Audit%20Browser%2001')`);
  await waitFor(`document.querySelector('#inventory-filter-feedback')?.textContent.includes('berhasil')`);
  const filtered = await evaluate(`document.querySelector('#inventory-results-content')?.textContent.includes('Audit Browser 01')`);
  assert(filtered, 'AJAX search result missing');
});

let itemId;
await check('crud-create-update', async () => {
  await navigate('/barang/create');
  await submit(`(() => { const f=document.querySelector('form[action="/barang"]'); f.nama_barang.value='Browser CRUD'; f.kategori.value='ATK'; f.satuan.value='Unit'; f.lokasi.value='Rak Browser'; f.stok.value='10'; f.daily_usage_estimate.value='2'; f.lead_time_days.value='3'; f.requestSubmit(); return true; })()`);
  await waitFor(`location.pathname === '/barang'`);
  await navigate('/barang?search=Browser%20CRUD');
  itemId = await evaluate(`(() => { const row=[...document.querySelectorAll('.inventory-table tbody tr')].find((item) => item.textContent.includes('Browser CRUD')); const href=row?.querySelector('a[href^="/barang/"]')?.getAttribute('href'); return href?.split('/').pop(); })()`);
  assert(itemId, 'Created item not found');
  await navigate(`/barang/${itemId}/edit`);
  await submit(`(() => { const f=document.querySelector('form[action="/barang/${itemId}"]'); f.nama_barang.value='Browser CRUD Updated'; f.requestSubmit(); return true; })()`);
  await waitFor(`location.pathname === '/barang'`);
  assert(await evaluate(`document.body.textContent.includes('Data barang berhasil diperbarui')`), 'Update feedback missing');
  await navigate(`/barang/${itemId}`);
  assert(await evaluate(`document.body.textContent.includes('Browser CRUD Updated')`), 'Updated item missing');
});

await check('stock-in-out-history', async () => {
  await navigate(`/barang/${itemId}/stok`);
  await submit(`(() => { const f=document.querySelector('form[action="/barang/${itemId}/stok"]'); f.jenis.value='masuk'; f.jumlah.value='5'; f.keterangan.value='Audit masuk'; f.requestSubmit(); return true; })()`);
  await waitFor(`location.pathname === '/barang/${itemId}'`);
  await navigate(`/barang/${itemId}/stok`);
  await submit(`(() => { const f=document.querySelector('form[action="/barang/${itemId}/stok"]'); f.jenis.value='keluar'; f.jumlah.value='3'; f.keterangan.value='Audit keluar'; f.requestSubmit(); return true; })()`);
  await waitFor(`location.pathname === '/barang/${itemId}'`);
  await navigate(`/barang/${itemId}/riwayat-stok`);
  const history = await evaluate(`({in: document.body.textContent.includes('Audit masuk'), out: document.body.textContent.includes('Audit keluar'), chart: document.querySelector('#stock-history-chart')?.dataset.chartReady === 'true'})`);
  assert(history.in && history.out && history.chart, 'Stock history/chart incomplete');
});

await check('prediction-enqueue-polling', async () => {
  await navigate('/prediksi-stok');
  await submit(`(() => { const f=document.querySelector('form[action$="analyze-all"]'); f.requestSubmit(); return true; })()`);
  await waitFor(`location.pathname === '/prediksi-stok'`);
  assert(await evaluate(`document.body.textContent.includes('Proses Prediksi Aktif')`), 'Prediction process status missing');
  await evaluate(`document.dispatchEvent(new CustomEvent('app:poll'))`);
  await sleep(500);
  assert(await evaluate(`Boolean(document.querySelector('#active-prediction-processes'))`), 'Prediction polling panel missing');
});

await check('modal', async () => {
  await navigate('/barang?import=1');
  await waitFor(`document.querySelector('#importBarangModal')?.classList.contains('show')`);
});

await check('ocr-upload-polling', async () => {
  await navigate('/verifications');
  const input = await command('DOM.getDocument');
  const node = await command('DOM.querySelector', { nodeId: input.root.nodeId, selector: '#document' });
  await command('DOM.setFileInputFiles', { nodeId: node.nodeId, files: [process.argv[5]] });
  await submit(`(() => { document.querySelector('form[action$="/verifications"]').requestSubmit(); return true; })()`);
  await waitFor(`location.pathname.includes('/processing')`);
  const polling = await evaluate(`({root: Boolean(document.querySelector('#ocr-process')), statusUrl: document.querySelector('#ocr-process')?.dataset.statusUrl || '', label: document.querySelector('#ocr-status-label')?.textContent || ''})`);
  assert(polling.root && polling.statusUrl && polling.label, 'OCR processing/polling UI missing');
});

await check('logout', async () => {
  await submit(`(() => { document.querySelector('form[action="/logout"]').requestSubmit(); return true; })()`);
  await waitFor(`location.pathname === '/login'`);
});

const result = {
  browser: browserName,
  product: version.Browser,
  userAgent: version['User-Agent'],
  checks,
  consoleErrors,
  failedRequests,
};
if (process.argv[6]) writeFileSync(process.argv[6], JSON.stringify(result, null, 2));
console.log(JSON.stringify(result, null, 2));
await sleep(500);
socket.close();

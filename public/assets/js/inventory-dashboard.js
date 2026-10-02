(function () {
  'use strict';

  function initializeStockActivity() {
    const root = document.getElementById('stock-activity-dashboard');
    const source = document.getElementById('stock-activity-data');
    const canvas = document.getElementById('stock-activity-chart');
    if (!root || !source || !canvas || root.dataset.initialized === 'true') return;

    root.dataset.initialized = 'true';
    const formatNumber = new Intl.NumberFormat('id-ID');
    const wrap = document.getElementById('stock-chart-wrap');
    const empty = document.getElementById('stock-chart-empty');
    const feedback = document.getElementById('stock-chart-status');
    const totalIn = document.getElementById('stock-total-in');
    const totalOut = document.getElementById('stock-total-out');
    const table = document.getElementById('stock-activity-table');
    const buttons = Array.from(root.querySelectorAll('[data-stock-period]'));
    let payload;
    let chart = null;
    let request = null;

    try {
      payload = JSON.parse(source.textContent);
    } catch (error) {
      showFeedback('Data awal aktivitas stok tidak dapat dibaca.', true);
      return;
    }

    function showFeedback(message, isError) {
      feedback.textContent = message;
      feedback.classList.toggle('is-error', isError);
      feedback.hidden = false;
    }

    function setLoading(loading) {
      root.classList.toggle('is-loading', loading);
      root.setAttribute('aria-busy', String(loading));
    }

    function renderTable(data) {
      table.replaceChildren();
      data.dates.forEach(function (date, index) {
        const row = document.createElement('tr');
        const dateCell = document.createElement('td');
        const inCell = document.createElement('td');
        const outCell = document.createElement('td');
        dateCell.textContent = date;
        inCell.textContent = formatNumber.format(data.masuk[index]);
        outCell.textContent = formatNumber.format(data.keluar[index]);
        inCell.className = 'text-end';
        outCell.className = 'text-end';
        row.append(dateCell, inCell, outCell);
        table.appendChild(row);
      });
    }

    function render(data) {
      payload = data;
      wrap.hidden = !data.has_activity || typeof window.Chart === 'undefined';
      empty.hidden = data.has_activity;
      totalIn.textContent = formatNumber.format(data.totals.masuk);
      totalOut.textContent = formatNumber.format(data.totals.keluar);
      canvas.setAttribute('aria-label', 'Grafik batang aktivitas stok masuk dan keluar selama ' + data.period + ' hari');
      renderTable(data);

      if (chart) {
        chart.destroy();
        chart = null;
      }
      if (!data.has_activity) return;
      if (typeof window.Chart === 'undefined') {
        showFeedback('Grafik tidak tersedia. Data aktivitas tetap dapat dilihat dalam tabel.', true);
        return;
      }

      chart = new window.Chart(canvas, {
        type: 'bar',
        data: { labels: data.labels, datasets: [
          { label: 'Barang masuk', data: data.masuk, backgroundColor: '#23875b', borderRadius: 5, maxBarThickness: 28 },
          { label: 'Barang keluar', data: data.keluar, backgroundColor: '#e08a16', borderRadius: 5, maxBarThickness: 28 }
        ] },
        options: {
          responsive: true,
          maintainAspectRatio: false,
          animation: window.matchMedia('(prefers-reduced-motion: reduce)').matches ? false : undefined,
          interaction: { mode: 'index', intersect: false },
          plugins: { legend: { display: false }, tooltip: { callbacks: {
            title: function (items) { return data.dates[items[0].dataIndex]; },
            label: function (item) { return item.dataset.label + ': ' + formatNumber.format(item.raw); }
          } } },
          scales: { x: { grid: { display: false }, ticks: { maxRotation: 0, autoSkip: true, maxTicksLimit: data.period === 30 ? 10 : 7 } }, y: { beginAtZero: true, ticks: { precision: 0 } } }
        }
      });
    }

    buttons.forEach(function (button) {
      button.addEventListener('click', function () {
        const period = Number(button.dataset.stockPeriod);
        if (period === payload.period) return;
        if (request) request.abort();
        const controller = new AbortController();
        request = controller;
        setLoading(true);
        showFeedback('Memuat aktivitas stok ' + period + ' hari.', false);

        fetch(root.dataset.endpoint + '?period=' + period, { headers: { Accept: 'application/json' }, credentials: 'same-origin', signal: controller.signal })
          .then(function (response) { if (!response.ok) throw new Error('Data aktivitas tidak tersedia'); return response.json(); })
          .then(function (data) {
            render(data);
            buttons.forEach(function (item) {
              const active = Number(item.dataset.stockPeriod) === data.period;
              item.classList.toggle('is-active', active);
              item.setAttribute('aria-pressed', String(active));
            });
            showFeedback('Aktivitas stok ' + data.period + ' hari berhasil dimuat.', false);
          })
          .catch(function (error) {
            if (error.name !== 'AbortError') showFeedback('Aktivitas stok belum dapat dimuat. Silakan coba lagi.', true);
          })
          .finally(function () {
            if (request !== controller) return;
            request = null;
            setLoading(false);
          });
      });
    });

    window.addEventListener('pagehide', function () {
      if (request) request.abort();
      if (chart) chart.destroy();
    }, { once: true });

    render(payload);
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', initializeStockActivity, { once: true });
  else initializeStockActivity();
}());

(function () {
  'use strict';

  function initializeAnalyticsCharts() {
    const source = document.getElementById('analytics-chart-data');
    if (!source) return;

    const feedback = document.querySelector('.analytics-chart-feedback');
    let data;
    try {
      data = JSON.parse(source.textContent);
    } catch (error) {
      if (feedback) feedback.hidden = false;
      return;
    }

    if (typeof window.Chart === 'undefined') {
      document.querySelectorAll('.analytics-chart-wrap').forEach(function (element) { element.hidden = true; });
      if (feedback) feedback.hidden = false;
      return;
    }

    const formatNumber = new Intl.NumberFormat('id-ID');
    const formatMoney = new Intl.NumberFormat('id-ID', {
      style: 'currency',
      currency: 'IDR',
      minimumFractionDigits: 0,
      maximumFractionDigits: 2
    });
    const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    const charts = [];
    const baseOptions = {
      responsive: true,
      maintainAspectRatio: false,
      animation: reducedMotion ? false : undefined
    };

    const mutationCanvas = document.getElementById('analytics-mutation-chart');
    if (mutationCanvas && data.trend.has_activity) {
      charts.push(new window.Chart(mutationCanvas, {
        type: 'bar',
        data: {
          labels: data.trend.labels,
          datasets: [
            { label: 'Masuk', data: data.trend.masuk, backgroundColor: '#23875b', borderRadius: 5, maxBarThickness: 24 },
            { label: 'Keluar', data: data.trend.keluar, backgroundColor: '#e24a5a', borderRadius: 5, maxBarThickness: 24 }
          ]
        },
        options: Object.assign({}, baseOptions, {
          interaction: { mode: 'index', intersect: false },
          plugins: { legend: { position: 'bottom' }, tooltip: { callbacks: {
            title: function (items) { return data.trend.dates[items[0].dataIndex]; },
            label: function (item) { return item.dataset.label + ': ' + formatNumber.format(item.raw) + ' unit'; }
          } } },
          scales: {
            x: { grid: { display: false }, ticks: { maxRotation: 0, autoSkip: true, maxTicksLimit: 10 } },
            y: { beginAtZero: true, ticks: { precision: 0 } }
          }
        })
      }));
    }

    const compositionCanvas = document.getElementById('analytics-composition-chart');
    if (compositionCanvas && data.composition.has_items) {
      charts.push(new window.Chart(compositionCanvas, {
        type: 'doughnut',
        data: {
          labels: data.composition.labels,
          datasets: [{
            data: data.composition.item_counts,
            backgroundColor: ['#3f7fd6', '#e0a12f', '#d94b5c'],
            borderColor: '#ffffff',
            borderWidth: 3,
            hoverOffset: 5
          }]
        },
        options: Object.assign({}, baseOptions, {
          cutout: '62%',
          plugins: { legend: { position: 'bottom', labels: { boxWidth: 12 } }, tooltip: { callbacks: {
            label: function (item) {
              const units = data.composition.stock_units[item.dataIndex];
              return item.label + ': ' + formatNumber.format(item.raw) + ' barang / ' + formatNumber.format(units) + ' unit';
            }
          } } }
        })
      }));
    }

    const valuationCanvas = document.getElementById('analytics-valuation-chart');
    if (valuationCanvas && data.valuation.has_value) {
      const values = data.valuation.values.map(function (value) { return Number(value); });
      charts.push(new window.Chart(valuationCanvas, {
        type: 'bar',
        data: {
          labels: data.valuation.labels,
          datasets: [{ label: 'Nilai diketahui', data: values, backgroundColor: '#4d54bd', borderRadius: 5, maxBarThickness: 26 }]
        },
        options: Object.assign({}, baseOptions, {
          indexAxis: 'y',
          plugins: { legend: { display: false }, tooltip: { callbacks: {
            label: function (item) { return 'Nilai diketahui: ' + formatMoney.format(item.raw); }
          } } },
          scales: {
            x: { beginAtZero: true, ticks: { callback: function (value) { return formatMoney.format(value); } } },
            y: { grid: { display: false } }
          }
        })
      }));
    }

    window.addEventListener('pagehide', function () {
      charts.forEach(function (chart) { chart.destroy(); });
    }, { once: true });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initializeAnalyticsCharts, { once: true });
  } else {
    initializeAnalyticsCharts();
  }
}());

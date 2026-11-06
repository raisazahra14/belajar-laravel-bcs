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

    const proc = data.procurement || {};
    const procTrendCanvas = document.getElementById('analytics-procurement-trend-chart');
    if (procTrendCanvas && proc.monthly_trend && proc.monthly_trend.has_activity) {
      const procTrendChart = new window.Chart(procTrendCanvas, {
        type: 'bar',
        data: {
          labels: proc.monthly_trend.labels,
          datasets: [
            { label: 'Anggaran PO', data: proc.monthly_trend.po_spend, backgroundColor: '#2563eb', borderRadius: 5, maxBarThickness: 28 },
            { label: 'Realisasi Invoice (OCR)', data: proc.monthly_trend.invoiced_spend, backgroundColor: '#16a34a', borderRadius: 5, maxBarThickness: 28 }
          ]
        },
        options: Object.assign({}, baseOptions, {
          interaction: { mode: 'index', intersect: false },
          plugins: {
            legend: { position: 'bottom' },
            tooltip: {
              callbacks: {
                label: function (item) { return item.dataset.label + ': ' + formatMoney.format(item.raw); }
              }
            }
          },
          scales: {
            x: { grid: { display: false } },
            y: { beginAtZero: true, ticks: { callback: function (value) { return formatMoney.format(value); } } }
          }
        })
      });
      charts.push(procTrendChart);

      const modeButtons = document.querySelectorAll('[data-procurement-trend-mode]');
      modeButtons.forEach(function (btn) {
        btn.addEventListener('click', function () {
          const mode = this.getAttribute('data-procurement-trend-mode');
          const sourceTrend = mode === 'quarterly' ? proc.quarterly_trend : proc.monthly_trend;
          if (!sourceTrend) return;

          procTrendChart.data.labels = sourceTrend.labels || [];
          procTrendChart.data.datasets[0].data = sourceTrend.po_spend || [];
          procTrendChart.data.datasets[1].data = sourceTrend.invoiced_spend || [];
          procTrendChart.update();

          modeButtons.forEach(function (b) {
            const active = b.getAttribute('data-procurement-trend-mode') === mode;
            b.classList.toggle('btn-primary', active);
            b.classList.toggle('btn-outline-primary', !active);
          });
        });
      });
    }

    const budgetCanvas = document.getElementById('analytics-budget-realization-chart');
    if (budgetCanvas && proc.has_data && proc.budget_comparison) {
      charts.push(new window.Chart(budgetCanvas, {
        type: 'bar',
        data: {
          labels: proc.budget_comparison.summary_labels || [],
          datasets: [{
            label: 'Nilai (Rp)',
            data: proc.budget_comparison.summary_values || [],
            backgroundColor: ['#2563eb', '#0891b2', '#16a34a'],
            borderRadius: 6,
            maxBarThickness: 36
          }]
        },
        options: Object.assign({}, baseOptions, {
          plugins: {
            legend: { display: false },
            tooltip: {
              callbacks: {
                label: function (item) { return formatMoney.format(item.raw); }
              }
            }
          },
          scales: {
            x: { grid: { display: false } },
            y: { beginAtZero: true, ticks: { callback: function (value) { return formatMoney.format(value); } } }
          }
        })
      }));
    }

    const poWarehouseCanvas = document.getElementById('analytics-po-warehouse-chart');
    if (poWarehouseCanvas && proc.warehouse_distribution && proc.warehouse_distribution.has_data) {
      charts.push(new window.Chart(poWarehouseCanvas, {
        type: 'bar',
        data: {
          labels: proc.warehouse_distribution.labels || [],
          datasets: [{
            label: 'Total Anggaran PO',
            data: proc.warehouse_distribution.spend_values || [],
            backgroundColor: '#3b82f6',
            borderRadius: 5,
            maxBarThickness: 26
          }]
        },
        options: Object.assign({}, baseOptions, {
          indexAxis: 'y',
          plugins: {
            legend: { display: false },
            tooltip: {
              callbacks: {
                label: function (item) {
                  const poCount = proc.warehouse_distribution.po_counts[item.dataIndex] || 0;
                  return formatMoney.format(item.raw) + ' (' + poCount + ' PO)';
                }
              }
            }
          },
          scales: {
            x: { beginAtZero: true, ticks: { callback: function (value) { return formatMoney.format(value); } } },
            y: { grid: { display: false } }
          }
        })
      }));
    }

    const poCategoryCanvas = document.getElementById('analytics-po-category-chart');
    if (poCategoryCanvas && proc.category_distribution && proc.category_distribution.has_data) {
      charts.push(new window.Chart(poCategoryCanvas, {
        type: 'doughnut',
        data: {
          labels: proc.category_distribution.labels || [],
          datasets: [{
            data: proc.category_distribution.spend_values || [],
            backgroundColor: ['#2563eb', '#16a34a', '#d97706', '#dc2626', '#7c3aed', '#0891b2'],
            borderColor: '#ffffff',
            borderWidth: 2
          }]
        },
        options: Object.assign({}, baseOptions, {
          cutout: '58%',
          plugins: {
            legend: { position: 'bottom', labels: { boxWidth: 12 } },
            tooltip: {
              callbacks: {
                label: function (item) {
                  const units = proc.category_distribution.unit_values[item.dataIndex] || 0;
                  return item.label + ': ' + formatMoney.format(item.raw) + ' (' + formatNumber.format(units) + ' unit)';
                }
              }
            }
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

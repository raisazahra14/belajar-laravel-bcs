(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {
        var root = document.getElementById('stock-history-chart');
        if (!root || root.hidden || root.dataset.chartReady === 'true') return;
        var error = document.getElementById('stock-history-error');

        try {
            if (typeof Chart === 'undefined') throw new Error('Chart.js tidak tersedia');
            var payload = JSON.parse(root.dataset.chart || '{}');
            if (!Array.isArray(payload.labels) || !Array.isArray(payload.balances)) throw new Error('Data grafik tidak valid');
            root.dataset.chartReady = 'true';
            if (root.stockChart) root.stockChart.destroy();

            var colors = payload.point_types.map(function (type) {
                return type === 'masuk' ? '#17834f' : (type === 'keluar' ? '#d56d13' : '#6259ca');
            });
            var styles = payload.point_types.map(function (type) {
                return type === 'masuk' ? 'triangle' : (type === 'keluar' ? 'rectRot' : 'circle');
            });
            root.stockChart = new Chart(root.querySelector('canvas'), {
                type: 'line',
                data: {labels: payload.labels, datasets: [{label: 'Saldo stok', data: payload.balances, borderColor: '#6259ca', backgroundColor: 'rgba(98,89,202,.08)', fill: true, tension: 0.18, spanGaps: false, pointBackgroundColor: colors, pointBorderColor: colors, pointStyle: styles, pointRadius: 5, pointHoverRadius: 7}]},
                options: {responsive: true, maintainAspectRatio: false, animation: window.matchMedia('(prefers-reduced-motion: reduce)').matches ? false : {duration: 350}, interaction: {intersect: false, mode: 'index'}, plugins: {legend: {display: false}, tooltip: {callbacks: {label: function (context) { return context.raw === null ? 'Snapshot tidak tersedia' : 'Saldo: ' + context.raw; }}}}, scales: {y: {beginAtZero: true, ticks: {precision: 0}}, x: {grid: {display: false}, ticks: {maxRotation: 0, autoSkip: true, maxTicksLimit: 8}}}}
            });
        } catch (exception) {
            root.hidden = true;
            if (error) error.hidden = false;
        }
    });
}());

(function () {
    'use strict';

    function closePredictionDetails(exceptRow) {
        document.querySelectorAll('.prediction-detail-row:not([hidden])').forEach(function (row) {
            if (row === exceptRow) return;
            row.hidden = true;
            var button = document.querySelector('[aria-controls="' + row.id + '"]');
            if (button) button.setAttribute('aria-expanded', 'false');
        });
    }

    function initializeDetails() {
        document.querySelectorAll('.prediction-detail-toggle').forEach(function (button) {
            button.addEventListener('click', function () {
                var row = document.getElementById(button.getAttribute('aria-controls'));
                if (!row) return;
                var opening = row.hidden;
                closePredictionDetails(opening ? row : null);
                row.hidden = !opening;
                button.setAttribute('aria-expanded', String(opening));
                if (opening) row.querySelector('.prediction-detail-close').focus();
            });
        });
        document.querySelectorAll('.prediction-detail-close').forEach(function (button) {
            button.addEventListener('click', function () {
                var row = button.closest('.prediction-detail-row');
                var trigger = document.querySelector('[aria-controls="' + row.id + '"]');
                row.hidden = true;
                if (trigger) {
                    trigger.setAttribute('aria-expanded', 'false');
                    trigger.focus();
                }
            });
        });
        document.addEventListener('keydown', function (event) {
            if (event.key !== 'Escape') return;
            var row = document.querySelector('.prediction-detail-row:not([hidden])');
            if (row) row.querySelector('.prediction-detail-close').click();
        });
    }

    function processRow(item) {
        var row = document.createElement('tr');
        var itemCell = document.createElement('td');
        var name = document.createElement('strong');
        var code = document.createElement('small');
        name.textContent = item.barang_name;
        code.textContent = item.barang_code;
        itemCell.append(name, code);
        var statusCell = document.createElement('td');
        var badge = document.createElement('span');
        badge.className = 'badge badge-' + (item.status === 'failed' ? 'danger' : 'info');
        badge.textContent = item.status_label;
        statusCell.appendChild(badge);
        if (item.message) {
            var message = document.createElement('small');
            message.className = 'process-safe-error';
            message.textContent = item.message;
            statusCell.appendChild(message);
        }
        var timeCell = document.createElement('td');
        timeCell.textContent = item.updated_label || '—';
        row.append(itemCell, statusCell, timeCell);
        return row;
    }

    function renderProcesses(payload) {
        var processes = Array.isArray(payload.processes) ? payload.processes : [];
        var counts = payload.counts || {};
        var counters = document.getElementById('prediction-process-counters');
        var toggle = document.getElementById('prediction-process-toggle');
        var list = document.getElementById('prediction-process-list');
        counters.replaceChildren();
        if (!processes.length) {
            counters.textContent = 'Tidak ada proses aktif';
            toggle.hidden = true;
            list.hidden = true;
            toggle.setAttribute('aria-expanded', 'false');
        } else {
            [['waiting', 'Menunggu'], ['processing', 'Diproses'], ['failed', 'Gagal']].forEach(function (entry, index) {
                if (index) counters.append(document.createTextNode(' · '));
                var strong = document.createElement('strong');
                strong.textContent = counts[entry[0]] || 0;
                counters.append(strong, document.createTextNode(' ' + entry[1]));
            });
            toggle.hidden = false;
        }
        var compactRows = document.getElementById('prediction-process-rows');
        var allRows = document.getElementById('prediction-process-all-rows');
        compactRows.replaceChildren.apply(compactRows, processes.slice(0, 5).map(processRow));
        allRows.replaceChildren.apply(allRows, processes.map(processRow));
        var allTrigger = document.getElementById('prediction-process-all-trigger');
        allTrigger.hidden = processes.length <= 5;
        allTrigger.querySelector('span').textContent = processes.length;
    }

    function initializeProcesses() {
        var section = document.getElementById('active-prediction-processes');
        if (!section) return;
        var toggle = document.getElementById('prediction-process-toggle');
        var list = document.getElementById('prediction-process-list');
        var dialog = document.getElementById('prediction-process-dialog');
        toggle.addEventListener('click', function () {
            var opening = list.hidden;
            list.hidden = !opening;
            toggle.setAttribute('aria-expanded', String(opening));
        });
        document.getElementById('prediction-process-all-trigger').addEventListener('click', function () {
            dialog.showModal();
            dialog.querySelector('.dialog-close').focus();
        });
        dialog.querySelector('.dialog-close').addEventListener('click', function () { dialog.close(); });
        dialog.addEventListener('click', function (event) { if (event.target === dialog) dialog.close(); });
        document.addEventListener('app:poll', function () {
            if (section.dataset.polling === 'true') return;
            section.dataset.polling = 'true';
            fetch(section.dataset.endpoint, {headers: {'Accept': 'application/json'}, credentials: 'same-origin'})
                .then(function (response) {
                    if (!response.ok) throw new Error('Status prediksi tidak tersedia');
                    return response.json();
                })
                .then(renderProcesses)
                .catch(function () {})
                .finally(function () { section.dataset.polling = 'false'; });
        });
    }

    document.addEventListener('DOMContentLoaded', function () {
        initializeDetails();
        initializeProcesses();
    });
}());

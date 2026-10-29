(function () {
    'use strict';

    const modalElement = document.getElementById('qrPreviewModal');
    if (!modalElement || !window.bootstrap) return;

    const image = document.getElementById('qr-preview-image');
    const code = document.getElementById('qr-preview-code');
    const name = document.getElementById('qr-preview-name');
    const download = document.getElementById('qr-preview-download');
    const modal = window.bootstrap.Modal.getOrCreateInstance(modalElement);

    document.addEventListener('click', function (event) {
        const trigger = event.target.closest('[data-qr-preview]');
        if (!trigger) return;

        image.src = trigger.dataset.qrImage;
        image.alt = 'QR Code ' + trigger.dataset.qrCode;
        code.textContent = trigger.dataset.qrCode;
        name.textContent = trigger.dataset.qrName;
        download.href = trigger.dataset.qrDownload;
        download.setAttribute('download', 'qr-' + trigger.dataset.qrCode + '.svg');
        modal.show();
    });

    modalElement.addEventListener('hidden.bs.modal', function () {
        image.removeAttribute('src');
        image.alt = '';
        download.href = '#';
    });
}());

(function () {
    'use strict';

    const root = document.getElementById('inventory-scanner');
    if (!root) return;

    const video = document.getElementById('scanner-video');
    const viewport = document.getElementById('scanner-viewport');
    const placeholder = document.getElementById('scanner-placeholder');
    const support = document.getElementById('scanner-support');
    const startButton = document.getElementById('scanner-start');
    const stopButton = document.getElementById('scanner-stop');
    const form = document.getElementById('scanner-code-form');
    const input = document.getElementById('scanner-code');
    const status = document.getElementById('scanner-status');
    const result = document.getElementById('scanner-result');
    const scanAgain = document.getElementById('scanner-again');
    let stream = null;
    let detector = null;
    let frameId = null;
    let lookupController = null;
    let detecting = false;

    const cameraSupported = 'BarcodeDetector' in window && navigator.mediaDevices && navigator.mediaDevices.getUserMedia;
    support.textContent = cameraSupported ? 'Kamera didukung' : 'Gunakan scanner USB / input';
    support.classList.toggle('is-supported', Boolean(cameraSupported));
    startButton.disabled = !cameraSupported;

    function announce(message, type) {
        status.textContent = message;
        status.className = 'scanner-status' + (type ? ' is-' + type : '');
    }

    function stopCamera() {
        if (frameId !== null) window.cancelAnimationFrame(frameId);
        frameId = null;
        detecting = false;
        if (stream) stream.getTracks().forEach(function (track) { track.stop(); });
        stream = null;
        video.srcObject = null;
        viewport.classList.remove('is-active');
        placeholder.hidden = false;
        startButton.hidden = false;
        stopButton.hidden = true;
    }

    function renderItem(payload) {
        const item = payload.item;
        document.getElementById('scanner-result-name').textContent = item.name;
        document.getElementById('scanner-result-code').textContent = item.code;
        const stock = document.getElementById('scanner-result-stock');
        stock.textContent = Number(item.stock).toLocaleString('id-ID') + ' ' + item.unit;
        stock.className = 'badge ' + (item.is_low_stock ? 'badge-danger' : 'badge-success');
        document.getElementById('scanner-result-location').textContent = item.location || 'Belum ditentukan';
        document.getElementById('scanner-result-state').textContent = item.is_low_stock ? 'Stok menipis' : 'Stok aman';
        document.getElementById('scanner-detail-link').href = payload.urls.detail;
        const stockLink = document.getElementById('scanner-stock-link');
        if (stockLink) stockLink.href = payload.urls.stock;
        result.hidden = false;
        result.scrollIntoView({behavior: window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth', block: 'nearest'});
    }

    async function lookup(rawCode) {
        const code = String(rawCode || '').trim();
        if (!code) {
            announce('Masukkan atau scan kode barang terlebih dahulu.', 'error');
            input.focus();
            return;
        }
        input.value = code;
        result.hidden = true;
        announce('Mencari ' + code + '...', 'loading');
        if (lookupController) lookupController.abort();
        lookupController = new AbortController();
        try {
            const url = new URL(root.dataset.lookupUrl, window.location.origin);
            url.searchParams.set('code', code);
            const response = await fetch(url, {
                headers: {'Accept': 'application/json'},
                credentials: 'same-origin',
                signal: lookupController.signal
            });
            const payload = await response.json();
            if (!response.ok) throw new Error(payload.message || 'Barang tidak ditemukan.');
            stopCamera();
            renderItem(payload);
            announce('Barang ditemukan: ' + payload.item.name + '.', 'success');
        } catch (error) {
            if (error.name === 'AbortError') return;
            announce(error.message || 'Pencarian barang gagal. Coba kembali.', 'error');
            input.select();
        }
    }

    async function detectFrame() {
        if (!stream || detecting) return;
        if (video.readyState >= HTMLMediaElement.HAVE_CURRENT_DATA) {
            detecting = true;
            try {
                const codes = await detector.detect(video);
                if (codes.length > 0 && codes[0].rawValue) {
                    await lookup(codes[0].rawValue);
                    return;
                }
            } catch (error) {
                announce('Pemindaian kamera terhenti. Aktifkan kembali atau gunakan input manual.', 'error');
                stopCamera();
                return;
            } finally {
                detecting = false;
            }
        }
        if (stream) frameId = window.requestAnimationFrame(detectFrame);
    }

    async function startCamera() {
        if (!cameraSupported) return;
        result.hidden = true;
        announce('Meminta izin kamera...', 'loading');
        try {
            detector = new window.BarcodeDetector();
            stream = await navigator.mediaDevices.getUserMedia({
                video: {facingMode: {ideal: 'environment'}},
                audio: false
            });
            video.srcObject = stream;
            await video.play();
            viewport.classList.add('is-active');
            placeholder.hidden = true;
            startButton.hidden = true;
            stopButton.hidden = false;
            announce('Kamera aktif. Arahkan barcode ke dalam bingkai.', 'success');
            frameId = window.requestAnimationFrame(detectFrame);
        } catch (error) {
            stopCamera();
            announce(error.name === 'NotAllowedError'
                ? 'Izin kamera ditolak. Izinkan kamera atau gunakan scanner USB/input manual.'
                : 'Kamera tidak dapat dibuka. Pastikan tidak dipakai aplikasi lain.', 'error');
        }
    }

    form.addEventListener('submit', function (event) {
        event.preventDefault();
        lookup(input.value);
    });
    startButton.addEventListener('click', startCamera);
    stopButton.addEventListener('click', function () {
        stopCamera();
        announce('Kamera dihentikan.', '');
    });
    scanAgain.addEventListener('click', function () {
        result.hidden = true;
        input.value = '';
        input.focus();
        announce('Siap memindai barang berikutnya.', '');
    });
    window.addEventListener('pagehide', stopCamera);
    input.focus();
}());

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
    let nativeDetector = null;
    let frameId = null;
    let lookupController = null;
    let detecting = false;

    // ISO/IEC 15417 Code-128 patterns (index 0..105, Stop = 106) for built-in Canvas fallback on Windows browsers without BarcodeDetector
    const CODE128_PATTERNS = [
        '212222','222122','222221','121223','121322','131222','122213','122312','132212','221213',
        '221312','231212','112232','122132','122231','113222','123122','123221','223211','221132',
        '221231','213212','223112','312131','311222','321122','321221','312212','322112','322211',
        '212123','212321','232121','111323','131123','131321','112313','132113','132311','211313',
        '231113','231311','112133','112331','132131','113123','113321','133121','313121','211331',
        '231131','213113','213311','213131','311123','311321','331121','312113','312311','332111',
        '314111','221411','431111','111224','111422','121124','121421','141122','141221','112214',
        '112412','122114','122411','142112','142211','241211','221114','413111','241112','134111',
        '111242','121142','121241','114212','124112','124211','411212','421112','421211','212141',
        '214121','412121','111143','111341','131141','114113','114311','411113','411311','113141',
        '114131','311141','411131','211412','211214','211232'
    ];
    const PATTERN_MAP = {};
    CODE128_PATTERNS.forEach(function (p, idx) { PATTERN_MAP[p] = idx; });

    const offscreenCanvas = document.createElement('canvas');
    const offscreenCtx = offscreenCanvas.getContext('2d', { willReadFrequently: true });

    // Muat decoder 2D QR Code (jsQR) secara otomatis jika browser belum menyediakan BarcodeDetector bawaan
    if (!('BarcodeDetector' in window) && typeof window.jsQR !== 'function') {
        const script = document.createElement('script');
        script.src = 'https://cdn.jsdelivr.net/npm/jsqr@1.4.0/dist/jsQR.min.js';
        script.async = true;
        document.head.appendChild(script);
    }

    // Aktifkan tombol kamera selama browser menyediakan akses MediaDevices (getUserMedia)
    const cameraSupported = Boolean(
        navigator.mediaDevices &&
        typeof navigator.mediaDevices.getUserMedia === 'function'
    );

    if (support) {
        support.textContent = cameraSupported ? 'Kamera siap digunakan' : 'Gunakan scanner USB / input';
        support.classList.toggle('is-supported', cameraSupported);
    }
    if (startButton) {
        startButton.disabled = !cameraSupported;
    }

    function matchCode128Symbol(runs, startIndex) {
        if (startIndex + 5 >= runs.length) return -1;
        let total = 0;
        for (let i = 0; i < 6; i++) total += runs[startIndex + i];
        if (total < 6) return -1;

        let sig = '';
        let sumMods = 0;
        const mods = [];
        for (let i = 0; i < 6; i++) {
            const m = Math.max(1, Math.min(4, Math.round((runs[startIndex + i] * 11) / total)));
            mods.push(m);
            sumMods += m;
        }
        if (sumMods !== 11) return -1;
        sig = mods.join('');
        return PATTERN_MAP[sig] !== undefined ? PATTERN_MAP[sig] : -1;
    }

    function decodeCode128FromScanline(grayRow, width) {
        // Hitung ambang batas adaptif lokal
        let min = 255;
        let max = 0;
        for (let x = 0; x < width; x++) {
            const v = grayRow[x];
            if (v < min) min = v;
            if (v > max) max = v;
        }
        if (max - min < 35) return null;
        const threshold = (min + max) >> 1;

        // Konversi ke run-length (bar hitam = 1, spasi putih = 0)
        const runs = [];
        const isBlackRun = [];
        let currentBlack = grayRow[0] < threshold;
        let count = 1;
        for (let x = 1; x < width; x++) {
            const black = grayRow[x] < threshold;
            if (black === currentBlack) {
                count++;
            } else {
                runs.push(count);
                isBlackRun.push(currentBlack);
                currentBlack = black;
                count = 1;
            }
        }
        runs.push(count);
        isBlackRun.push(currentBlack);

        // Cari Start Code B (nilai 104, dimulai pada bar hitam)
        for (let i = 0; i + 18 < runs.length; i++) {
            if (!isBlackRun[i]) continue;
            const sym = matchCode128Symbol(runs, i);
            if (sym !== 104) continue;

            const values = [104];
            let cursor = i + 6;
            while (cursor + 6 < runs.length && values.length < 32) {
                const nextSym = matchCode128Symbol(runs, cursor);
                if (nextSym === -1) break;
                values.push(nextSym);
                cursor += 6;

                // Cek apakah sudah mencapai checksum + Stop
                if (values.length >= 3) {
                    const candidateChecksum = values[values.length - 1];
                    let sum = values[0];
                    for (let k = 1; k < values.length - 1; k++) {
                        sum += values[k] * k;
                    }
                    if (sum % 103 === candidateChecksum) {
                        let decoded = '';
                        for (let k = 1; k < values.length - 1; k++) {
                            if (values[k] >= 0 && values[k] <= 94) {
                                decoded += String.fromCharCode(values[k] + 32);
                            }
                        }
                        if (decoded.length >= 4) {
                            return decoded.trim();
                        }
                    }
                }
            }
        }
        return null;
    }

    function detectBarcodeFromCanvas(videoEl) {
        const w = Math.min(640, videoEl.videoWidth || 640);
        const h = Math.min(480, videoEl.videoHeight || 480);
        if (w < 64 || h < 64 || !offscreenCtx) return null;

        offscreenCanvas.width = w;
        offscreenCanvas.height = h;
        offscreenCtx.drawImage(videoEl, 0, 0, w, h);

        // 1. Prioritaskan pemindaian 2D QR Code jika pustaka jsQR tersedia
        if (typeof window.jsQR === 'function') {
            try {
                const fullFrame = offscreenCtx.getImageData(0, 0, w, h);
                const qrResult = window.jsQR(fullFrame.data, w, h, { inversionAttempts: 'dontInvert' });
                if (qrResult && qrResult.data && qrResult.data.trim()) {
                    return qrResult.data.trim();
                }
            } catch (_) {
                // Lanjutkan ke fallback scanline
            }
        }

        const scanRatios = [0.5, 0.42, 0.58, 0.35, 0.65];
        const grayRow = new Uint8Array(w);

        for (let r = 0; r < scanRatios.length; r++) {
            const y = Math.floor(h * scanRatios[r]);
            const imgData = offscreenCtx.getImageData(0, y, w, 1).data;
            for (let x = 0; x < w; x++) {
                const offset = x * 4;
                grayRow[x] = (imgData[offset] * 77 + imgData[offset + 1] * 150 + imgData[offset + 2] * 29) >> 8;
            }
            const code = decodeCode128FromScanline(grayRow, w);
            if (code) return code;
        }
        return null;
    }

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
                let detectedValue = null;

                if (nativeDetector) {
                    try {
                        const codes = await nativeDetector.detect(video);
                        if (codes && codes.length > 0 && codes[0].rawValue) {
                            detectedValue = codes[0].rawValue;
                        }
                    } catch (_) {
                        nativeDetector = null;
                    }
                }

                if (!detectedValue) {
                    detectedValue = detectBarcodeFromCanvas(video);
                }

                if (detectedValue) {
                    await lookup(detectedValue);
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

    async function requestCameraStream() {
        try {
            return await navigator.mediaDevices.getUserMedia({
                video: { facingMode: { ideal: 'environment' }, width: { ideal: 1280 }, height: { ideal: 720 } },
                audio: false
            });
        } catch (firstError) {
            if (firstError && firstError.name === 'NotAllowedError') {
                throw firstError;
            }
            // Fallback untuk webcam laptop/PC yang tidak mendukung constraint facingMode/resolusi tertentu
            return await navigator.mediaDevices.getUserMedia({
                video: true,
                audio: false
            });
        }
    }

    async function startCamera() {
        if (!cameraSupported) return;
        result.hidden = true;
        announce('Meminta izin kamera...', 'loading');
        try {
            if ('BarcodeDetector' in window) {
                try {
                    nativeDetector = new window.BarcodeDetector();
                } catch (_) {
                    nativeDetector = null;
                }
            }
            stream = await requestCameraStream();
            video.srcObject = stream;
            await video.play();
            viewport.classList.add('is-active');
            placeholder.hidden = true;
            startButton.hidden = true;
            stopButton.hidden = false;
            announce('Kamera aktif. Arahkan barcode (BRG-######) ke dalam bingkai.', 'success');
            frameId = window.requestAnimationFrame(detectFrame);
        } catch (error) {
            stopCamera();
            announce(error && error.name === 'NotAllowedError'
                ? 'Izin kamera ditolak oleh browser. Klik ikon gembok/kamera di address bar untuk mengizinkan kamera.'
                : 'Kamera tidak dapat dibuka. Pastikan kamera terpasang dan tidak sedang dipakai aplikasi lain.', 'error');
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

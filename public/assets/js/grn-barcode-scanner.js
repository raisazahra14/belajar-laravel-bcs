(function () {
    'use strict';

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

    function initGrnBarcodeScanner() {
        const scannerRoot = document.getElementById('grn-barcode-scanner');
        const receiptForm = document.getElementById('goodsReceiptForm');
        if (!scannerRoot || !receiptForm) return;

        const codeInput = document.getElementById('grn-scanner-input');
        const stepInput = document.getElementById('grn-scanner-step');
        const addBtn = document.getElementById('grn-scanner-add-btn');
        const cameraStartBtn = document.getElementById('grn-camera-start-btn');
        const cameraStopBtn = document.getElementById('grn-camera-stop-btn');
        const cameraViewport = document.getElementById('grn-camera-viewport');
        const video = document.getElementById('grn-scanner-video');
        const feedback = document.getElementById('grn-scanner-feedback');
        const totalBadge = document.getElementById('grn-scan-total-badge');
        const receiptErrorAlert = document.getElementById('receipt-error-alert');
        const modalEl = document.getElementById('goodsReceiptModal');

        const completedCodesRaw = scannerRoot.getAttribute('data-completed-codes') || '';
        const completedCodes = completedCodesRaw
            .split(',')
            .map(function (c) { return c.trim().toUpperCase(); })
            .filter(Boolean);

        let stream = null;
        let detector = null;
        let frameId = null;
        let detecting = false;
        let lastScanCode = '';
        let lastScanTime = 0;

        const offscreenCanvas = document.createElement('canvas');
        const offscreenCtx = offscreenCanvas.getContext('2d', { willReadFrequently: true });

        if (!('BarcodeDetector' in window) && typeof window.jsQR !== 'function') {
            const script = document.createElement('script');
            script.src = 'https://cdn.jsdelivr.net/npm/jsqr@1.4.0/dist/jsQR.min.js';
            script.async = true;
            document.head.appendChild(script);
        }

        const cameraSupported = Boolean(
            navigator.mediaDevices &&
            typeof navigator.mediaDevices.getUserMedia === 'function'
        );

        if (cameraStartBtn) {
            cameraStartBtn.disabled = !cameraSupported;
            if (!cameraSupported) {
                cameraStartBtn.title = 'Browser perangkat ini belum mengizinkan akses kamera; gunakan scanner USB atau input kode.';
            }
        }

        function matchCode128Symbol(runs, startIndex) {
            if (startIndex + 5 >= runs.length) return -1;
            let total = 0;
            for (let i = 0; i < 6; i++) total += runs[startIndex + i];
            if (total < 6) return -1;

            let sumMods = 0;
            const mods = [];
            for (let i = 0; i < 6; i++) {
                const m = Math.max(1, Math.min(4, Math.round((runs[startIndex + i] * 11) / total)));
                mods.push(m);
                sumMods += m;
            }
            if (sumMods !== 11) return -1;
            const sig = mods.join('');
            return PATTERN_MAP[sig] !== undefined ? PATTERN_MAP[sig] : -1;
        }

        function decodeCode128FromScanline(grayRow, width) {
            let min = 255;
            let max = 0;
            for (let x = 0; x < width; x++) {
                const v = grayRow[x];
                if (v < min) min = v;
                if (v > max) max = v;
            }
            if (max - min < 35) return null;
            const threshold = (min + max) >> 1;

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

        function normalizeBarcode(raw) {
            const cleaned = String(raw || '').trim().toUpperCase();
            if (!cleaned) return '';
            const match = cleaned.match(/BRG-[A-Z0-9-]+/i);
            return match ? match[0].toUpperCase() : cleaned;
        }

        function showFeedback(message, variant) {
            if (!feedback) return;
            feedback.hidden = false;
            feedback.className = 'alert py-2 px-3 mb-0 mt-2 small alert-' + (variant || 'info');
            feedback.textContent = message;
        }

        function updateTotalBadge() {
            if (!totalBadge) return;
            const qtyInputs = receiptForm.querySelectorAll('.receipt-qty-input');
            let sum = 0;
            qtyInputs.forEach(function (input) {
                const v = parseInt(input.value, 10) || 0;
                if (v > 0) sum += v;
            });
            totalBadge.textContent = sum.toLocaleString('id-ID') + ' unit siap diterima';
        }

        function processScannedCode(rawValue) {
            const code = normalizeBarcode(rawValue);
            if (!code) {
                showFeedback('Masukkan atau pindai kode barang (contoh: BRG-000001) terlebih dahulu.', 'warning');
                if (codeInput) codeInput.focus();
                return false;
            }

            const step = Math.max(1, parseInt(stepInput ? stepInput.value : '1', 10) || 1);
            const rows = receiptForm.querySelectorAll('tr.grn-item-row');
            let matchedRow = null;

            rows.forEach(function (row) {
                const rowCode = (row.getAttribute('data-kode-barang') || '').trim().toUpperCase();
                if (rowCode === code) {
                    matchedRow = row;
                }
            });

            if (!matchedRow) {
                if (completedCodes.indexOf(code) !== -1) {
                    showFeedback('Barang dengan kode ' + code + ' sudah diterima penuh pada penerimaan sebelumnya (sisa 0).', 'warning');
                } else {
                    showFeedback('Kode barang ' + code + ' tidak terdaftar pada daftar item Purchase Order ini.', 'danger');
                }
                if (codeInput) {
                    codeInput.select();
                }
                return false;
            }

            const qtyInput = matchedRow.querySelector('.receipt-qty-input');
            if (!qtyInput) return false;

            const maxSisa = parseInt(qtyInput.getAttribute('data-max'), 10) || 0;
            const currentQty = parseInt(qtyInput.value, 10) || 0;
            const itemName = matchedRow.getAttribute('data-nama-barang') || code;
            const itemUnit = matchedRow.getAttribute('data-satuan') || 'unit';

            if (currentQty >= maxSisa) {
                showFeedback(
                    'Batas sisa pesanan tercapai: ' + itemName + ' (' + code + ') sudah berada pada kuantitas maksimum (' + maxSisa + ' ' + itemUnit + ').',
                    'warning'
                );
                matchedRow.classList.add('table-warning');
                setTimeout(function () { matchedRow.classList.remove('table-warning'); }, 900);
                if (codeInput) {
                    codeInput.value = '';
                    codeInput.focus();
                }
                return false;
            }

            const newQty = Math.min(maxSisa, currentQty + step);
            const addedQty = newQty - currentQty;
            qtyInput.value = String(newQty);
            qtyInput.dispatchEvent(new Event('input', { bubbles: true }));
            qtyInput.dispatchEvent(new Event('change', { bubbles: true }));

            if (receiptErrorAlert) {
                receiptErrorAlert.classList.add('d-none');
            }

            matchedRow.classList.remove('table-warning');
            matchedRow.classList.add('table-success');
            setTimeout(function () { matchedRow.classList.remove('table-success'); }, 900);

            updateTotalBadge();
            showFeedback(
                'Scan berhasil: ' + code + ' — ' + itemName + ' (+' + addedQty + ' ' + itemUnit + '). Total diterima: ' + newQty + ' / ' + maxSisa + ' ' + itemUnit + '.',
                'success'
            );

            if (codeInput) {
                codeInput.value = '';
                codeInput.focus();
            }
            return true;
        }

        function stopCamera() {
            if (frameId !== null) {
                window.cancelAnimationFrame(frameId);
                frameId = null;
            }
            detecting = false;
            if (stream) {
                stream.getTracks().forEach(function (track) { track.stop(); });
                stream = null;
            }
            if (video) {
                video.srcObject = null;
            }
            if (cameraViewport) {
                cameraViewport.hidden = true;
            }
            if (cameraStartBtn) {
                cameraStartBtn.hidden = false;
            }
            if (cameraStopBtn) {
                cameraStopBtn.hidden = true;
            }
        }

        async function detectCameraFrame() {
            if (!stream || detecting || !video) return;
            if (video.readyState >= HTMLMediaElement.HAVE_CURRENT_DATA) {
                detecting = true;
                try {
                    let rawDetected = null;

                    if (detector) {
                        try {
                            const barcodes = await detector.detect(video);
                            if (barcodes && barcodes.length > 0 && barcodes[0].rawValue) {
                                rawDetected = barcodes[0].rawValue;
                            }
                        } catch (_) {
                            detector = null;
                        }
                    }

                    if (!rawDetected) {
                        rawDetected = detectBarcodeFromCanvas(video);
                    }

                    if (rawDetected) {
                        const now = Date.now();
                        const rawCode = normalizeBarcode(rawDetected);
                        if (rawCode && (rawCode !== lastScanCode || now - lastScanTime > 1200)) {
                            lastScanCode = rawCode;
                            lastScanTime = now;
                            processScannedCode(rawCode);
                        }
                    }
                } catch (err) {
                    showFeedback('Pemindaian kamera terhenti. Gunakan scanner USB atau ketik kode barang.', 'warning');
                    stopCamera();
                    return;
                } finally {
                    detecting = false;
                }
            }
            if (stream) {
                frameId = window.requestAnimationFrame(detectCameraFrame);
            }
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
                return await navigator.mediaDevices.getUserMedia({
                    video: true,
                    audio: false
                });
            }
        }

        async function startCamera() {
            if (!cameraSupported || !video) return;
            showFeedback('Mengaktifkan kamera pemindai barcode...', 'info');
            try {
                if ('BarcodeDetector' in window) {
                    try {
                        detector = new window.BarcodeDetector();
                    } catch (_) {
                        detector = null;
                    }
                }
                stream = await requestCameraStream();
                video.srcObject = stream;
                await video.play();
                if (cameraViewport) cameraViewport.hidden = false;
                if (cameraStartBtn) cameraStartBtn.hidden = true;
                if (cameraStopBtn) cameraStopBtn.hidden = false;
                showFeedback('Kamera aktif. Arahkan barcode / QR barang (BRG-######) ke kamera.', 'info');
                frameId = window.requestAnimationFrame(detectCameraFrame);
            } catch (err) {
                stopCamera();
                showFeedback(
                    err && err.name === 'NotAllowedError'
                        ? 'Izin kamera ditolak oleh browser. Klik ikon gembok/kamera di address bar untuk mengizinkan kamera.'
                        : 'Kamera tidak dapat diakses pada perangkat ini.',
                    'danger'
                );
            }
        }

        if (codeInput) {
            codeInput.addEventListener('keydown', function (event) {
                if (event.key === 'Enter') {
                    event.preventDefault();
                    event.stopPropagation();
                    processScannedCode(codeInput.value);
                }
            });
        }

        if (addBtn) {
            addBtn.addEventListener('click', function () {
                processScannedCode(codeInput ? codeInput.value : '');
            });
        }

        if (cameraStartBtn) {
            cameraStartBtn.addEventListener('click', startCamera);
        }

        if (cameraStopBtn) {
            cameraStopBtn.addEventListener('click', function () {
                stopCamera();
                showFeedback('Kamera pemindai dihentikan.', 'secondary');
            });
        }

        receiptForm.querySelectorAll('.receipt-qty-input').forEach(function (input) {
            input.addEventListener('input', updateTotalBadge);
            input.addEventListener('change', updateTotalBadge);
        });

        receiptForm.querySelectorAll('.btn-fill-row-max, #btn-receive-all-remaining').forEach(function (btn) {
            btn.addEventListener('click', function () {
                setTimeout(updateTotalBadge, 10);
            });
        });

        if (modalEl) {
            modalEl.addEventListener('shown.bs.modal', function () {
                if (codeInput) codeInput.focus();
                updateTotalBadge();
            });
            modalEl.addEventListener('hidden.bs.modal', stopCamera);
        }

        window.addEventListener('pagehide', stopCamera);
        updateTotalBadge();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initGrnBarcodeScanner, { once: true });
    } else {
        initGrnBarcodeScanner();
    }
}());

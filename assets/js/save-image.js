/* eslint n/no-unsupported-features/node-builtins: "off" */

/** Browser-only module: save the finished result to a user-chosen location. */
(function () {
    'use strict';

    const result = document.querySelector('[data-stage="result"]');
    const button = document.querySelector('[data-save-image]');
    const status = document.getElementById('save-image-status');
    if (!result || !button || !status) {
        return;
    }

    const canChooseLocation = window.isSecureContext && typeof window.showSaveFilePicker === 'function';
    const isSgu = typeof config !== 'undefined' && config.sgu && config.sgu.enabled === true;
    const sequenceStorageKey = 'photobooth.sgu.download-sequence.v1';
    const formats = {
        jpg: 'image/jpeg',
        jpeg: 'image/jpeg',
        png: 'image/png',
        gif: 'image/gif',
        webp: 'image/webp',
        mp4: 'video/mp4',
        webm: 'video/webm',
        mov: 'video/quicktime'
    };
    let busy = false;
    let displayedFilename = null;
    let memorySequence = 0;

    function localDateStamp() {
        const now = new Date();
        return String(now.getFullYear()) + String(now.getMonth() + 1).padStart(2, '0') + String(now.getDate()).padStart(2, '0');
    }

    function nextSequence() {
        if (!isSgu) {
            return null;
        }
        try {
            const saved = Number.parseInt(window.localStorage.getItem(sequenceStorageKey), 10);
            return Math.max(memorySequence, Number.isFinite(saved) ? saved : 0) + 1;
        } catch {
            return memorySequence + 1;
        }
    }

    function reserveSequence(sequence) {
        if (sequence === null) {
            return;
        }
        memorySequence = Math.max(memorySequence, sequence);
        try {
            window.localStorage.setItem(sequenceStorageKey, String(memorySequence));
        } catch {
            // Keep a per-page sequence when storage is disabled by browser policy.
        }
    }

    function currentFile() {
        const filename = result.getAttribute('data-img') || '';
        if (!/^[A-Za-z0-9._-]+\.(jpe?g|png|gif|webp|mp4|webm|mov)$/i.test(filename)) {
            return null;
        }
        const extension = filename.split('.').pop().toLowerCase();
        const sequence = nextSequence();
        return {
            filename,
            extension,
            mime: formats[extension],
            // Use the finished, full-resolution file, including any applied filter.
            url: environment.publicFolders.images + '/' + encodeURIComponent(filename),
            sequence,
            suggestedName: sequence === null
                ? 'photobooth-' + filename
                : 'SGU-' + localDateStamp() + '-' + String(sequence).padStart(3, '0') + '.' + extension
        };
    }

    function update() {
        const file = currentFile();
        const filename = file ? file.filename : null;
        if (filename !== displayedFilename) {
            displayedFilename = filename;
            status.textContent = '';
        }
        button.disabled = busy || !file || !result.classList.contains('stage--active');
        if (!canChooseLocation && file && !status.textContent) {
            status.textContent =
                'Ảnh sẽ tải về theo cài đặt trình duyệt. Để chọn nơi lưu trực tiếp, mở web bằng Chrome hoặc Edge trên máy tính qua localhost hoặc HTTPS.';
        }
    }

    function setBusy(value) {
        busy = value;
        button.setAttribute('aria-busy', String(value));
        update();
        document.dispatchEvent(new CustomEvent('photobooth.save.busy', { detail: { busy: value } }));
    }

    if (!canChooseLocation) {
        button.querySelector('.button--label').textContent = 'Tải ảnh về máy';
    }

    button.addEventListener('click', async (event) => {
        event.preventDefault();
        event.stopPropagation();
        const file = currentFile();
        if (busy || !file || !result.classList.contains('stage--active')) {
            return;
        }

        if (!canChooseLocation) {
            const link = document.createElement('a');
            link.href = file.url;
            link.download = file.suggestedName;
            document.body.append(link);
            link.click();
            link.remove();
            reserveSequence(file.sequence);
            // A download dispatch is not confirmation that the file was saved.
            status.textContent = 'Đã gửi yêu cầu tải ảnh. Xem mục Tệp đã tải xuống của trình duyệt để mở ảnh.';
            return;
        }

        const showStatus = (message) => {
            if (result.getAttribute('data-img') === file.filename) {
                status.textContent = message;
            }
        };
        let phase = 'picker';
        let writable;
        let fetchTimeout;
        setBusy(true);
        showStatus('Chọn thư mục và tên file trong cửa sổ lưu ảnh.');
        try {
            // Keep the picker before the first await/fetch to preserve user activation.
            const handle = await window.showSaveFilePicker({
                id: 'photobooth-save-image',
                suggestedName: file.suggestedName,
                types: [{ description: 'Ảnh / video Photobooth', accept: { [file.mime]: ['.' + file.extension] } }]
            });
            // Every accepted save gets a fresh name. This avoids asking the browser to
            // atomically replace the same destination and leaving a staging .tmp after
            // an interrupted or denied overwrite.
            reserveSequence(file.sequence);
            phase = 'fetch';
            showStatus('Đang tải ảnh để lưu…');
            const controller = new AbortController();
            fetchTimeout = setTimeout(() => controller.abort(), 60000);
            const response = await fetch(file.url, {
                credentials: 'same-origin',
                cache: 'no-store',
                signal: controller.signal
            });
            if (!response.ok) {
                throw new Error('Image request failed: ' + response.status);
            }
            const blob = await response.blob();
            if (!blob.size || (blob.type && blob.type !== file.mime && blob.type !== 'application/octet-stream')) {
                throw new Error('Unexpected image response');
            }
            clearTimeout(fetchTimeout);
            phase = 'write';
            showStatus('Đang lưu ảnh…');
            writable = await handle.createWritable();
            await writable.write(blob);
            await writable.close();
            writable = null;
            showStatus('Đã lưu “' + handle.name + '” vào nơi bạn chọn.');
        } catch (error) {
            if (writable) {
                try {
                    await writable.abort();
                } catch {
                    // A failed stream may already be closed; preserve the original error.
                }
            }
            if (phase === 'picker' && error.name === 'AbortError') {
                showStatus('Đã hủy lưu ảnh. Bạn có thể chọn nơi lưu lại.');
            } else if (phase === 'fetch') {
                showStatus('Không tải được ảnh để lưu. Kiểm tra kết nối rồi bấm chọn nơi lưu lại.');
            } else if (phase === 'write' && writable && isSgu) {
                showStatus('Trình duyệt chưa hoàn tất ghi file. Nếu còn file .tmp, đó là bản tạm chưa được xác nhận; hãy giữ file JPG đã lưu và xóa bản .tmp sau khi đóng Photobooth. Lần lưu kế tiếp sẽ có tên số mới.');
            } else {
                showStatus('Chưa lưu được ảnh. Bấm chọn nơi lưu lại và chọn thư mục bạn có quyền ghi.');
            }
        } finally {
            clearTimeout(fetchTimeout);
            setBusy(false);
        }
    });

    new MutationObserver(update).observe(result, { attributes: true, attributeFilter: ['data-img', 'class'] });
    update();
})();

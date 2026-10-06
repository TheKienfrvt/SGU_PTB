/* globals photoboothPreview photoboothPhotoCamera sguKiosk templateSelection csrf */
/* eslint n/no-unsupported-features/node-builtins: "off" */
/* exported createCaptureSession */

// An extension owned by photoBooth, never an independent flow-state controller.
// eslint-disable-next-line no-unused-vars
const createCaptureSession = (booth) => {
    const root = document.querySelector('[data-capture-session]');
    if (!root) {
        return null;
    }
    const element = (name) => root.querySelector('[data-session-' + name + ']');
    const pairCancelButton = document.querySelector('[data-template-pair-cancel]');
    const templateSaveButton = document.querySelector('[data-template-save-directory]');
    const templateSaveStatus = document.querySelector('[data-template-save-status]');
    const saveButtons = [element('save-directory'), templateSaveButton].filter(Boolean);
    const saveStatuses = [element('save-status'), templateSaveStatus].filter(Boolean);
    const settings = config.sgu;
    // The sheet is two finished frames joined edge to edge, so its ratio comes from the JPEG itself.
    const printSheet = Object.freeze({ format: 'A5-pair', label: 'A5' });
    const printSheetAspect = 'natural';
    let session = null;
    let busy = false;
    let generation = 0;
    let completionTimer;
    let waitingTimer;
    let selected = [];
    let recovering = false;
    let currentSlotIndex = 0;
    let retakeSlotIndex = null;
    let pairPending = false;
    let directoryHandle = null;
    let autoSaveBusy = false;
    let directoryPickerBusy = false;
    let autoSaveQueue = Promise.resolve();
    const savedAssets = new Set();
    const canChooseDirectory =
        typeof window !== 'undefined' &&
        window.isSecureContext === true &&
        typeof window.showDirectoryPicker === 'function';
    const messages = {
        AGENT_NOT_CONFIGURED: 'Chưa bật Windows Camera Agent / Sony SDK bridge. Cam Link chỉ dùng xem trước.',
        AGENT_UNREACHABLE:
            'Windows Camera Agent chưa chạy. Nhờ người vận hành khởi động agent rồi bấm Kết nối lại camera.',
        CAMERA_NOT_CONNECTED: 'Chưa phát hiện máy ảnh USB. Kiểm tra dây USB và PC Remote trên máy ảnh.',
        CAMERA_BUSY: 'Máy ảnh USB đang bận. Chờ ít giây rồi bấm Kết nối lại camera.',
        DEVICE_NOT_READY: 'Hệ thống chụp chưa sẵn sàng (bộ nhớ lưu ảnh hoặc máy in). Nhờ người vận hành kiểm tra.',
        EXIF_UNAVAILABLE: 'Cần bật extension PHP EXIF trước khi chụp nhiều ảnh để xử lý đúng hướng ảnh camera.',
        FINAL_CHANGED:
            'File ảnh đã bị thay đổi bên ngoài phiên. Đã chặn in để tránh in ảnh khác với preview; nhờ người vận hành kiểm tra.',
        STORAGE_FULL: 'Ổ lưu ảnh sắp đầy. Nhờ người vận hành giải phóng dung lượng rồi tiếp tục.',
        PREVIEW_UNAVAILABLE: 'Preview đã mất kết nối. Kiểm tra CamLink, kết nối lại rồi tiếp tục.',
        PRINT_DISABLED: 'Chưa bật in từ kết quả hoặc chưa cấu hình lệnh in. Ảnh của bạn vẫn được giữ.',
        PRINT_LOCKED: 'Máy in đang khóa. Nhờ người vận hành kiểm tra giấy và mở khóa rồi thử lại.',
        PRINT_UNCERTAIN: 'Chưa xác nhận được lệnh in. Nhờ người vận hành kiểm tra hàng đợi trước khi in lại.',
        SESSION_BUSY: 'Máy đang xử lý yêu cầu trước. Chọn Kiểm tra trạng thái sau ít giây.',
        DEVICE_BUSY: 'Camera hoặc máy in đang bận. Chờ ít giây rồi kiểm tra trạng thái.',
        SESSION_EXPIRED: 'Phiên đã hết hạn. Kết thúc lượt này rồi bắt đầu lượt mới.',
        INVALID_OVERLAY: 'Khung PNG chưa đúng kích thước đầu ra. Nhờ người vận hành kiểm tra cấu hình.',
        INVALID_TEMPLATE: 'Khung đã chọn không còn hợp lệ. Vui lòng chọn lại.',
        TEMPLATE_REQUIRED: 'Vui lòng chọn một khung trước khi tiếp tục.',
        IMAGE_MEMORY_LIMIT: 'Ảnh vượt giới hạn bộ nhớ xử lý. Nhờ người vận hành kiểm tra độ phân giải.',
        INVALID_JPEG: 'File camera không phải JPEG hợp lệ hoặc vượt giới hạn kích thước.'
    };
    const request = async (action, data = {}, photo = null) => {
        const controller = new AbortController();
        const timeout = setTimeout(() => controller.abort(), 150000);
        try {
            const body = new FormData();
            body.set('action', action);
            body.set(csrf.key, csrf.token);
            body.set('session_id', session?.id || '');
            Object.entries(data).forEach(([key, value]) => {
                if (Array.isArray(value)) {
                    value.forEach((item) => body.append(key + '[]', item));
                } else {
                    body.set(key, value);
                }
            });
            if (typeof Blob !== 'undefined' && photo instanceof Blob) {
                const number = String((retakeSlotIndex ?? currentSlotIndex) + 1).padStart(2, '0');
                body.set('photo', photo, 'capture-' + number + '.jpg');
            }
            const response = await fetch(environment.publicFolders.api + '/captureSession.php', {
                method: 'POST',
                credentials: 'same-origin',
                cache: 'no-store',
                body,
                signal: controller.signal
            });
            const result = await response.json();
            if (!response.ok || !result.success) {
                const error = new Error(result.error || 'SESSION_FAILED');
                error.reselectTemplate = result.reselect_template === true;
                throw error;
            }
            session = result.session;
            pairPending = result.pair_pending === true;
            if (pairCancelButton) {
                pairCancelButton.hidden = !pairPending;
            }
            if (session?.template_id && typeof templateSelection !== 'undefined') {
                templateSelection.setSelectedTemplateId(session.template_id);
            }
            return session;
        } finally {
            clearTimeout(timeout);
        }
    };
    const setState = (state, title) => {
        booth.setFlowState(state);
        root.hidden = false;
        root.dataset.state = state;
        element('title').textContent = title;
        element('title').focus({ preventScroll: true });
        [
            'camera',
            'template-shell',
            'template',
            'photo-shell',
            'photo',
            'grid',
            'countdown',
            'capture',
            'retake',
            'compose',
            'confirm',
            'duplicate',
            'second',
            'print',
            'download',
            'resume',
            'refresh',
            'reconnect'
        ].forEach((name) => {
            element(name).hidden = true;
        });
        element('message').textContent = '';
        element('waiting').hidden = state !== 'composing';
        // Exit is locked while a shot or compose runs, so the bar would only show a dead button.
        element('actions').hidden = ['countdown', 'capturing', 'composing'].includes(state);
        element('exit').disabled = ['countdown', 'capturing', 'composing', 'printing'].includes(state);
        element('counter').textContent = session ? 'Ảnh ' + session.shots.length + ' / ' + session.target : '';
    };
    const fail = (error) => {
        if (error.reselectTemplate || error.message === 'INVALID_TEMPLATE' || error.message === 'TEMPLATE_REQUIRED') {
            session = null;
            selected = [];
            root.hidden = true;
            booth.takingPic = false;
            booth.reset();
            if (typeof templateSelection !== 'undefined') {
                templateSelection.requireReselection(messages[error.message]);
            }
            return;
        }
        root.classList.remove('sgu-session--flash');
        setState('error', 'Cần kiểm tra');
        element('message').textContent =
            messages[error.message] ||
            'Yêu cầu chưa hoàn tất. Ảnh đã chụp vẫn được giữ; kiểm tra trạng thái trước khi tiếp tục.';
        element('refresh').hidden = false;
        element('reconnect').hidden = !reconnectErrors.includes(error.message);
        recovering = true;
    };
    const reconnectErrors = ['PREVIEW_UNAVAILABLE', 'AGENT_UNREACHABLE', 'CAMERA_NOT_CONNECTED', 'CAMERA_BUSY'];
    // Maps the last USB status to the real blocker instead of blaming the preview.
    const usbCameraError = () => {
        const status = photoboothPhotoCamera.status || {};
        if (status.agent_online === false) {
            return 'AGENT_UNREACHABLE';
        }
        return status.camera_busy === true ? 'CAMERA_BUSY' : 'CAMERA_NOT_CONNECTED';
    };
    // A native session without shots belongs on the welcome screen, where the
    // live preview and the fail-closed start button already live.
    const returnToStart = () => {
        root.hidden = true;
        booth.takingPic = false;
        if (typeof templateSelection !== 'undefined' && templateSelection.getSelectedTemplateId() !== '') {
            templateSelection.showPreview();
        } else {
            document.querySelector('[data-stage="start"]').classList.add('stage--active');
        }
        booth.setFlowState('idle');
    };
    const guarded = async (action) => {
        if (busy) {
            return;
        }
        busy = true;
        try {
            await action();
        } catch (error) {
            fail(error);
        } finally {
            busy = false;
        }
    };
    const pause = async (milliseconds, token) => {
        await new Promise((resolve) => setTimeout(resolve, milliseconds));
        if (token !== generation) {
            throw new Error('STALE_SESSION');
        }
    };
    // With wait=true the "Chờ xíu" screen stays up until the final JPEG has loaded.
    const showPhoto = (url, alt, wait = false, aspect = null) => {
        const photo = element('photo');
        const canvas = session?.template?.canvas;
        const previewAction = booth.flowState === 'sheet-preview' ? element('print') : element('confirm');
        previewAction.disabled = true;
        if (aspect === 'natural') {
            if (canvas?.width && canvas?.height) {
                element('photo-shell').style.aspectRatio = canvas.width * 2 + ' / ' + canvas.height;
            }
        } else if (aspect) {
            element('photo-shell').style.aspectRatio = aspect;
        } else if (canvas?.width && canvas?.height) {
            element('photo-shell').style.aspectRatio = canvas.width + ' / ' + canvas.height;
        }
        const stopWaiting = () => {
            clearTimeout(waitingTimer);
            element('waiting').hidden = true;
        };
        if (wait) {
            element('waiting').hidden = false;
            clearTimeout(waitingTimer);
            waitingTimer = setTimeout(() => {
                stopWaiting();
                if (photo.dataset.imageState === 'loading') {
                    element('message').textContent = 'Ảnh xem trước tải quá lâu. Kiểm tra trạng thái để thử tải lại.';
                    element('refresh').hidden = false;
                }
            }, 15000);
        }
        photo.dataset.imageState = 'loading';
        photo.onload = () => {
            photo.dataset.imageState = 'ready';
            if (aspect === 'natural' && photo.naturalWidth && photo.naturalHeight) {
                element('photo-shell').style.aspectRatio = photo.naturalWidth + ' / ' + photo.naturalHeight;
            }
            stopWaiting();
            if (booth.flowState === 'final-preview' || booth.flowState === 'sheet-preview') {
                previewAction.disabled = false;
                element('refresh').hidden = true;
            }
        };
        photo.onerror = () => {
            stopWaiting();
            photo.dataset.imageState = 'error';
            photo.alt = 'Không tải được ' + alt.toLocaleLowerCase('vi');
            element('message').textContent =
                'Không tải được ảnh JPEG xem trước. Hãy kiểm tra file kết quả và đường dẫn ảnh trước khi hoàn tất.';
            if (booth.flowState === 'final-preview' || booth.flowState === 'sheet-preview') {
                element('refresh').hidden = false;
            }
        };
        photo.src = url;
        photo.alt = alt;
        photo.hidden = false;
        element('photo-shell').hidden = false;
    };
    const assetName = (url) => {
        const encoded =
            String(url || '')
                .split('?')[0]
                .split('/')
                .pop() || '';
        try {
            return decodeURIComponent(encoded);
        } catch {
            return encoded;
        }
    };
    const setSaveStatus = (message) => {
        saveStatuses.forEach((status) => {
            status.textContent = message;
            status.hidden = message === '';
        });
    };
    const setSaveButtonsDisabled = (disabled) => {
        saveButtons.forEach((button) => {
            button.disabled = disabled;
        });
    };
    const setSaveButtonLabel = (label) => {
        saveButtons.forEach((button) => {
            button.textContent = label;
        });
    };
    const autoSave = async (url) => {
        if (!url || savedAssets.has(url) || autoSaveBusy) {
            return;
        }
        if (!canChooseDirectory) {
            setSaveStatus('Tự lưu cần Chrome/Edge trên localhost hoặc HTTPS. Bạn vẫn có thể dùng nút Tải JPEG.');
            return;
        }
        if (!directoryHandle) {
            setSaveStatus('Chọn thư mục một lần; các ảnh hoàn tất tiếp theo sẽ tự lưu vào đó.');
            return;
        }
        autoSaveBusy = true;
        setSaveButtonsDisabled(true);
        try {
            if (
                typeof directoryHandle.queryPermission === 'function' &&
                (await directoryHandle.queryPermission({ mode: 'readwrite' })) !== 'granted'
            ) {
                directoryHandle = null;
                setSaveButtonLabel('Chọn nơi lưu ảnh');
                setSaveStatus('Quyền ghi thư mục đã hết. Hãy chọn lại thư mục tự lưu.');
                return;
            }
            setSaveStatus('Đang tự lưu ảnh…');
            const response = await fetch(url, { credentials: 'same-origin', cache: 'no-store' });
            if (!response.ok) {
                throw new Error('SAVE_FETCH_FAILED');
            }
            const blob = await response.blob();
            if (!blob.size || (blob.type && blob.type !== 'image/jpeg' && blob.type !== 'application/octet-stream')) {
                throw new Error('SAVE_INVALID_FILE');
            }
            const filename = assetName(url);
            if (!/^[A-Za-z0-9._-]+\.jpe?g$/i.test(filename)) {
                throw new Error('SAVE_INVALID_NAME');
            }
            const fileHandle = await directoryHandle.getFileHandle(filename, { create: true });
            const writable = await fileHandle.createWritable();
            try {
                await writable.write(blob);
                await writable.close();
            } catch (error) {
                try {
                    await writable.abort();
                } catch {
                    // The stream may already be closed; keep the original failure.
                }
                throw error;
            }
            savedAssets.add(url);
            setSaveStatus('Đã tự lưu “' + filename + '” vào thư mục ' + directoryHandle.name + '.');
        } catch {
            setSaveStatus('Không thể tự lưu ảnh. Hãy chọn lại thư mục có quyền ghi hoặc dùng nút Tải JPEG.');
        } finally {
            autoSaveBusy = false;
            setSaveButtonsDisabled(!canChooseDirectory || directoryPickerBusy);
        }
    };
    const queueAutoSave = (url) => {
        autoSaveQueue = autoSaveQueue.then(() => autoSave(url));
        return autoSaveQueue;
    };
    const browserMode = () => (session?.capture_mode || settings.capture_mode) === 'browser';
    const slotPosition = (position) =>
        ({
            center: '50% 50%',
            top: '50% 0%',
            bottom: '50% 100%',
            left: '0% 50%',
            right: '100% 50%',
            'top-left': '0% 0%',
            'top-right': '100% 0%',
            'bottom-left': '0% 100%',
            'bottom-right': '100% 100%'
        })[position] || '50% 50%';
    const renderTemplate = (selectable = false) => {
        const preview = element('template');
        const definition = session?.template;
        if (!definition?.canvas || !Array.isArray(definition.slots)) {
            preview.hidden = true;
            element('template-shell').hidden = true;
            return;
        }
        preview.replaceChildren();
        preview.hidden = false;
        element('template-shell').hidden = false;
        element('template-shell').style.aspectRatio = definition.canvas.width + ' / ' + definition.canvas.height;
        preview.style.aspectRatio = definition.canvas.width + ' / ' + definition.canvas.height;
        preview.style.backgroundColor = definition.canvas.background || '#fff';
        preview.style.backgroundImage = definition.background ? `url("${definition.background}")` : '';
        const slotShots = session.selected.length
            ? session.selected.map((id) => session.shots.find((shot) => shot.id === id)).filter(Boolean)
            : session.shots;
        definition.slots.forEach((slot, index) => {
            const button = document.createElement('button');
            button.type = 'button';
            button.className = 'sgu-session__slot';
            button.style.left = (slot.x / definition.canvas.width) * 100 + '%';
            button.style.top = (slot.y / definition.canvas.height) * 100 + '%';
            button.style.width = (slot.width / definition.canvas.width) * 100 + '%';
            button.style.height = (slot.height / definition.canvas.height) * 100 + '%';
            const shot = slotShots[index];
            button.disabled = !selectable || !shot;
            button.setAttribute('aria-label', 'Ô ảnh ' + (index + 1));
            button.setAttribute('aria-pressed', String(selectable && retakeSlotIndex === index));
            if (shot) {
                const image = document.createElement('img');
                button.dataset.imageState = 'loading';
                image.onload = () => {
                    button.dataset.imageState = 'ready';
                };
                image.onerror = () => {
                    button.dataset.imageState = 'error';
                    element('message').textContent =
                        'Không tải được thumbnail cho ô ' +
                        (index + 1) +
                        '. Ảnh gốc vẫn được giữ; hãy kiểm tra phiên và endpoint ảnh.';
                };
                image.src = shot.thumbnail;
                image.alt = 'Ảnh trong ô ' + (index + 1);
                image.style.objectFit = slot.fit || 'cover';
                image.style.objectPosition = slotPosition(slot.position);
                image.style.transform = slot.rotation ? `rotate(${slot.rotation}deg)` : '';
                button.append(image);
            } else {
                const label = document.createElement('span');
                label.textContent = 'PHOTO ' + (index + 1);
                button.append(label);
            }
            if (selectable && shot) {
                button.addEventListener('click', () => {
                    retakeSlotIndex = index;
                    renderTemplate(true);
                    element('retake').disabled = false;
                    element('message').textContent =
                        'Đã chọn ô ' + (index + 1) + '. Nhấn Chụp lại để thay đúng ảnh này.';
                });
            }
            preview.append(button);
        });
        if (definition.overlay) {
            const overlay = document.createElement('img');
            overlay.className = 'sgu-session__template-overlay';
            overlay.onerror = () => {
                element('message').textContent = 'Không tải được lớp PNG của khung đã chọn. Vui lòng chọn lại khung.';
            };
            overlay.src = definition.overlay;
            overlay.alt = '';
            preview.append(overlay);
        }
    };
    const showBrowserWorkspace = () => {
        currentSlotIndex = session.shots.length;
        retakeSlotIndex = null;
        setState('preview', currentSlotIndex === 0 ? 'Sẵn sàng chụp' : 'Ảnh đã tự động vào khung');
        element('camera').hidden = false;
        element('camera').append(document.querySelector('.preview'));
        document.getElementById('preview--video').style.display = 'block';
        renderTemplate(false);
        element('capture').hidden = false;
        element('capture').disabled = currentSlotIndex >= session.required;
        element('capture').textContent = currentSlotIndex === 0 ? 'CHỤP' : 'CHỤP TIẾP';
        element('counter').textContent = 'Ảnh ' + currentSlotIndex + ' / ' + session.required;
        element('message').textContent =
            currentSlotIndex === 0
                ? 'Khung sẽ tự nhận ảnh sau mỗi lần chụp.'
                : 'Ảnh vừa chụp đã được gán vào ô ' + currentSlotIndex + '.';
    };
    const createJpeg = (video) =>
        new Promise((resolve, reject) => {
            if (!photoboothPreview.isMediaReady() || !video.videoWidth || !video.videoHeight) {
                reject(new Error('PREVIEW_UNAVAILABLE'));
                return;
            }
            const canvas = document.createElement('canvas');
            canvas.width = video.videoWidth;
            canvas.height = video.videoHeight;
            const context = canvas.getContext('2d');
            if (!context) {
                reject(new Error('CAPTURE_FAILED'));
                return;
            }
            const horizontal = config.preview.flip === 'flip-vertical';
            const vertical = config.preview.flip === 'flip-horizontal';
            context.translate(horizontal ? canvas.width : 0, vertical ? canvas.height : 0);
            context.scale(horizontal ? -1 : 1, vertical ? -1 : 1);
            context.drawImage(video, 0, 0, canvas.width, canvas.height);
            canvas.toBlob((blob) => {
                if (!photoboothPreview.isMediaReady()) {
                    reject(new Error('PREVIEW_UNAVAILABLE'));
                } else if (blob) {
                    resolve(blob);
                } else {
                    reject(new Error('CAPTURE_FAILED'));
                }
            }, 'image/jpeg', 0.92);
        });
    const composeBrowser = async () => {
        selected = session.shots.map((shot) => shot.id);
        setState('composing', 'Đang ghép ảnh của bạn…');
        await request('compose', { selected });
        console.info('[Template] photos assigned to all slots');
        display();
    };
    const captureBrowser = async (slot = null) => {
        const token = ++generation;
        if (!photoboothPreview.isMediaReady()) {
            await photoboothPreview.initializeMedia();
        }
        if (!photoboothPreview.isMediaReady()) {
            throw new Error('PREVIEW_UNAVAILABLE');
        }
        const video = document.getElementById('preview--video');
        setState('countdown', slot === null ? 'Nhìn vào camera nhé!' : 'Chụp lại ô ' + (slot + 1));
        element('camera').hidden = false;
        element('camera').append(document.querySelector('.preview'));
        renderTemplate(false);
        element('countdown').hidden = false;
        console.info('[Capture] countdown started');
        for (let seconds = settings.countdown_seconds; seconds > 0; seconds--) {
            element('countdown').textContent = seconds;
            await pause(1000, token);
            if (!photoboothPreview.isMediaReady()) {
                throw new Error('PREVIEW_UNAVAILABLE');
            }
        }
        if (!photoboothPreview.isMediaReady()) {
            throw new Error('PREVIEW_UNAVAILABLE');
        }
        const blob = await createJpeg(video);
        if (!photoboothPreview.isMediaReady()) {
            throw new Error('PREVIEW_UNAVAILABLE');
        }
        console.info('[Capture] jpeg created');
        setState('capturing', 'Đang lưu JPEG…');
        element('camera').hidden = false;
        renderTemplate(false);
        // The last shot (or a retake) is followed by compose: show the waiting screen now.
        element('waiting').hidden = slot === null && session.shots.length + 1 < session.required;
        root.classList.add('sgu-session--flash');
        if (slot === null) {
            await request('capture', { index: session.shots.length }, blob);
            console.info('[Template] photo assigned to slot ' + session.shots.length);
        } else {
            await request('replace', { slot }, blob);
            console.info('[Template] photo replaced in slot ' + (slot + 1));
        }
        console.info('[Capture] jpeg uploaded');
        root.classList.remove('sgu-session--flash');
        if (session.shots.length === session.required || session.state === 'selecting') {
            await composeBrowser();
        } else {
            showBrowserWorkspace();
        }
    };
    // One press fills every remaining slot: each shot runs its own countdown.
    const captureBrowserSeries = async () => {
        do {
            await captureBrowser();
        } while (session?.state === 'preview' && session.shots.length < session.required);
    };
    const renderSelection = () => {
        setState('selecting', 'Chọn những khoảnh khắc bạn thích');
        element('grid').hidden = false;
        element('compose').hidden = false;
        element('grid').replaceChildren();
        session.shots.forEach((shot, index) => {
            const button = document.createElement('button');
            button.type = 'button';
            button.disabled = session.state === 'composing';
            button.setAttribute('aria-label', 'Ảnh ' + (index + 1));
            const image = document.createElement('img');
            button.dataset.imageState = 'loading';
            image.onload = () => {
                button.dataset.imageState = 'ready';
            };
            image.onerror = () => {
                button.dataset.imageState = 'error';
                element('message').textContent =
                    'Không tải được thumbnail ảnh ' + (index + 1) + '. Ảnh gốc vẫn được giữ trong phiên.';
            };
            image.src = shot.thumbnail; // ONLY the server thumbnail endpoint, never originals.
            image.alt = 'Ảnh ' + (index + 1);
            button.append(image);
            const order = document.createElement('span');
            button.append(order);
            const update = () => {
                const position = selected.indexOf(shot.id);
                button.setAttribute('aria-pressed', String(position !== -1));
                order.textContent = position === -1 ? String(index + 1) : '✓ ' + (position + 1);
            };
            button.addEventListener('click', () => {
                if (busy || booth.flowState !== 'selecting') {
                    return;
                }
                if (selected.includes(shot.id)) {
                    selected = selected.filter((id) => id !== shot.id);
                } else if (selected.length < session.required) {
                    selected.push(shot.id);
                }
                // Update in place so keyboard focus is retained.
                root.dispatchEvent(new Event('selection-change'));
            });
            root.addEventListener('selection-change', update, { signal: selectionEvents.signal });
            update();
            element('grid').append(button);
        });
        updateCounter();
    };
    let selectionEvents = new AbortController();
    const updateCounter = () => {
        element('counter').textContent = 'Đã chọn ' + selected.length + ' / ' + session.required;
        element('compose').disabled = selected.length !== session.required;
    };
    root.addEventListener('selection-change', updateCounter);
    const exit = () =>
        guarded(async () => {
            await request('cancel');
            generation++;
            clearTimeout(completionTimer);
            selected = [];
            selectionEvents.abort();
            root.hidden = true;
            booth.takingPic = false;
            booth.reset();
            if (typeof templateSelection !== 'undefined') {
                templateSelection.showSelection();
            } else {
                document.querySelector('[data-stage="start"]').classList.add('stage--active');
            }
            booth.resetTimeOut();
            booth.screensaver.resetTimer();
        });
    const display = () => {
        recovering = false;
        if (!session) {
            return;
        }
        if ((session.state === 'selecting' || session.state === 'composing') && browserMode()) {
            setState('composing', 'Đang hoàn tất ảnh ghép…');
            element('message').textContent = 'Ảnh đã đủ các ô. Đang tạo preview cuối.';
        } else if (session.state === 'selecting' || session.state === 'composing') {
            selected = session.selected.length ? session.selected.slice() : selected;
            selectionEvents.abort();
            selectionEvents = new AbortController();
            renderSelection();
        } else if (['final-preview', 'confirmed', 'sheet-preview', 'complete'].includes(session.state)) {
            const complete = session.state === 'complete';
            const confirmed = session.state === 'confirmed' || session.confirmed === true || complete;
            const sheetReady =
                (session.state === 'sheet-preview' || complete) && session.sheet_format === printSheet.format;
            setState(
                complete ? 'complete' : sheetReady ? 'sheet-preview' : 'final-preview',
                complete
                    ? 'Đã gửi lệnh in'
                    : sheetReady
                      ? 'Tờ in ' + printSheet.label + ' đã sẵn sàng'
                      : confirmed
                        ? 'JPEG cuối cùng đã sẵn sàng'
                        : 'Xem trước ảnh hoàn chỉnh'
            );
            showPhoto(
                sheetReady ? session.sheet : session.final,
                sheetReady ? 'Tờ in ' + printSheet.label + ' gồm hai khung ảnh' : 'Ảnh JPEG cuối cùng',
                true,
                sheetReady ? printSheetAspect : null
            );
            retakeSlotIndex = null;
            if (!sheetReady) {
                renderTemplate(browserMode() && !confirmed);
            }
            if (browserMode() && !sheetReady) {
                element('retake').hidden = confirmed;
                element('retake').disabled = true;
            }
            element('confirm').hidden = confirmed;
            element('duplicate').hidden = !confirmed || sheetReady || pairPending;
            element('second').hidden = !confirmed || sheetReady || pairPending;
            element('print').hidden = complete || !sheetReady || !config.print.from_result;
            element('download').hidden = !confirmed;
            element('download').href = confirmed ? (sheetReady ? session.sheet : session.final) : '';
            element('download').textContent = sheetReady ? 'Tải JPEG ' + printSheet.label : 'Tải JPEG';
            element('message').textContent = complete
                ? 'Vui lòng nhận ảnh tại máy in. Cảm ơn bạn đã lưu lại khoảnh khắc tại SGU!'
                : sheetReady
                  ? 'Trang ' +
                    printSheet.label +
                    ' gồm hai khung đã sẵn sàng. Kiểm tra preview rồi gửi đúng file này đến máy in.'
                  : confirmed
                    ? pairPending
                        ? 'Đang ghép khung thứ nhất và khung thứ hai thành một trang ' + printSheet.label + '…'
                        : 'Ảnh đã hoàn tất. Chọn nhân đôi ảnh này hoặc chụp thêm một khung để tạo trang in ' +
                          printSheet.label +
                          '.'
                    : '';
            if (confirmed) {
                void queueAutoSave(session.final);
            }
            if (sheetReady) {
                void queueAutoSave(session.sheet);
            }
            if (complete) {
                clearTimeout(completionTimer);
                const token = generation;
                completionTimer = setTimeout(() => {
                    if (token === generation && booth.flowState === 'complete') {
                        exit();
                    }
                }, settings.complete_seconds * 1000);
            }
        } else if (session.state === 'preview') {
            if (browserMode()) {
                showBrowserWorkspace();
            } else if (session.shots.length === 0) {
                returnToStart();
            } else {
                setState('preview', 'Tiếp tục lượt chụp');
                element('camera').hidden = false;
                element('camera').append(document.querySelector('.preview'));
                document.getElementById('preview--video').style.display = 'block';
                if (!photoboothPreview.isMediaReady()) {
                    photoboothPreview.initializeMedia();
                }
                element('resume').hidden = false;
                element('message').textContent =
                    'Đã giữ ' + session.shots.length + ' ảnh. Kiểm tra preview rồi tiếp tục.';
            }
        } else {
            fail(new Error(session.state.startsWith('print') ? 'PRINT_UNCERTAIN' : 'SESSION_FAILED'));
            if (session.state.startsWith('captur')) {
                element('message').textContent =
                    'Lượt chụp trước bị gián đoạn. Không tự chụp lại để tránh trùng ảnh. Nhờ người vận hành kiểm tra camera.';
            }
        }
    };
    const captureSeries = async () => {
        const token = ++generation;
        while (session.state === 'preview') {
            if (!photoboothPreview.isMediaReady()) {
                await photoboothPreview.initializeMedia();
            }
            if (!photoboothPreview.isMediaReady()) {
                throw new Error('PREVIEW_UNAVAILABLE');
            }
            if (config.windows_agent?.enabled && !(await photoboothPhotoCamera.check())) {
                throw new Error(usbCameraError());
            }
            if (!sguKiosk.isReady()) {
                throw new Error('DEVICE_NOT_READY');
            }
            setState('preview', 'Nhìn vào camera nhé!');
            element('counter').textContent = 'Ảnh ' + (session.shots.length + 1) + ' / ' + session.target;
            element('camera').hidden = false;
            element('camera').append(document.querySelector('.preview'));
            renderTemplate(false);
            const video = document.getElementById('preview--video');
            video.style.display = 'block';
            // Status events may reparent the preview; SGU kiosk knows this slot.
            booth.setFlowState('countdown');
            root.dataset.state = 'countdown';
            element('exit').disabled = true;
            element('countdown').hidden = false;
            for (let seconds = settings.countdown_seconds; seconds > 0; seconds--) {
                element('countdown').textContent = seconds;
                await pause(1000, token);
                if (!photoboothPreview.isMediaReady()) {
                    throw new Error('PREVIEW_UNAVAILABLE');
                }
            }
            const data = { index: session.shots.length };
            if (settings.require_usb_capture !== false && !config.dev.demo_images && !config.windows_agent?.enabled) {
                throw new Error('AGENT_NOT_CONFIGURED');
            }
            if (!config.windows_agent?.enabled && !config.dev.demo_images && config.preview.camTakesPic) {
                const canvas = document.createElement('canvas');
                canvas.width = video.videoWidth;
                canvas.height = video.videoHeight;
                const context = canvas.getContext('2d');
                // Match the existing preview flip convention.
                const horizontal = config.preview.flip === 'flip-vertical';
                const vertical = config.preview.flip === 'flip-horizontal';
                context.translate(horizontal ? canvas.width : 0, vertical ? canvas.height : 0);
                context.scale(horizontal ? -1 : 1, vertical ? -1 : 1);
                context.drawImage(video, 0, 0);
                data.canvas = canvas.toDataURL('image/jpeg', settings.jpeg_quality / 100);
            }
            setState('capturing', 'Đang nhận ảnh từ camera…');
            element('camera').hidden = false;
            renderTemplate(false);
            root.classList.add('sgu-session--flash');
            await request('capture', data);
            root.classList.remove('sgu-session--flash');
            setState('shot-preview', 'Khoảnh khắc vừa chụp');
            showPhoto(session.shots[session.shots.length - 1].thumbnail, 'Ảnh vừa chụp');
            await pause(settings.shot_preview_ms, token);
        }
        display();
    };
    element('exit').addEventListener('click', exit);
    element('refresh').addEventListener('click', () =>
        guarded(async () => {
            await request('status');
            display();
        })
    );
    element('resume').addEventListener('click', () => guarded(captureSeries));
    element('capture').addEventListener('click', () => guarded(captureBrowserSeries));
    element('retake').addEventListener('click', () =>
        guarded(async () => {
            if (!browserMode() || retakeSlotIndex === null || session.state !== 'final-preview') {
                return;
            }
            const slot = retakeSlotIndex;
            await captureBrowser(slot);
        })
    );
    element('reconnect').addEventListener('click', () =>
        guarded(async () => {
            await photoboothPreview.retryMedia();
            await sguKiosk.loadPreflight();
            if (config.windows_agent?.enabled) {
                await photoboothPhotoCamera.check();
            }
            await request('status');
            display();
        })
    );
    element('compose').addEventListener('click', () =>
        guarded(async () => {
            if (booth.flowState !== 'selecting' || selected.length !== session.required) {
                return;
            }
            setState('composing', 'Đang ghép ảnh của bạn…');
            await request('compose', { selected });
            display();
        })
    );
    element('confirm').addEventListener('click', () =>
        guarded(async () => {
            if (booth.flowState !== 'final-preview' || session.confirmed === true) {
                return;
            }
            element('confirm').disabled = true;
            await request('confirm');
            if (pairPending) {
                setState('composing', 'Đang tạo trang in ' + printSheet.label + '…');
                await request('pair_finish');
            }
            display();
        })
    );
    element('duplicate').addEventListener('click', () =>
        guarded(async () => {
            if (booth.flowState !== 'final-preview' || session.confirmed !== true || pairPending) {
                return;
            }
            setState('composing', 'Đang tạo trang in ' + printSheet.label + '…');
            await request('pair_duplicate');
            display();
        })
    );
    element('second').addEventListener('click', () =>
        guarded(async () => {
            if (booth.flowState !== 'final-preview' || session.confirmed !== true || pairPending) {
                return;
            }
            await request('pair_hold');
            generation++;
            clearTimeout(completionTimer);
            selected = [];
            selectionEvents.abort();
            root.hidden = true;
            booth.takingPic = false;
            booth.reset();
            if (typeof templateSelection !== 'undefined') {
                templateSelection.showError(
                    'Đã giữ khung thứ nhất. Chọn lại khung hiện tại hoặc chọn một khung khác để chụp khung thứ hai.'
                );
                templateSelection.showSelection();
            }
            booth.resetTimeOut();
            booth.screensaver.resetTimer();
        })
    );
    element('print').addEventListener('click', () =>
        guarded(async () => {
            if (booth.flowState !== 'sheet-preview' || session.confirmed !== true || !session.sheet) {
                return;
            }
            element('print').disabled = true;
            setState('printing', 'Đang gửi ảnh đến máy in…');
            showPhoto(session.sheet, 'Trang ' + printSheet.label + ' đang được gửi in', false, printSheetAspect);
            await request('print');
            display();
        })
    );
    const chooseSaveDirectory = async () => {
        if (!canChooseDirectory || autoSaveBusy || directoryPickerBusy) {
            return;
        }
        directoryPickerBusy = true;
        setSaveButtonsDisabled(true);
        try {
            directoryHandle = await window.showDirectoryPicker({ id: 'sgu-photobooth-auto-save', mode: 'readwrite' });
            setSaveButtonLabel('Đổi nơi lưu ảnh');
            setSaveStatus('Đã chọn thư mục ' + directoryHandle.name + '. Ảnh hoàn tất sẽ tự lưu tại đây.');
            if (session?.confirmed) {
                await queueAutoSave(session.final);
                if (session.sheet) {
                    await queueAutoSave(session.sheet);
                }
            }
        } catch (error) {
            if (error?.name !== 'AbortError') {
                setSaveStatus('Không thể mở thư mục. Hãy thử lại bằng Chrome/Edge trên localhost hoặc HTTPS.');
            }
        } finally {
            directoryPickerBusy = false;
            setSaveButtonsDisabled(!canChooseDirectory || autoSaveBusy);
        }
    };
    saveButtons.forEach((button) => button.addEventListener('click', chooseSaveDirectory));
    if (!canChooseDirectory) {
        setSaveButtonsDisabled(true);
        setSaveStatus('Trình duyệt này không hỗ trợ tự lưu vào thư mục đã chọn; hãy dùng nút Tải JPEG.');
    } else {
        setSaveStatus('Chọn thư mục một lần; mỗi ảnh hoàn tất sẽ tự lưu vào đó.');
    }
    pairCancelButton?.addEventListener('click', () =>
        guarded(async () => {
            await request('pair_cancel');
            if (typeof templateSelection !== 'undefined') {
                templateSelection.showError('Đã hủy ảnh thứ nhất. Bạn có thể bắt đầu một lượt chụp mới.');
                templateSelection.showSelection();
            }
        })
    );
    return {
        prepare: async (templateId) => {
            if (busy || !templateId) {
                return false;
            }
            busy = true;
            try {
                if (session && session.template_id !== templateId) {
                    await request('cancel');
                    session = null;
                }
                await request('start', { template_id: templateId });

                return true;
            } catch (error) {
                fail(error);

                return false;
            } finally {
                busy = false;
            }
        },
        start: () =>
            guarded(async () => {
                if (booth.takingPic || recovering) {
                    return;
                }
                booth.takingPic = true;
                booth.resetTimeOut();
                booth.screensaver.hide();
                setState('preview', 'Đang mở lượt chụp…');
                const templateId =
                    typeof templateSelection !== 'undefined' ? templateSelection.getSelectedTemplateId() : '';
                if (typeof templateSelection !== 'undefined' && templateId === '') {
                    throw new Error('TEMPLATE_REQUIRED');
                }
                await request('start', templateId === '' ? {} : { template_id: templateId });
                // An empty session created before the operator switched capture mode
                // is recreated, so CamLink capture never runs the USB flow (or vice versa).
                if (
                    session.capture_mode &&
                    session.capture_mode !== settings.capture_mode &&
                    session.shots.length === 0
                ) {
                    await request('cancel');
                    await request('start', templateId === '' ? {} : { template_id: templateId });
                }
                if (session.state === 'preview' && browserMode()) {
                    await captureBrowserSeries();
                } else if (session.state === 'preview') {
                    await captureSeries();
                } else {
                    display();
                }
            }),
        restore: () =>
            guarded(async () => {
                await request('status');
                if (session) {
                    booth.takingPic = true;
                    booth.resetTimeOut();
                    booth.screensaver.hide();
                    if (session.state === 'confirmed' && pairPending) {
                        setState('composing', 'Đang tạo trang in ' + printSheet.label + '…');
                        await request('pair_finish');
                        display();
                    } else if (
                        browserMode() &&
                        session.state === 'selecting' &&
                        session.shots.length === session.required
                    ) {
                        await composeBrowser();
                    } else {
                        display();
                    }
                } else if (pairPending && typeof templateSelection !== 'undefined') {
                    templateSelection.showError(
                        'Đang giữ khung thứ nhất. Chọn khung để chụp tiếp, hoặc bấm Hủy ảnh thứ nhất để bắt đầu lại.'
                    );
                    templateSelection.showSelection();
                }
            })
    };
};

/* eslint n/no-unsupported-features/node-builtins: "off" */
/* globals photoboothPreview photoBooth photoboothPhotoCamera */

/**
 * SGU kiosk presentation controller.
 *
 * It consumes real browser preview events and the small read-only server
 * preflight. It intentionally does not claim that a configured tether command
 * has found a physical camera; that verification remains an operator test.
 */
const sguKiosk = (function () {
    const States = {
        IDLE: 'idle',
        PREVIEW: 'preview',
        COUNTDOWN: 'countdown',
        CAPTURING: 'capturing',
        TRANSFERRING: 'transferring',
        SHOT_PREVIEW: 'shot-preview',
        SELECTING: 'selecting',
        COMPOSING: 'composing',
        FINAL_PREVIEW: 'final-preview',
        PRINTING: 'printing',
        COMPLETE: 'complete',
        ERROR: 'error'
    };

    const stateLabels = {
        [States.IDLE]: 'Máy đang sẵn sàng',
        [States.PREVIEW]: 'Đang mở xem trước',
        [States.COUNTDOWN]: 'Chuẩn bị chụp',
        [States.CAPTURING]: 'Đang chụp ảnh',
        [States.TRANSFERRING]: 'Đang nhận JPEG từ máy ảnh',
        [States.SHOT_PREVIEW]: 'Đang kiểm tra ảnh',
        [States.SELECTING]: 'Chọn ảnh để tiếp tục',
        [States.COMPOSING]: 'Đang xử lý ảnh',
        [States.FINAL_PREVIEW]: 'Ảnh đã sẵn sàng',
        [States.PRINTING]: 'Đang in ảnh',
        [States.COMPLETE]: 'Hoàn tất',
        [States.ERROR]: 'Cần kiểm tra thiết bị'
    };

    const api = {};
    let previewReady = false;
    let initialized = false;
    let preflightState = 'checking';
    let preflightRequest = null;
    let previewContainer;
    let previewAnchor;
    let previewSlot;
    let flowState = States.IDLE;
    let preflight = {
        captureSource: 'command',
        captureBackendConfigured: false,
        captureBackendReady: null,
        storageWritable: false,
        printerRequired: false,
        printerReady: null
    };

    const getStatusElements = () => document.querySelectorAll('[data-sgu-camera-status]');
    const getStatusTexts = () => document.querySelectorAll('[data-sgu-camera-status-text]');
    const getFlowStatus = () => document.querySelectorAll('[data-sgu-flow-status]');
    const getPreflightLabels = () => document.querySelectorAll('[data-sgu-preflight]');
    const getStartButtons = () =>
        document.querySelectorAll(
            '.stage--sgu .takePic, .stage--sgu .takeCollage, .stage--sgu .takeCustom, .stage--sgu .takeVideo'
        );
    const templateSelectionActive = () =>
        document.querySelector('[data-template-selection]')?.classList.contains('stage--active') === true;
    const cameraReady = () => previewReady && photoboothPreview.isMediaReady();

    const showWelcomePreview = () => {
        if (!previewContainer || !previewSlot) {
            return;
        }
        const idle = flowState === States.IDLE;
        if (idle) {
            previewSlot.append(previewContainer);
        } else if (
            config.sgu.session_enabled &&
            document.querySelector('[data-session-camera]') &&
            ['preview', 'countdown', 'capturing'].includes(flowState)
        ) {
            document.querySelector('[data-session-camera]').append(previewContainer);
        } else if (
            ['preview', 'countdown', 'capturing'].includes(flowState) &&
            document.querySelector('[data-sgu-capture-preview]')
        ) {
            document.querySelector('[data-sgu-capture-preview]').append(previewContainer);
        } else {
            previewAnchor.after(previewContainer);
        }
        previewSlot.hidden = !idle;
        previewSlot.dataset.ready = String(cameraReady());
        const captureSlot = document.querySelector('[data-sgu-capture-preview]');
        if (captureSlot) {
            captureSlot.hidden =
                !!config.sgu.session_enabled || !['preview', 'countdown', 'capturing'].includes(flowState);
        }
        if (idle && cameraReady()) {
            document.getElementById('preview--video').style.display = 'block';
        }
    };

    api.isReady = () => {
        const printerReady = !preflight.printerRequired || preflight.printerReady === true;
        const captureAvailable = preflight.captureBackendConfigured && preflight.captureBackendReady !== false;
        const browserCapture = config.sgu.capture_mode === 'browser';
        const usbRequired = !browserCapture && config.sgu.require_usb_capture !== false && !config.dev.demo_images;
        const photoStatus = typeof photoboothPhotoCamera !== 'undefined' ? photoboothPhotoCamera.status : {};
        const agentOnline =
            photoStatus.agent_online === true ||
            (photoStatus.agent_online === undefined && photoStatus.success === true);
        const cameraConnected =
            photoStatus.camera_connected === true ||
            (photoStatus.camera_connected === undefined && photoStatus.connected === true);
        const captureReady =
            photoStatus.capture_ready === true ||
            (photoStatus.capture_ready === undefined && photoStatus.success === true && cameraConnected);
        const usbReady =
            browserCapture ||
            (!(config.windows_agent && config.windows_agent.enabled) && !usbRequired) ||
            (photoStatus.success === true && agentOnline && cameraConnected && captureReady);
        return cameraReady() && usbReady && preflight.storageWritable && captureAvailable && printerReady;
    };

    // Explains why the USB half blocks Start; the preview half has its own help text.
    const usbBlockReason = () => {
        if (
            config.sgu.capture_mode === 'browser' ||
            !(config.windows_agent && config.windows_agent.enabled) ||
            typeof photoboothPhotoCamera === 'undefined'
        ) {
            return '';
        }
        const status = photoboothPhotoCamera.status;
        if (status.capture_ready === true) {
            return '';
        }
        if (status.agent_online === undefined) {
            return 'Đang kiểm tra máy ảnh USB';
        }
        if (status.agent_online !== true) {
            return 'Windows Camera Agent chưa chạy — nhờ người vận hành khởi động agent';
        }
        if (status.camera_busy === true) {
            return 'Máy ảnh USB đang bận';
        }
        if (status.camera_connected !== true) {
            return 'Máy ảnh USB chưa kết nối — bật PC Remote (USB) trên máy ảnh';
        }
        return 'Máy ảnh USB chưa sẵn sàng chụp';
    };

    const updateStartAvailability = () => {
        const ready = api.isReady();
        getStartButtons().forEach((button) => {
            button.disabled = !ready;
            button.setAttribute('aria-disabled', String(!ready));
        });
        getPreflightLabels().forEach((label) => {
            if (preflightState === 'checking') {
                label.textContent = 'Đang kiểm tra bộ nhớ lưu ảnh';
            } else if (preflightState === 'error') {
                label.textContent = 'Không thể kiểm tra hệ thống chụp. Hãy thử lại.';
            } else if (!preflight.storageWritable) {
                label.textContent = 'Bộ nhớ lưu ảnh chưa sẵn sàng';
            } else if (
                config.sgu.capture_mode !== 'browser' &&
                preflight.usbRequired &&
                !config.windows_agent.enabled
            ) {
                label.textContent = 'Cần cấu hình Windows Camera Agent để chụp USB';
            } else if (!preflight.captureBackendConfigured) {
                label.textContent = 'Hệ thống chụp ảnh chưa được cấu hình';
            } else if (preflight.captureBackendReady === false) {
                label.textContent = 'Hệ thống chụp ảnh chưa sẵn sàng';
            } else if (preflight.printerRequired && preflight.printerReady !== true) {
                label.textContent = 'Chưa xác nhận máy in sẵn sàng';
            } else {
                label.textContent = ready
                    ? 'Có thể bắt đầu chụp'
                    : usbBlockReason() || 'Kết nối camera để bắt đầu';
            }
        });
        document.querySelectorAll('[data-sgu-capture-status]').forEach((element) => {
            if (preflightState === 'checking') {
                element.textContent = 'Đang kiểm tra hệ thống chụp ảnh';
            } else if (preflightState === 'error') {
                element.textContent = 'Chưa kiểm tra được hệ thống chụp ảnh';
            } else if (
                config.sgu.capture_mode !== 'browser' &&
                preflight.usbRequired &&
                !config.windows_agent.enabled
            ) {
                element.textContent = 'Photo Camera: Disconnected • Chưa bật Windows Camera Agent / Sony SDK bridge.';
            } else if (!preflight.captureBackendConfigured) {
                element.textContent = 'Chụp ảnh: chưa được cấu hình';
            } else if (preflight.captureSource === 'browser') {
                element.textContent = cameraReady() ? 'Chụp từ CamLink: sẵn sàng' : 'Chụp từ CamLink: chờ xem trước';
            } else if (preflight.captureSource === 'demo') {
                element.textContent = 'Chụp ảnh: đang dùng chế độ ảnh mẫu';
            } else {
                element.textContent =
                    preflight.captureBackendReady === true
                        ? 'Chụp JPEG qua USB: sẵn sàng'
                        : 'Chụp JPEG qua USB: đã cấu hình, chưa xác nhận thiết bị';
            }
        });
    };

    const setCameraStatus = (status) => {
        const state = status.state || 'checking';
        const messages = {
            checking: ['Đang kiểm tra camera', 'Đang kiểm tra quyền truy cập camera…'],
            'permission-required': ['Cần quyền truy cập camera', 'Cho phép truy cập camera để bắt đầu.'],
            'permission-denied': [
                'Quyền camera đang bị chặn',
                'Cho phép Camera trong cài đặt trang web của trình duyệt, rồi thử lại.'
            ],
            connecting: ['Đang kết nối camera…', 'Chọn Cho phép / Allow nếu trình duyệt đang hỏi quyền camera.'],
            ready: [
                `Live Preview: Connected${status.label ? ` • ${status.label}` : ''}${status.resolution ? ` • ${status.resolution}` : ''}`,
                'Xem trước trực tiếp từ camera.'
            ],
            disconnected: [
                'Mất kết nối camera',
                'Kiểm tra dây USB. Máy sẽ thử kết nối lại; bạn cũng có thể bấm Thử kết nối lại.'
            ],
            busy: [
                'Camera đang bận hoặc chưa sẵn sàng',
                'Đóng Windows Camera, OBS, Teams, Zoom, Discord hoặc tab khác đang dùng camera, rồi thử lại.'
            ],
            'not-found': ['Không tìm thấy thiết bị camera', 'Kiểm tra CamLink và dây HDMI/USB, rồi thử lại.'],
            unsupported: [
                'Không thể truy cập camera',
                'Mở bằng http://localhost/ hoặc HTTPS hợp lệ trong Chrome/Edge có hỗ trợ camera.'
            ],
            'configuration-required': [
                'Xem trước camera chưa được bật',
                'Bật xem trước từ camera thiết bị trong phần Cài đặt.'
            ],
            stopped: ['Camera đang tạm dừng', 'Camera sẽ kết nối lại khi trở về màn hình bắt đầu.'],
            error: ['Không thể mở camera', 'Kiểm tra thiết bị rồi bấm Thử kết nối lại.']
        };
        const [label, help] = messages[state] || messages.error;
        previewReady = state === 'ready' && photoboothPreview.isMediaReady();
        getStatusElements().forEach((element) => {
            element.dataset.state = state;
        });
        getStatusTexts().forEach((element) => {
            element.textContent = previewReady ? label : 'Live Preview: Disconnected • ' + label;
        });
        document.querySelectorAll('[data-sgu-camera-help]').forEach((element) => {
            element.textContent = help;
            element.hidden = previewReady;
        });
        document.querySelectorAll('[data-sgu-reconnect]').forEach((button) => {
            button.textContent =
                state === 'permission-required'
                    ? 'Cho phép Cam Link'
                    : state === 'ready'
                      ? 'Kết nối lại Cam Link'
                      : 'Thử kết nối lại Cam Link';
            button.disabled = ['checking', 'connecting'].includes(state);
            button.setAttribute('aria-disabled', String(button.disabled));
        });
        showWelcomePreview();
        updateStartAvailability();
    };

    api.setFlowState = (state) => {
        flowState = Object.values(States).includes(state) ? state : States.ERROR;
        document.body.dataset.sguState = flowState;
        getFlowStatus().forEach((element) => {
            element.textContent = stateLabels[flowState];
        });
        showWelcomePreview();
        if (
            flowState === States.IDLE &&
            !templateSelectionActive() &&
            photoboothPreview.previewStatus.state === 'stopped'
        ) {
            photoboothPreview.initializeMedia();
        }
    };

    // Called directly by the user’s “Tiếp tục” action after selecting a frame.
    // This preserves the existing permission and reconnect implementation while
    // keeping getUserMedia out of the initial template screen.
    // core reset() hides the video element while the stream stays live; an
    // already-open stream emits no new status event, so re-show it here.
    api.activatePreview = () =>
        photoboothPreview.initializeMedia().then((ready) => {
            showWelcomePreview();
            return ready;
        });

    api.loadPreflight = () => {
        if (preflightRequest) {
            return preflightRequest;
        }
        preflightState = 'checking';
        preflightRequest = (async () => {
            try {
                const response = await fetch('api/sguPreflight.php', {
                    credentials: 'same-origin',
                    cache: 'no-store',
                    headers: { Accept: 'application/json' }
                });
                if (!response.ok) {
                    throw new Error('Preflight request failed');
                }
                preflight = Object.assign(preflight, await response.json());
                preflightState = 'ready';
            } catch {
                preflight.captureBackendConfigured = false;
                preflight.storageWritable = false;
                preflightState = 'error';
            } finally {
                preflightRequest = null;
                updateStartAvailability();
            }
        })();
        return preflightRequest;
    };

    api.reconnect = () => {
        api.loadPreflight();
        return photoboothPreview.retryMedia();
    };

    api.init = () => {
        if (initialized) {
            return;
        }
        initialized = true;
        previewContainer = document.querySelector('.preview');
        previewSlot = document.querySelector('[data-sgu-preview-slot]');
        if (previewContainer && previewSlot) {
            previewAnchor = document.createComment('preview home position');
            previewContainer.before(previewAnchor);
        }
        document.addEventListener('photobooth.preview.status', (event) => setCameraStatus(event.detail));
        document.addEventListener('photobooth.flow.state', (event) => api.setFlowState(event.detail.state));
        document.addEventListener('photobooth.photo-camera.status', updateStartAvailability);
        document.querySelectorAll('[data-sgu-reconnect]').forEach((button) => {
            button.addEventListener('click', api.reconnect);
        });
        api.setFlowState(typeof photoBooth !== 'undefined' ? photoBooth.flowState : States.IDLE);
        api.loadPreflight();
        setCameraStatus(photoboothPreview.previewStatus);
        if (!templateSelectionActive()) {
            photoboothPreview.initializeMedia();
        }
    };

    return api;
})();

// Register after preview/core's jQuery-ready callbacks to avoid an idle/ready race.
$(function () {
    sguKiosk.init();
});

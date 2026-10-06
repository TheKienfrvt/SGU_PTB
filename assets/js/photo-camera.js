/* eslint n/no-unsupported-features/node-builtins: "off" */
/* globals photoboothPreview photoBooth */

const photoboothPhotoCamera = (function () {
    const api = { status: { connected: false }, pending: false };
    let progressTimer;
    let progressGeneration = 0;
    let initialized = false;
    let healthTimer;
    let healthPolling = false;
    const enabled = () => !!(config.windows_agent && config.windows_agent.enabled);

    function publish(status) {
        const agentOnline =
            status.agent_online === true || (status.agent_online === undefined && status.success === true);
        const cameraConnected =
            status.success === true && (status.camera_connected === true || status.connected === true);
        const captureReady =
            status.success === true && cameraConnected && (status.capture_ready === undefined || status.capture_ready === true);
        const model = status.camera_model || (status.camera && status.camera.model) || '';
        status = {
            ...status,
            agent_online: agentOnline,
            connected: cameraConnected,
            camera_connected: cameraConnected,
            camera_model: model || null,
            camera_busy: status.camera_busy === true,
            capture_ready: captureReady,
            last_error: status.last_error || status.error_code || null
        };
        api.status = status;
        document.querySelectorAll('[data-windows-agent-status]').forEach((element) => {
            element.dataset.state = agentOnline ? 'ready' : 'disconnected';
            element.textContent = agentOnline
                ? 'Windows Agent: Online'
                : 'Windows Agent: Offline • ' + (status.error || 'Không kết nối được dịch vụ camera trên Windows.');
        });
        document.querySelectorAll('[data-photo-camera-status]').forEach((element) => {
            element.dataset.state = cameraConnected ? 'ready' : 'disconnected';
            element.textContent = cameraConnected
                ? 'Photo Camera: Connected • ' + (model || 'USB')
                : 'Photo Camera: Disconnected • ' +
                  (agentOnline
                      ? status.error || 'Máy ảnh chưa sẵn sàng qua USB/PC Remote.'
                      : 'Đang chờ Windows Agent.');
        });
        document.querySelectorAll('[data-photo-capture-status]').forEach((element) => {
            element.dataset.state = captureReady ? 'ready' : status.camera_busy ? 'busy' : 'disconnected';
            element.textContent = captureReady
                ? 'Capture: Ready'
                : status.camera_busy
                  ? 'Capture: Not Ready • Máy ảnh đang bận.'
                  : 'Capture: Not Ready';
        });
        document.querySelectorAll('[data-photo-camera-reconnect]').forEach((button) => {
            button.hidden = captureReady;
        });
        document.dispatchEvent(new CustomEvent('photobooth.photo-camera.status', { detail: status }));
    }

    async function json(path) {
        const controller = new AbortController();
        const timer = setTimeout(() => controller.abort(), 11000);
        try {
            const response = await fetch(environment.publicFolders.api + path, {
                cache: 'no-store',
                credentials: 'same-origin',
                signal: controller.signal
            });
            if (!response.ok) {
                throw new Error('Camera request failed');
            }
            return await response.json();
        } finally {
            clearTimeout(timer);
        }
    }

    api.check = async () => {
        if (!enabled()) {
            if (config.sgu?.enabled && config.sgu.require_usb_capture !== false && !config.dev?.demo_images) {
                publish({
                    success: false,
                    connected: false,
                    error_code: 'AGENT_NOT_CONFIGURED',
                    error: 'Chưa bật Windows Camera Agent.'
                });
                return false;
            }
            return true;
        }
        if (api.pending) {
            return false;
        }
        api.pending = true;
        try {
            const status = await json('/cameraStatus.php');
            publish(status);
            return api.status.capture_ready === true;
        } catch {
            publish({
                success: false,
                agent_online: false,
                connected: false,
                camera_connected: false,
                capture_ready: false,
                error_code: 'AGENT_UNREACHABLE',
                last_error: 'AGENT_UNREACHABLE',
                error: 'Không kết nối được dịch vụ điều khiển camera trên Windows.'
            });
            return false;
        } finally {
            api.pending = false;
        }
    };

    api.captureId = () =>
        Array.from(crypto.getRandomValues(new Uint8Array(16)), (byte) => byte.toString(16).padStart(2, '0')).join('');

    api.stopProgress = () => {
        progressGeneration += 1;
        clearTimeout(progressTimer);
    };

    api.watchProgress = (id) => {
        api.stopProgress();
        const generation = progressGeneration;
        const poll = async () => {
            try {
                const progress = await json('/cameraCaptureStatus.php?capture_id=' + id);
                if (generation === progressGeneration && ['transferring', 'complete'].includes(progress.state)) {
                    photoBooth.setFlowState('transferring');
                }
            } catch {
                // Capture response owns errors. Status polling cannot trigger another shutter.
            }
            if (generation === progressGeneration) {
                progressTimer = setTimeout(poll, 1000);
            }
        };
        progressTimer = setTimeout(poll, 1000);
    };

    api.init = () => {
        if (initialized) {
            return;
        }
        initialized = true;
        document.querySelectorAll('[data-photo-camera-reconnect]').forEach((button) => {
            button.addEventListener('click', async () => {
                button.disabled = true;
                try {
                    await api.check();
                } finally {
                    button.disabled = false;
                }
            });
        });
        const select = document.querySelector('[data-preview-device]');
        if (select) {
            const updateDevices = () => {
                const devices = photoboothPreview.getCameras();
                select.replaceChildren();
                devices.forEach((device, index) => {
                    const option = document.createElement('option');
                    option.value = device.deviceId;
                    option.textContent = device.label || 'Camera ' + (index + 1);
                    select.append(option);
                });
                const track = photoboothPreview.stream && photoboothPreview.stream.getVideoTracks()[0];
                if (track) {
                    select.value = track.getSettings().deviceId;
                }
                select.disabled = devices.length < 2 || (typeof photoBooth !== 'undefined' && photoBooth.takingPic);
            };
            select.addEventListener('change', async () => {
                select.disabled = true;
                await photoboothPreview.chooseCamera(select.value);
                updateDevices();
            });
            document.addEventListener('photobooth.preview.status', updateDevices);
            document.addEventListener('photobooth.flow.state', updateDevices);
        }
        if (enabled()) {
            const pollHealth = async () => {
                if (healthPolling) {
                    return;
                }
                healthPolling = true;
                clearTimeout(healthTimer);
                try {
                    if (!document.hidden && !photoBooth.takingPic) {
                        await api.check();
                    }
                } finally {
                    healthPolling = false;
                    healthTimer = setTimeout(pollHealth, 10000);
                }
            };
            document.addEventListener('visibilitychange', () => {
                if (!document.hidden) {
                    pollHealth();
                }
            });
            pollHealth();
        }
    };
    return api;
})();

$(function () {
    photoboothPhotoCamera.init();
});

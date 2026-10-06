/* eslint n/no-unsupported-features/node-builtins: "off" */

/* globals photoBooth photoboothTools */

function getPreviewUrlWithCacheBusting() {
    const url = getBasePreviewUrl();
    const timestamp = new Date().getTime();

    if (url.includes('?')) {
        return `${url}&t=${timestamp}`;
    }

    return `${url}?t=${timestamp}`;
}

function getBasePreviewUrl() {
    if (!config.preview || !config.preview.url) {
        return '';
    }

    const raw = config.preview.url;
    //remove url("") if present
    const match = raw.match(/^url\((['"]?)(.+?)\1\)$/);

    return match ? match[2] : raw;
}

const photoboothPreview = (function () {
    // vars
    const CameraDisplayMode = {
            INIT: 1,
            BACKGROUND: 2,
            COUNTDOWN: 3,
            TEST: 4
        },
        PreviewMode = {
            NONE: 'none',
            DEVICE: 'device_cam',
            URL: 'url'
        },
        api = {};

    let pid,
        video,
        loader,
        url,
        pictureFrame,
        collageFrame,
        connection = null,
        generation = 0,
        connectionGeneration = 0,
        reconnectTimer = null,
        deviceChangeTimer = null,
        mutedTimer = null,
        reconnectAttempts = 0,
        reconnectEnabled = false,
        permission = 'unknown',
        permissionStatus = null,
        sessionGranted = false,
        cancelVideoWait = null,
        cameras = [],
        enumerationSucceeded = false,
        listenersAttached = false;

    const isSgu = () => Boolean(config.sgu && config.sgu.enabled);
    const log = (message, detail = {}) => photoboothTools.console.logDev('Camera: ' + message, detail);
    const stopTracks = (stream) => stream && stream.getTracks().forEach((track) => track.stop());
    const normalizeLabel = (label) =>
        String(label || '')
            .toLowerCase()
            .replace(/[\s_-]+/g, ' ')
            .trim();
    const isPreferredCaptureDevice = (label) =>
        /\b(cam\s*link|elgato|usb\s*video|capture|hdmi)\b/i.test(normalizeLabel(label));

    api.previewStatus = { state: 'checking', permission, label: '', resolution: '', error: null };

    api.isMediaReady = () => {
        const element = video && video.get(0);
        const stream = api.stream;
        const track = stream && stream.getVideoTracks()[0];
        return Boolean(
            stream &&
            stream.active &&
            track &&
            track.readyState === 'live' &&
            !track.muted &&
            element &&
            element.srcObject === stream &&
            !element.paused &&
            element.videoWidth > 0 &&
            element.videoHeight > 0
        );
    };

    api.reportStatus = (state, error = null, reason = '') => {
        const element = video && video.get(0);
        const track = api.stream && api.stream.getVideoTracks()[0];
        api.previewStatus = {
            state,
            permission,
            label: track ? track.label : '',
            resolution: state === 'ready' && element ? `${element.videoWidth}×${element.videoHeight}` : '',
            error: error ? { name: error.name, message: error.message, constraint: error.constraint || '' } : null,
            reason
        };
        log('State', api.previewStatus);
        document.dispatchEvent(new CustomEvent('photobooth.preview.status', { detail: api.previewStatus }));
    };

    const clearReconnect = () => {
        clearTimeout(reconnectTimer);
        reconnectTimer = null;
    };

    const releaseStream = () => {
        clearTimeout(mutedTimer);
        if (cancelVideoWait) {
            cancelVideoWait();
        }
        const stream = api.stream;
        api.stream = null;
        stopTracks(stream);
        const element = video && video.get(0);
        if (element) {
            element.pause();
            element.srcObject = null;
        }
    };

    const enumerateCameras = async () => {
        enumerationSucceeded = false;
        if (!navigator.mediaDevices.enumerateDevices) {
            return [];
        }
        try {
            const devices = await navigator.mediaDevices.enumerateDevices();
            enumerationSucceeded = true;
            cameras = devices.filter((device) => device.kind === 'videoinput');
            log('devices detected', { count: cameras.length, preferredLabel: config.sgu && config.sgu.camera_label });
            if (config.dev.loglevel > 0) {
                console.table(cameras.map(({ label, deviceId, groupId }) => ({ label, deviceId, groupId })));
            }
            return cameras;
        } catch (error) {
            // Enumeration failure must not discard a usable granted stream.
            log('Could not enumerate devices', { name: error.name, message: error.message });
            return [];
        }
    };

    let savedDeviceId = '';
    try {
        savedDeviceId = window.localStorage.getItem('photobooth.preview.deviceId') || '';
    } catch {
        // Browser policy/private browsing can disable persistence.
    }
    api.getCameras = () => cameras.slice();
    api.chooseCamera = (id) => {
        if (!cameras.some((device) => device.deviceId === id)) {
            return Promise.resolve(false);
        }
        savedDeviceId = id;
        try {
            window.localStorage.setItem('photobooth.preview.deviceId', id);
        } catch {
            // The selection still works for the current page.
        }
        return api.retryMedia({ preferCamLink: false });
    };

    const selectCamera = (devices, preferCamLink = false) => {
        const configuredId = isSgu() ? config.sgu.camera_device_id : '';
        const preferredLabel = isSgu() ? normalizeLabel(config.sgu.camera_label) : '';
        const configuredDevice = devices.find((device) => configuredId && device.deviceId === configuredId);
        const labelledDevice = devices.find(
            (device) => preferredLabel && normalizeLabel(device.label).includes(preferredLabel)
        );
        const captureDevice = isSgu() ? devices.find((device) => isPreferredCaptureDevice(device.label)) : null;
        const savedDevice = devices.find((device) => savedDeviceId && device.deviceId === savedDeviceId);
        return (
            configuredDevice ||
            (preferCamLink ? labelledDevice || captureDevice || savedDevice : savedDevice || labelledDevice || captureDevice) ||
            (devices.length === 1 ? devices[0] : null)
        );
    };

    const queryPermission = async () => {
        if (navigator.permissions && navigator.permissions.query) {
            try {
                if (!permissionStatus) {
                    permissionStatus = await navigator.permissions.query({ name: 'camera' });
                    permissionStatus.addEventListener('change', () => {
                        permission = permissionStatus.state;
                        log('Permission changed', { permission });
                        if (permission !== 'granted') {
                            sessionGranted = false;
                            generation += 1;
                            clearReconnect();
                            releaseStream();
                            api.reportStatus(permission === 'denied' ? 'permission-denied' : 'permission-required');
                        } else if (reconnectEnabled && !connection) {
                            api.initializeMedia();
                        }
                    });
                }
                permission = permissionStatus.state;
                return permission;
            } catch (error) {
                log('Permissions API unavailable; using camera access fallback', { name: error.name });
            }
        }
        // A successful getUserMedia call is evidence of permission for this page.
        // On a new page without Permissions API support, labelled inputs are a
        // browser-provided hint of an existing grant; getUserMedia still enforces it.
        if (sessionGranted) {
            return 'granted';
        }
        const devices = await enumerateCameras();
        return devices.some((device) => device.label) ? 'granted' : 'unknown';
    };

    const scheduleReconnect = (reason) => {
        if (!reconnectEnabled || permission !== 'granted' || reconnectTimer || reconnectAttempts >= 3) {
            return;
        }
        const delay = Math.min(1000 * 2 ** reconnectAttempts, 3000);
        reconnectAttempts += 1;
        log('Reconnect scheduled', { reason, delay, attempt: reconnectAttempts });
        reconnectTimer = setTimeout(() => {
            reconnectTimer = null;
            api.initializeMedia();
        }, delay);
    };

    const disconnected = (stream, reason) => {
        if (api.stream !== stream) {
            return;
        }
        generation += 1;
        releaseStream();
        api.reportStatus('disconnected', null, reason);
        scheduleReconnect(reason);
    };

    const waitForVideo = (stream, attempt) =>
        new Promise((resolve, reject) => {
            const element = video.get(0);
            const track = stream.getVideoTracks()[0];
            let played = false;
            let finished = false;
            const events = ['loadedmetadata', 'loadeddata', 'playing', 'resize'];
            const finish = (error) => {
                if (finished) {
                    return;
                }
                finished = true;
                clearTimeout(timeout);
                events.forEach((event) => element.removeEventListener(event, check));
                track.removeEventListener('unmute', check);
                cancelVideoWait = null;
                if (error) {
                    reject(error);
                } else {
                    resolve();
                }
            };
            const check = () => {
                if (generation !== attempt) {
                    finish(new DOMException('Camera request superseded', 'AbortError'));
                } else if (played && api.isMediaReady()) {
                    finish();
                }
            };
            const timeout = setTimeout(
                () => finish(new DOMException('Video did not become ready within 10 seconds', 'TimeoutError')),
                10000
            );
            cancelVideoWait = () => finish(new DOMException('Camera request cancelled', 'AbortError'));
            events.forEach((event) => element.addEventListener(event, check));
            track.addEventListener('unmute', check);
            element.srcObject = stream;
            element.muted = true;
            Promise.resolve()
                .then(() => element.play())
                .then(() => {
                    played = true;
                    check();
                })
                .catch((error) =>
                    finish(
                        error.name === 'NotAllowedError'
                            ? new DOMException(
                                  'Click reconnect to start video playback: ' + error.message,
                                  'PlaybackError'
                              )
                            : error
                    )
                );
        });

    const openCamera = async (device) => {
        const constraints = { audio: false, video: device ? { deviceId: { exact: device.deviceId } } : true };
        if (!device && !isSgu()) {
            constraints.video = { facingMode: { ideal: config.preview.camera_mode } };
        }
        try {
            return await navigator.mediaDevices.getUserMedia(constraints);
        } catch (error) {
            if (error.name !== 'OverconstrainedError') {
                throw error;
            }
            log('Constraint rejected; retrying once with video:true', {
                name: error.name,
                message: error.message,
                constraint: error.constraint
            });
            return navigator.mediaDevices.getUserMedia({ audio: false, video: true });
        }
    };

    const connect = (userInitiated = false, preferCamLink = false) => {
        if (connection) {
            if (connectionGeneration === generation) {
                return connection;
            }
            // A stopped getUserMedia request cannot be aborted by the browser.
            // Wait for it to settle before opening another device.
            const queuedGeneration = generation;
            return connection.then(() =>
                generation === queuedGeneration ? connect(userInitiated, preferCamLink) : false
            );
        }
        if (api.isMediaReady()) {
            return Promise.resolve(true);
        }
        reconnectEnabled = true;
        const attempt = ++generation;
        connectionGeneration = attempt;
        connection = (async () => {
            let opened = null;
            try {
                log('Initialization started', {
                    secureContext: window.isSecureContext,
                    origin: location.origin,
                    protocol: location.protocol,
                    mediaDevices: Boolean(navigator.mediaDevices),
                    userInitiated
                });
                if (config.preview.mode !== PreviewMode.DEVICE) {
                    api.reportStatus('configuration-required');
                    return false;
                }
                if (
                    !window.isSecureContext ||
                    !navigator.mediaDevices ||
                    !navigator.mediaDevices.getUserMedia ||
                    !video.get(0)
                ) {
                    api.reportStatus(
                        'unsupported',
                        null,
                        !window.isSecureContext ? 'insecure-context' : 'media-unavailable'
                    );
                    return false;
                }
                api.reportStatus('checking');
                permission = await queryPermission();
                if (generation !== attempt) {
                    return false;
                }
                if (permission === 'denied') {
                    api.reportStatus('permission-denied');
                    return false;
                }
                if (permission !== 'granted' && !userInitiated) {
                    api.reportStatus('permission-required');
                    return false;
                }
                releaseStream();
                api.reportStatus('connecting');
                const knownDevices = permission === 'granted' ? await enumerateCameras() : [];
                if (generation !== attempt) {
                    return false;
                }
                const initialSelection = selectCamera(knownDevices, preferCamLink);
                opened = await openCamera(initialSelection);
                if (generation !== attempt) {
                    stopTracks(opened);
                    return false;
                }
                api.stream = opened;
                sessionGranted = true;
                permission = 'granted';
                const devices = await enumerateCameras();
                if (generation !== attempt) {
                    stopTracks(opened);
                    return false;
                }
                const selected = selectCamera(devices, preferCamLink);
                const firstTrack = opened.getVideoTracks()[0];
                if (!firstTrack) {
                    throw new DOMException('No video track was returned', 'NotFoundError');
                }
                if (
                    selected &&
                    selected.deviceId &&
                    (!initialSelection || initialSelection.deviceId !== selected.deviceId) &&
                    firstTrack.getSettings().deviceId !== selected.deviceId
                ) {
                    // Release the permission/default stream BEFORE reopening CamLink.
                    releaseStream();
                    opened = await openCamera(selected);
                    if (generation !== attempt) {
                        stopTracks(opened);
                        return false;
                    }
                    api.stream = opened;
                }
                const stream = opened;
                const track = stream.getVideoTracks()[0];
                if (!track || !stream.active || track.readyState !== 'live') {
                    throw new DOMException('Camera stream is not active', 'NotReadableError');
                }
                track.addEventListener('ended', () => disconnected(stream, 'track-ended'));
                track.addEventListener('mute', () => {
                    if (api.stream === stream && api.previewStatus.state === 'ready') {
                        api.reportStatus('disconnected', null, 'track-muted');
                        clearTimeout(mutedTimer);
                        mutedTimer = setTimeout(() => {
                            if (track.muted) {
                                disconnected(stream, 'track-muted');
                            }
                        }, 2000);
                    }
                });
                track.addEventListener('unmute', () => {
                    clearTimeout(mutedTimer);
                    if (api.stream === stream && api.isMediaReady() && !connection) {
                        api.reportStatus('ready');
                    }
                });
                await waitForVideo(stream, attempt);
                if (generation !== attempt) {
                    return false;
                }
                // Negotiate preferred dimensions only after opening successfully.
                // They remain ideals: a device need not support these dimensions.
                const collage = typeof photoBooth !== 'undefined' && photoBooth.photoStyle === 'collage';
                const width = isSgu() ? 1920 : collage ? config.preview.videoWidth_collage : config.preview.videoWidth;
                const height = isSgu()
                    ? 1080
                    : collage
                      ? config.preview.videoHeight_collage
                      : config.preview.videoHeight;
                const ideals = {};
                if (width > 0) {
                    ideals.width = { ideal: width };
                }
                if (height > 0) {
                    ideals.height = { ideal: height };
                }
                if (!isSgu()) {
                    ideals.facingMode = { ideal: config.preview.camera_mode };
                }
                try {
                    await track.applyConstraints(ideals);
                } catch (error) {
                    log('Keeping working stream after optional constraints failed', {
                        name: error.name,
                        message: error.message,
                        constraint: error.constraint
                    });
                }
                if (generation !== attempt) {
                    return false;
                }
                await waitForVideo(stream, attempt);
                if (generation !== attempt || !api.isMediaReady()) {
                    return false;
                }
                reconnectAttempts = 0;
                clearReconnect();
                log('selected: ' + (track.label || 'camera'), {
                    label: track.label,
                    settings: track.getSettings(),
                    trackState: track.readyState
                });
                log('stream started', { width: video.get(0).videoWidth, height: video.get(0).videoHeight });
                api.reportStatus('ready');
                return true;
            } catch (error) {
                log('getUserMedia failed', {
                    name: error.name,
                    message: error.message,
                    constraint: error.constraint || ''
                });
                if (generation !== attempt) {
                    stopTracks(opened);
                    return false;
                }
                releaseStream();
                let state = 'error';
                if (error.name === 'NotAllowedError' || error.name === 'PermissionDeniedError') {
                    permission = 'denied';
                    sessionGranted = false;
                    state = 'permission-denied';
                    clearReconnect();
                } else if (error.name === 'NotReadableError') {
                    state = 'busy';
                } else if (error.name === 'NotFoundError') {
                    const devices = await enumerateCameras();
                    state = enumerationSucceeded && devices.length === 0 ? 'not-found' : 'error';
                } else if (error.name === 'AbortError') {
                    state = 'disconnected';
                } else if (error.name === 'SecurityError') {
                    state = 'unsupported';
                }
                if (generation === attempt) {
                    api.reportStatus(state, error);
                    if (['busy', 'disconnected', 'not-found'].includes(state)) {
                        scheduleReconnect(error.name);
                    }
                }
                return false;
            }
        })().finally(() => {
            connection = null;
        });
        return connection;
    };

    api.initializeMedia = (
        cb = () => {
            return;
        },
        options = {}
    ) => {
        const userInitiated = options.userInitiated === true || (!isSgu() && options.userInitiated !== false);
        return connect(userInitiated, options.preferCamLink === true).then((ready) => {
            if (ready && api.isMediaReady()) {
                cb();
            }
            return ready;
        });
    };

    api.changeVideoMode = function (mode) {
        photoboothTools.console.logDev('Preview: Changing video mode: ' + mode);
        if (mode !== CameraDisplayMode.BACKGROUND) {
            loader.css('--stage-background', 'transparent');
        }
        video.show();
    };

    api.getAndDisplayMedia = function (mode) {
        return api.initializeMedia(() => api.changeVideoMode(mode), {
            userInitiated: !isSgu() || Boolean(navigator.userActivation && navigator.userActivation.isActive)
        });
    };

    api.runCmd = function (mode) {
        if (config.windows_agent?.enabled) {
            return;
        }
        const dataVideo = {
            play: mode,
            pid: pid
        };

        photoboothTools
            .ajaxWithCsrf({
                url: 'api/previewCamera.php',
                method: 'POST',
                data: dataVideo
            })
            .done(function (result) {
                photoboothTools.console.log('Preview: ' + dataVideo.play + ' webcam successfully.');
                pid = result.pid;
            })
            // eslint-disable-next-line no-unused-vars
            .fail(function (xhr, status, result) {
                if (photoboothTools.isCsrfErrorResponse(xhr)) {
                    photoboothTools.handleCsrfMismatch('api/previewCamera.php');
                    return;
                }
                photoboothTools.console.log('ERROR: Preview: Failed to ' + dataVideo.play + ' webcam!');
            });
    };

    api.startVideo = function (mode, retry = 0) {
        photoboothTools.console.log('Preview: startVideo mode: ' + mode);
        if (config.preview.mode !== PreviewMode.URL.valueOf()) {
            if (!navigator.mediaDevices || config.preview.mode === PreviewMode.NONE.valueOf()) {
                return;
            }
        }

        switch (mode) {
            case CameraDisplayMode.INIT:
                photoboothTools.console.logDev('Preview: Running preview cmd (INIT).');
                api.runCmd('start');
                break;
            case CameraDisplayMode.BACKGROUND:
                if (
                    config.preview.mode === PreviewMode.DEVICE.valueOf() &&
                    config.commands.preview &&
                    !config.preview.bsm
                ) {
                    photoboothTools.console.logDev('Preview: Running preview cmd (BACKGROUND).');
                    api.runCmd('start');
                }
                api.getAndDisplayMedia(CameraDisplayMode.BACKGROUND);
                break;
            case CameraDisplayMode.COUNTDOWN:
                if (config.commands.preview) {
                    if (
                        config.preview.bsm ||
                        (!config.preview.bsm && retry > 0) ||
                        (typeof photoBooth !== 'undefined' && photoBooth.nextCollageNumber > 0)
                    ) {
                        photoboothTools.console.logDev('Preview: Running preview cmd (COUNTDOWN).');
                        api.runCmd('start');
                    }
                }
                if (config.preview.mode === PreviewMode.DEVICE.valueOf()) {
                    photoboothTools.console.logDev('Preview: Preview at countdown from device cam.');
                    api.getAndDisplayMedia(CameraDisplayMode.COUNTDOWN);
                } else if (config.preview.mode === PreviewMode.URL.valueOf()) {
                    photoboothTools.console.logDev('Preview: Preview at countdown from URL.');
                    setTimeout(function () {
                        url.css('background-image', 'url("' + getPreviewUrlWithCacheBusting() + '")');
                        url.show();
                        url.addClass('streaming');
                    }, config.preview.url_delay);
                }
                break;
            case CameraDisplayMode.TEST:
                if (config.preview.mode === PreviewMode.DEVICE.valueOf()) {
                    photoboothTools.console.logDev('Preview: Preview from device cam.');
                    api.getAndDisplayMedia(CameraDisplayMode.TEST);
                } else if (config.preview.mode === PreviewMode.URL.valueOf()) {
                    photoboothTools.console.logDev('Preview: Preview from URL.');
                    setTimeout(function () {
                        url.css('background-image', 'url("' + getPreviewUrlWithCacheBusting() + '")');
                        url.show();
                        url.addClass('streaming');
                    }, config.preview.url_delay);
                }
                break;
            default:
                photoboothTools.console.log('ERROR: Preview: Call for unexpected video mode: ' + mode);
                break;
        }
    };

    api.stopPreview = function () {
        if (config.commands.preview_kill) {
            api.runCmd('stop');
        }
        if (config.preview.mode === PreviewMode.DEVICE.valueOf() || config.preview.mode === PreviewMode.URL.valueOf()) {
            api.stopVideo();
        }
    };

    api.stopVideo = function () {
        loader.css('--stage-background', 'var(--background-countdown-color)');
        reconnectEnabled = false;
        generation += 1;
        clearReconnect();
        clearTimeout(deviceChangeTimer);
        releaseStream();
        url.removeClass('streaming');
        url.hide();
        url.css('background-image', 'none');
        video.hide();
        api.reportStatus('stopped');
        pictureFrame.hide();
        collageFrame.hide();
    };

    api.setElements = () => {
        video = $('#preview--video');
        loader = $('.stage[data-stage="loader"]');
        url = $('#preview--ipcam');
        pictureFrame = $('#previewframe--picture');
        collageFrame = $('#previewframe--collage');
    };

    api.init = function () {
        api.setElements();
        if (listenersAttached || !navigator.mediaDevices) {
            return;
        }
        listenersAttached = true;
        navigator.mediaDevices.addEventListener('devicechange', () => {
            clearTimeout(deviceChangeTimer);
            deviceChangeTimer = setTimeout(async () => {
                if (!reconnectEnabled || connection) {
                    return;
                }
                const observedGeneration = generation;
                permission = await queryPermission();
                if (permission !== 'granted' || observedGeneration !== generation || connection || !reconnectEnabled) {
                    return;
                }
                const devices = await enumerateCameras();
                if (!enumerationSucceeded || observedGeneration !== generation || connection || !reconnectEnabled) {
                    return;
                }
                const track = api.stream && api.stream.getVideoTracks()[0];
                const currentId = track && track.getSettings().deviceId;
                const selected = selectCamera(devices);
                if (
                    api.isMediaReady() &&
                    devices.some((device) => device.deviceId === currentId) &&
                    (!selected || selected.deviceId === currentId)
                ) {
                    return;
                }
                log('Device change; reconnecting', { selected: selected && selected.label });
                generation += 1;
                releaseStream();
                reconnectAttempts = 0;
                clearReconnect();
                api.reportStatus('disconnected', null, 'devicechange');
                scheduleReconnect('devicechange');
            }, 500);
        });
        window.addEventListener('pagehide', () => api.stopVideo());
    };

    api.retryMedia = function (options = {}) {
        if (connection && connectionGeneration === generation) {
            return connection;
        }
        clearReconnect();
        clearTimeout(deviceChangeTimer);
        reconnectAttempts = 0;
        releaseStream();
        return api.initializeMedia(
            () => {
                return;
            },
            { userInitiated: true, preferCamLink: options.preferCamLink !== false }
        );
    };

    return api;
})();

$(function () {
    photoboothPreview.init();
    photoboothTools.console.log('Preview: Preview functions available.');
});

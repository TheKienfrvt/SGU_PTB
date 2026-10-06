// Deterministic API doubles exercise lifecycle logic; these are NOT hardware tests.
const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const path = require('node:path');
const root = path.resolve(__dirname, '../..');
const source = fs.readFileSync(path.join(root, 'assets/js/preview.js'), 'utf8');
const kioskSource = fs.readFileSync(path.join(root, 'assets/js/sgu-kiosk.js'), 'utf8');
const flush = async () => { for (let i = 0; i < 40; i++) await Promise.resolve(); };
const deferred = () => {
    let resolve, reject;
    const promise = new Promise((yes, no) => { resolve = yes; reject = no; });
    return { promise, resolve, reject };
};
const camera = (deviceId, label) => ({ kind: 'videoinput', deviceId, label, groupId: 'group-' + deviceId });
const camLink = camera('camlink', 'Elgato Cam Link 4K');
const webcam = camera('webcam', 'Integrated Webcam');

function harness(options = {}) {
    let now = 0, timerId = 0;
    const timers = new Map();
    const setTimer = (fn, delay) => { const id = ++timerId; timers.set(id, { fn, at: now + delay }); return id; };
    class Element extends EventTarget {
        constructor() { super(); this.style = {}; this.dataset = {}; this.hidden = false; this.disabled = false; }
        setAttribute(name, value) { this[name] = value; }
        append(element) { element.parent = this; }
        before(element) { element.parent = this.parent; }
        after(element) { element.parent = this.parent; }
    }
    const video = new Element();
    Object.assign(video, { srcObject: null, paused: true, videoWidth: 0, videoHeight: 0 });
    video.pause = () => { video.paused = true; };
    video.play = async () => {
        video.paused = false;
        if (options.autoMetadata !== false) {
            video.videoWidth = 1280; video.videoHeight = 720;
            video.dispatchEvent(new Event('loadedmetadata'));
        }
        if (options.play) await options.play();
    };
    const media = new EventTarget();
    const permission = new EventTarget();
    permission.state = options.permission || 'granted';
    const nodes = new Map();
    for (const selector of ['.preview', '[data-sgu-preview-slot]', '[data-sgu-camera-status]', '[data-sgu-camera-status-text]',
        '[data-sgu-camera-help]', '[data-sgu-reconnect]', '[data-sgu-preflight]', '[data-sgu-capture-status]', '[data-sgu-flow-status]', '[data-sgu-capture-preview]']) {
        nodes.set(selector, new Element());
    }
    nodes.set('#preview--video', video);
    nodes.set('start', new Element());
    const doc = new EventTarget();
    doc.body = new Element();
    doc.querySelector = (selector) => nodes.get(selector) || null;
    doc.querySelectorAll = (selector) => selector.startsWith('.stage--sgu') ? [nodes.get('start')] : nodes.has(selector) ? [nodes.get(selector)] : [];
    doc.getElementById = (id) => nodes.get('#' + id);
    doc.createComment = () => new Element();
    const window = new EventTarget();
    window.isSecureContext = options.secure !== false;
    const preferences = options.preferences || new Map();
    window.localStorage = { getItem: (key) => preferences.get(key), setItem: (key, value) => preferences.set(key, value) };
    const h = { video, media, permission, nodes, timers, calls: [], streams: [], states: [], devices: options.devices || [camLink], maxLive: 0, fetchCalls: 0 };
    h.makeStream = (device = camLink) => {
        const track = new EventTarget();
        Object.assign(track, { label: device.label, readyState: 'live', muted: false });
        const stream = { active: true, getTracks: () => [track], getVideoTracks: () => [track] };
        track.getSettings = () => ({ deviceId: device.deviceId, width: 1280, height: 720 });
        track.applyConstraints = async () => {
            if (options.constraintError) throw new DOMException('Unsupported ideal', 'OverconstrainedError');
        };
        track.stop = () => { track.readyState = 'ended'; stream.active = false; };
        stream.track = track;
        h.streams.push(stream);
        h.maxLive = Math.max(h.maxLive, h.streams.filter((item) => item.active).length);
        return stream;
    };
    media.enumerateDevices = async () => {
        if (options.enumerateError) throw new DOMException('Enumeration failed', 'AbortError');
        return h.devices;
    };
    media.getUserMedia = async (constraints) => {
        h.calls.push(constraints);
        if (options.getMedia) return options.getMedia(constraints, h);
        const deviceId = constraints.video.deviceId && constraints.video.deviceId.exact;
        return h.makeStream(h.devices.find((device) => device.deviceId === deviceId) || h.devices[0] || camLink);
    };
    const config = {
        preview: { mode: options.mode || 'device_cam', videoWidth: 1280, videoHeight: 720, camera_mode: 'user' },
        sgu: { enabled: options.sgu !== false, capture_mode: options.captureMode || 'native', require_usb_capture: options.requireUsb === true, camera_label: options.label === undefined ? 'Cam Link' : options.label, camera_device_id: options.deviceId || '' },
        dev: { loglevel: 0 }, commands: {}, windows_agent: { enabled: options.windowsAgent === true }
    };
    const $ = (selector) => {
        if (typeof selector === 'function') { selector(); return; }
        const element = selector === '#preview--video' ? video : new Element();
        const wrapper = { get: () => element, show: () => { element.style.display = 'block'; return wrapper; },
            hide: () => { element.style.display = 'none'; return wrapper; }, css: () => wrapper, removeClass: () => wrapper };
        return wrapper;
    };
    class CustomEvent extends Event { constructor(type, init) { super(type); this.detail = init.detail; } }
    const context = vm.createContext({ config, $, navigator: { mediaDevices: options.unsupported ? undefined : media,
        permissions: options.noPermissions ? undefined : { query: async () => permission } },
        window, document: doc, location: { origin: 'http://localhost', protocol: 'http:' },
        photoBooth: { photoStyle: 'photo', flowState: 'idle' },
        photoboothPhotoCamera: { status: options.usbStatus || { success: false, connected: false } },
        photoboothTools: { console: { log() {}, logDev() {} } },
        DOMException, CustomEvent, console, setTimeout: setTimer, clearTimeout: (id) => timers.delete(id),
        fetch: async () => {
            h.fetchCalls++;
            return { ok: options.preflightFailure !== true, json: async () => options.preflight || {
                captureSource: 'browser', captureBackendConfigured: true, captureBackendReady: null, storageWritable: true
            } };
        }
    });
    doc.addEventListener('photobooth.preview.status', (event) => h.states.push(event.detail.state));
    vm.runInContext(source + '\nglobalThis.api = photoboothPreview;', context);
    h.api = context.api;
    h.tick = async (duration) => {
        const target = now + duration;
        while (true) {
            const next = [...timers.entries()].filter(([, timer]) => timer.at <= target).sort((a, b) => a[1].at - b[1].at)[0];
            if (!next) break;
            now = next[1].at; timers.delete(next[0]); next[1].fn(); await flush();
        }
        now = target; await flush();
    };
    h.loadKiosk = () => { vm.runInContext(kioskSource + '\nglobalThis.kiosk = sguKiosk;', context); h.kiosk = context.kiosk; };
    return h;
}

test('SGU production blocks an unverified command even with a ready Cam Link', async () => {
    const h = harness({ requireUsb: true, preflight: { usbRequired: true, captureSource: 'command', captureBackendConfigured: false, storageWritable: true } });
    h.loadKiosk(); await flush();
    assert.equal(h.api.isMediaReady(), true);
    assert.equal(h.nodes.get('start').disabled, true);
    assert.match(h.nodes.get('[data-sgu-capture-status]').textContent, /Chưa bật Windows Camera Agent/);
    assert.equal(h.nodes.get('[data-sgu-preview-slot]').dataset.ready, 'true');
});

test('browser capture mode is ready from the live MediaStream without Windows Agent', async () => {
    const h = harness({
        captureMode: 'browser',
        requireUsb: true,
        preflight: { usbRequired: false, captureSource: 'browser', captureBackendConfigured: true, storageWritable: true }
    });
    h.loadKiosk();
    await flush();
    assert.equal(h.api.isMediaReady(), true);
    assert.equal(h.nodes.get('start').disabled, false);
    assert.match(h.nodes.get('[data-sgu-capture-status]').textContent, /CamLink/);
});

test('SGU production requires positive USB success separately from preview', async () => {
    for (const status of [{ success: true, connected: true }, { success: false, connected: true }, { success: true, connected: false }]) {
        const h = harness({ requireUsb: true, windowsAgent: true, usbStatus: status });
        h.loadKiosk(); await flush();
        assert.equal(h.nodes.get('start').disabled, !(status.success && status.connected));
    }
});

test('returning to the start screen re-shows a live stream hidden by reset without reopening the camera', async () => {
    const h = harness();
    h.loadKiosk(); await flush();
    assert.equal(h.video.style.display, 'block');
    h.video.style.display = 'none'; // core reset() -> previewVideo.hide() after a finished session
    assert.equal(await h.kiosk.activatePreview(), true);
    assert.equal(h.video.style.display, 'block');
    assert.equal(h.calls.length, 1);
});

test('SGU reserves preview space while waiting for browser permission', async () => {
    const h = harness({ permission: 'prompt' });
    h.loadKiosk(); await flush();
    assert.equal(h.nodes.get('[data-sgu-preview-slot]').hidden, false);
    assert.equal(h.nodes.get('[data-sgu-preview-slot]').dataset.ready, 'false');
});

test('SGU countdown moves the same video into the capture slot without opening another stream', async () => {
    const h = harness();
    h.loadKiosk(); await flush();
    const preview = h.nodes.get('.preview');
    assert.equal(preview.parent, h.nodes.get('[data-sgu-preview-slot]'));
    h.kiosk.setFlowState('countdown');
    assert.equal(preview.parent, h.nodes.get('[data-sgu-capture-preview]'));
    assert.equal(h.nodes.get('[data-sgu-capture-preview]').hidden, false);
    h.kiosk.setFlowState('transferring');
    assert.equal(h.nodes.get('[data-sgu-capture-preview]').hidden, true);
    h.kiosk.setFlowState('idle');
    assert.equal(preview.parent, h.nodes.get('[data-sgu-preview-slot]'));
    assert.equal(h.calls.length, 1);
});

test('prompt waits for a gesture; page load never claims no camera or opens a stream', async () => {
    const h = harness({ permission: 'prompt', devices: [camera('', '')] });
    assert.equal(await h.api.initializeMedia(), false);
    assert.equal(h.api.previewStatus.state, 'permission-required');
    assert.equal(h.calls.length, 0);
    assert.equal(h.states.includes('not-found'), false);
});

test('granted permission connects CamLink automatically on each new page', async () => {
    for (let reload = 0; reload < 2; reload++) {
        const h = harness({ devices: [webcam, camLink] });
        assert.equal(await h.api.initializeMedia(), true);
        assert.equal(h.calls[0].video.deviceId.exact, 'camlink');
        assert.equal(h.calls.length, 1);
        assert.equal(h.api.previewStatus.state, 'ready');
        assert.equal(h.api.isMediaReady(), true);
    }
});

test('manual permission flow closes initial stream before selecting CamLink', async () => {
    const h = harness({ permission: 'prompt', devices: [webcam, camLink] });
    assert.equal(await h.api.retryMedia(), true);
    assert.equal(h.calls[0].video, true);
    assert.equal(h.calls[1].video.deviceId.exact, 'camlink');
    assert.equal(h.streams[0].active, false);
    assert.equal(h.maxLive, 1);
    assert.equal(h.api.stream.track.label, camLink.label);
});

test('configured existing ID wins; stale ID falls back to partial label', async () => {
    for (const [deviceId, expected] of [['webcam', 'webcam'], ['expired-id', 'camlink']]) {
        const h = harness({ devices: [webcam, camLink], deviceId });
        await h.api.initializeMedia();
        assert.equal(h.calls[0].video.deviceId.exact, expected);
    }
});

test('CamLink variant fallback, sole camera, and working default are usable', async () => {
    for (const [devices, label, expected] of [
        [[webcam, camera('usb', 'Elgato CamLink 4K')], 'Cam Link', 'usb'],
        [[webcam, camera('capture', 'USB Video Capture')], 'Missing preference', 'capture'],
        [[webcam, camera('hdmi', 'HDMI Video')], 'Missing preference', 'hdmi'],
        [[webcam], 'Missing preference', 'webcam'],
        [[webcam, camera('other', 'USB Camera')], 'Missing preference', 'webcam']
    ]) {
        const h = harness({ devices, label });
        assert.equal(await h.api.initializeMedia(), true);
        assert.equal(h.api.stream.track.getSettings().deviceId, expected);
        assert.equal(h.maxLive, 1);
    }
});

test('denied permission never prompts or retries; permission change to granted reconnects', async () => {
    const h = harness({ permission: 'denied' });
    await h.api.initializeMedia(); await h.api.retryMedia(); await h.tick(30000);
    assert.equal(h.calls.length, 0);
    assert.equal(h.api.previewStatus.state, 'permission-denied');
    h.permission.state = 'granted'; h.permission.dispatchEvent(new Event('change')); await flush();
    assert.equal(h.api.previewStatus.state, 'ready');
});

test('NotAllowedError is denied without popup loops', async () => {
    const h = harness({ getMedia: async () => { throw new DOMException('Blocked', 'NotAllowedError'); } });
    await h.api.retryMedia(); await h.tick(30000);
    assert.equal(h.calls.length, 1);
    assert.equal(h.api.previewStatus.state, 'permission-denied');
});

test('NotReadableError is busy and auto retry is bounded to three retries', async () => {
    const h = harness({ getMedia: async () => { throw new DOMException('In use', 'NotReadableError'); } });
    await h.api.initializeMedia(); await h.tick(30000);
    assert.equal(h.calls.length, 4);
    assert.equal(h.api.previewStatus.state, 'busy');
    assert.equal(h.states.includes('not-found'), false);
});

test('NotFoundError reports no camera only for an empty enumeration', async () => {
    for (const devices of [[], [camLink]]) {
        const h = harness({ devices, getMedia: async () => { throw new DOMException('Missing', 'NotFoundError'); } });
        await h.api.initializeMedia();
        assert.equal(h.api.previewStatus.state, devices.length ? 'error' : 'not-found');
    }
});

test('exact device constraint fallback retries once and retains the usable default', async () => {
    const h = harness({ devices: [webcam, camLink], getMedia: async (constraints, mock) => {
        if (mock.calls.length === 1) throw new DOMException('Constraint unsupported', 'OverconstrainedError');
        return mock.makeStream(webcam);
    } });
    assert.equal(await h.api.initializeMedia(), true);
    assert.equal(h.calls.length, 2);
    assert.equal(h.calls[1].video, true);
    assert.equal(h.api.stream.track.label, webcam.label);
});

test('optional ideal resolution failure keeps a working preview', async () => {
    const h = harness({ constraintError: true });
    assert.equal(await h.api.initializeMedia(), true);
    assert.equal(h.calls.length, 1);
});

test('metadata alone cannot signal ready before play resolves', async () => {
    const playback = deferred();
    const h = harness({ play: () => playback.promise });
    const pending = h.api.initializeMedia(); await flush();
    assert.equal(h.video.videoWidth, 1280);
    assert.equal(h.states.includes('ready'), false);
    playback.resolve();
    assert.equal(await pending, true);
});

test('ready waits for nonzero dimensions after play; timeout releases the device', async () => {
    const h = harness({ autoMetadata: false });
    const pending = h.api.initializeMedia(); await flush();
    assert.equal(h.states.includes('ready'), false);
    await h.tick(10000);
    assert.equal(await pending, false);
    assert.equal(h.api.previewStatus.state, 'error');
    assert.equal(h.video.srcObject, null);
    assert.equal(h.streams[0].active, false);
});

test('autoplay rejection is not misreported as camera permission denied', async () => {
    const h = harness({ play: async () => { throw new DOMException('Gesture required', 'NotAllowedError'); } });
    assert.equal(await h.api.initializeMedia(), false);
    assert.equal(h.api.previewStatus.state, 'error');
    assert.equal(h.api.previewStatus.permission, 'granted');
});

test('double reset is single-flight and never opens two devices', async () => {
    const media = deferred();
    const h = harness({ getMedia: () => media.promise });
    const first = h.api.retryMedia(); const second = h.api.retryMedia(); await flush();
    assert.equal(h.calls.length, 1);
    media.resolve(h.makeStream());
    assert.equal(await first, true); assert.equal(await second, true);
    assert.equal(h.maxLive, 1);
});

test('stop while getUserMedia is pending disposes the late stream', async () => {
    const media = deferred(); const h = harness({ getMedia: () => media.promise });
    const pending = h.api.initializeMedia(); await flush();
    h.api.stopVideo(); media.resolve(h.makeStream());
    assert.equal(await pending, false);
    assert.equal(h.api.previewStatus.state, 'stopped');
    assert.equal(h.video.srcObject, null);
    assert.equal(h.streams[0].active, false);
});

test('reconnect after cancellation waits for the old request to settle', async () => {
    const media = deferred();
    const h = harness({ getMedia: (constraints, mock) => mock.calls.length === 1 ? media.promise : mock.makeStream() });
    const old = h.api.initializeMedia(); await flush(); h.api.stopVideo();
    const next = h.api.retryMedia(); await flush();
    assert.equal(h.calls.length, 1);
    media.resolve(h.makeStream());
    assert.equal(await old, false); assert.equal(await next, true);
    assert.equal(h.calls.length, 2); assert.equal(h.maxLive, 1);
});

test('track ended clears preview immediately then reconnects with backoff', async () => {
    const h = harness(); await h.api.initializeMedia();
    const old = h.api.stream; old.track.stop(); old.track.dispatchEvent(new Event('ended'));
    assert.equal(h.api.previewStatus.state, 'disconnected');
    assert.equal(h.api.isMediaReady(), false); assert.equal(h.video.srcObject, null);
    await h.tick(1000);
    assert.equal(h.api.previewStatus.state, 'ready'); assert.equal(h.maxLive, 1);
});

test('devicechange reconnects an inserted CamLink, but intentional stop stays stopped', async () => {
    const h = harness({ devices: [webcam] }); await h.api.initializeMedia();
    h.devices = [webcam, camLink]; h.media.dispatchEvent(new Event('devicechange')); await h.tick(1500);
    assert.equal(h.api.previewStatus.label, camLink.label); assert.equal(h.maxLive, 1);
    h.api.stopVideo(); const count = h.calls.length;
    h.media.dispatchEvent(new Event('devicechange')); await h.tick(10000);
    assert.equal(h.calls.length, count); assert.equal(h.video.srcObject, null);
});

test('Permissions API fallback uses CTA for hidden labels and connects already-labelled devices', async () => {
    const fresh = harness({ noPermissions: true, devices: [camera('', '')] });
    await fresh.api.initializeMedia(); assert.equal(fresh.calls.length, 0);
    assert.equal(fresh.api.previewStatus.state, 'permission-required');
    const granted = harness({ noPermissions: true });
    assert.equal(await granted.api.initializeMedia(), true);
});

test('enumeration failure does not discard a granted stream', async () => {
    const h = harness({ enumerateError: true });
    assert.equal(await h.api.initializeMedia(), true);
    assert.equal(h.api.previewStatus.state, 'ready');
});

test('Windows USB agent keeps HDMI preview independent of legacy command start/stop', async () => {
    const h = harness({ windowsAgent: true });
    await h.api.initializeMedia();
    // The harness has no command transport: any attempted shell API call fails this test.
    h.api.runCmd('start');
    h.api.runCmd('stop');
    assert.equal(h.api.isMediaReady(), true);
    assert.equal(h.calls.length, 1);
    assert.equal(h.streams[0].active, true);
});

test('operator preview selection is remembered and a missing saved device falls back to Cam Link', async () => {
    const preferences = new Map();
    const h = harness({ permission: 'granted', devices: [camLink, webcam], preferences });
    await h.api.initializeMedia(); await flush();
    await h.api.chooseCamera(webcam.deviceId); await flush();
    assert.equal(h.api.stream.track.getSettings().deviceId, webcam.deviceId);
    h.api.stopVideo();
    const reloaded = harness({ permission: 'granted', devices: [camLink, webcam], preferences });
    await reloaded.api.initializeMedia(); await flush();
    assert.equal(reloaded.api.stream.track.getSettings().deviceId, webcam.deviceId);
    reloaded.api.stopVideo();
    const missing = harness({ permission: 'granted', devices: [camLink], preferences });
    await missing.api.initializeMedia(); await flush();
    assert.equal(missing.api.stream.track.getSettings().deviceId, camLink.deviceId);
});

test('reconnect button prioritizes Cam Link over a remembered webcam', async () => {
    const preferences = new Map([['photobooth.preview.deviceId', webcam.deviceId]]);
    const h = harness({ permission: 'granted', devices: [webcam, camLink], preferences });
    await h.api.initializeMedia(); await flush();
    assert.equal(h.api.stream.track.getSettings().deviceId, webcam.deviceId);
    await h.api.retryMedia(); await flush();
    assert.equal(h.api.stream.track.getSettings().deviceId, camLink.deviceId);
});

test('stop during the final video-ready event cannot publish stale ready', async () => {
    let plays = 0;
    const h = harness({ play: async () => {
        if (++plays === 2) {
            h.video.videoWidth = 0;
            h.video.videoHeight = 0;
        }
    } });
    const pending = h.api.initializeMedia();
    await flush();
    assert.equal(plays, 2);
    // A later listener in the same DOM event can stop the stream before the
    // resolved readiness promise resumes initialization.
    h.video.addEventListener('resize', () => h.api.stopVideo());
    h.video.videoWidth = 1280;
    h.video.videoHeight = 720;
    h.video.dispatchEvent(new Event('resize'));
    assert.equal(await pending, false);
    assert.equal(h.api.previewStatus.state, 'stopped');
    assert.equal(h.api.isMediaReady(), false);
});

test('failed enumeration cannot turn a device-open error into no camera', async () => {
    const h = harness({ enumerateError: true, getMedia: async () => { throw new DOMException('Missing device', 'NotFoundError'); } });
    await h.api.initializeMedia();
    assert.equal(h.api.previewStatus.state, 'error');
});

test('a persistent muted track reconnects; a transient mute recovers without reopening', async () => {
    const h = harness(); await h.api.initializeMedia();
    const track = h.api.stream.track;
    track.muted = true; track.dispatchEvent(new Event('mute'));
    assert.equal(h.api.isMediaReady(), false);
    await h.tick(500);
    track.muted = false; track.dispatchEvent(new Event('unmute'));
    await h.tick(2000);
    assert.equal(h.calls.length, 1); assert.equal(h.api.previewStatus.state, 'ready');
    track.muted = true; track.dispatchEvent(new Event('mute'));
    await h.tick(3000);
    assert.equal(h.calls.length, 2); assert.equal(h.api.previewStatus.state, 'ready');
    assert.equal(track.readyState, 'ended');
});

test('a late device enumeration cannot replace a manually reconnected stream', async () => {
    const h = harness(); await h.api.initializeMedia();
    const enumeration = deferred();
    let calls = 0;
    h.media.enumerateDevices = () => ++calls === 1 ? enumeration.promise : Promise.resolve(h.devices);
    h.media.dispatchEvent(new Event('devicechange')); await h.tick(500);
    await h.api.retryMedia();
    const replacement = h.api.stream;
    enumeration.resolve([]); await flush(); await h.tick(5000);
    assert.equal(h.api.stream, replacement); assert.equal(h.api.previewStatus.state, 'ready');
});

test('unsupported, insecure, and explicitly disabled preview do not request camera', async () => {
    for (const options of [{ unsupported: true }, { secure: false }, { mode: 'none' }]) {
        const h = harness(options); assert.equal(await h.api.initializeMedia(), false);
        assert.equal(h.calls.length, 0);
        assert.equal(h.api.previewStatus.state, options.mode ? 'configuration-required' : 'unsupported');
    }
});

test('SGU init displays permission CTA, then enables Start only with actual ready video and storage', async () => {
    const h = harness({ permission: 'prompt' }); h.loadKiosk(); await flush();
    assert.equal(h.nodes.get('[data-sgu-camera-status-text]').textContent, 'Live Preview: Disconnected • Cần quyền truy cập camera');
    assert.equal(h.nodes.get('[data-sgu-reconnect]').textContent, 'Cho phép Cam Link');
    assert.equal(h.nodes.get('start').disabled, true); assert.equal(h.calls.length, 0);
    await h.kiosk.reconnect(); await flush();
    assert.equal(h.nodes.get('start').disabled, false);
    assert.equal(h.nodes.get('[data-sgu-preview-slot]').hidden, false);
    h.api.stream.track.stop(); h.api.stream.track.dispatchEvent(new Event('ended'));
    assert.equal(h.nodes.get('start').disabled, true);
});

test('SGU keeps backend failure separate from browser-ready state', async () => {
    const h = harness({ preflight: { storageWritable: false, captureSource: 'command', captureBackendConfigured: true, captureBackendReady: null } });
    h.loadKiosk(); await flush();
    assert.match(h.nodes.get('[data-sgu-camera-status-text]').textContent, /Live Preview: Connected/);
    assert.match(h.nodes.get('[data-sgu-capture-status]').textContent, /chưa xác nhận thiết bị/);
    assert.equal(h.nodes.get('start').disabled, true);
});

test('SGU reset refreshes failed preflight, while capture pause resumes on return home', async () => {
    const options = { preflightFailure: true }; const h = harness(options); h.loadKiosk(); await flush();
    assert.equal(h.nodes.get('start').disabled, true);
    options.preflightFailure = false; await h.kiosk.reconnect(); await flush();
    assert.equal(h.nodes.get('start').disabled, false);
    h.kiosk.setFlowState('capturing'); h.api.stopVideo();
    h.kiosk.setFlowState('idle'); await flush();
    assert.equal(h.api.previewStatus.state, 'ready'); assert.equal(h.kiosk.isReady(), true);
});

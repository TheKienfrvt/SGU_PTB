const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const source = fs.readFileSync('assets/js/capture-session.js', 'utf8');
const flush = async () => {
    for (let i = 0; i < 25; i++) await Promise.resolve();
};

function harness(initial, options = {}) {
    class Element extends EventTarget {
        constructor() {
            super();
            this.hidden = false;
            this.disabled = false;
            this.dataset = {};
            this.style = {};
            this.children = [];
            this.attributes = {};
            this.classList = { add() {}, remove() {} };
        }
        append(child) {
            this.children.push(child);
        }
        prepend(child) {
            this.children.unshift(child);
        }
        replaceChildren() {
            this.children = [];
        }
        setAttribute(key, value) {
            this.attributes[key] = value;
        }
        focus() {}
        click() {
            if (!this.disabled && !this.hidden) this.dispatchEvent(new Event('click'));
        }
    }
    const nodes = new Map();
    const node = (key) => {
        if (!nodes.has(key)) nodes.set(key, new Element());
        return nodes.get(key);
    };
    const root = node('root');
    root.querySelector = (selector) => node(selector.match(/data-session-(.+)\]/)[1]);
    const calls = [];
    const timers = new Map();
    let timerId = 0;
    let session = structuredClone(initial);
    let pairPending = options.pairPending === true;
    let ready = options.ready !== false;
    const booth = {
        flowState: 'idle',
        takingPic: false,
        setFlowState(state) {
            this.flowState = state;
        },
        resetTimeOut() {},
        reset() {
            this.flowState = 'idle';
        },
        screensaver: { hide() {}, resetTimer() {} }
    };
    const video = node('video');
    video.videoWidth = 1920;
    video.videoHeight = 1080;
    const createElement = (name) => {
        const created = new Element();
        if (name === 'canvas') {
            created.getContext = () => ({ translate() {}, scale() {}, drawImage() {} });
            created.toBlob = (callback) => {
                if (options.losePreviewBeforeBlob) ready = false;
                callback(new Blob(['jpeg'], { type: 'image/jpeg' }));
            };
        }
        return created;
    };
    const context = vm.createContext({
        document: {
            querySelector: (selector) => (selector === '[data-capture-session]' ? root : node(selector)),
            getElementById: () => video,
            createElement
        },
        config: {
            preview: { flip: '' },
            sgu: {
                capture_mode: options.browser ? 'browser' : 'native',
                countdown_seconds: 1,
                shot_preview_ms: 200,
                complete_seconds: 8
            },
            print: { from_result: true },
            windows_agent: { enabled: !!options.photoCamera },
            dev: { demo_images: !options.browser && !options.photoCamera }
        },
        csrf: { key: 'csrf', token: 'test' },
        environment: { publicFolders: { api: '/api' } },
        photoboothPreview: { isMediaReady: () => ready, initializeMedia: async () => {} },
        sguKiosk: { isReady: () => ready },
        photoboothPhotoCamera: options.photoCamera,
        templateSelection: options.templateSelection,
        fetch: async (url, request = {}) => {
            if (!request.body) {
                calls.push({ action: 'asset-fetch', url });
                return { ok: true, blob: async () => new Blob(['jpeg'], { type: 'image/jpeg' }) };
            }
            const action = request.body.get('action');
            calls.push({ action, data: request.body });
            if (options.fetch) await options.fetch(action);
            if (action === 'compose') {
                session.state = 'final-preview';
                session.final = '/data/images/final.jpg';
            }
            if (action === 'confirm') {
                session.state = 'confirmed';
                session.confirmed = true;
            }
            if (action === 'pair_duplicate' || action === 'pair_finish') {
                session.state = 'sheet-preview';
                session.sheet = '/data/images/a5.jpg';
                session.sheet_format = 'A5-pair';
                pairPending = false;
            }
            if (action === 'pair_hold') {
                session = null;
                pairPending = true;
            }
            if (action === 'pair_cancel') {
                session = null;
                pairPending = false;
            }
            if (action === 'print') {
                session.state = 'complete';
                if (options.lostPrintResponse) throw new Error('Network lost');
            }
            if (action === 'capture') {
                session.shots.push({
                    id: String(session.shots.length),
                    thumbnail: '/api/captureSessionImage.php?shot=' + session.shots.length
                });
                if (session.shots.length === session.target) session.state = 'selecting';
            }
            if (action === 'replace') {
                const slot = Number(request.body.get('slot'));
                session.shots[slot] = { id: 'replacement', thumbnail: '/api/captureSessionImage.php?shot=replacement' };
                session.selected[slot] = 'replacement';
                session.state = 'selecting';
            }
            if (action === 'compose') session.selected = request.body.getAll('selected[]');
            if (action === 'cancel') {
                session = null;
                pairPending = false;
            }
            if (action === 'start' && session === null && options.recreate) session = structuredClone(options.recreate);
            return {
                ok: true,
                json: async () => ({ success: true, session: structuredClone(session), pair_pending: pairPending })
            };
        },
        window: options.window,
        URLSearchParams,
        FormData,
        Blob,
        AbortController,
        Event,
        console,
        setTimeout: (fn, delay) => {
            timers.set(++timerId, { fn, delay });
            return timerId;
        },
        clearTimeout: (id) => timers.delete(id)
    });
    const api = vm.runInContext(source + '\ncreateCaptureSession', context)(booth);
    return {
        api,
        booth,
        node,
        calls,
        timers,
        setReady: (value) => (ready = value),
        advance: async (delay) => {
            for (const [id, timer] of [...timers])
                if (timer.delay === delay) {
                    timers.delete(id);
                    timer.fn();
                }
            await flush();
        }
    };
}

const selecting = () => ({
    id: 'session',
    state: 'selecting',
    required: 2,
    target: 3,
    selected: [],
    final: null,
    confirmed: false,
    shots: [0, 1, 2].map((i) => ({ id: String(i), thumbnail: '/api/captureSessionImage.php?shot=' + i }))
});
const browserSession = (overrides = {}) => ({
    id: 'session',
    state: 'preview',
    capture_mode: 'browser',
    required: 2,
    target: 2,
    selected: [],
    final: null,
    confirmed: false,
    shots: [],
    template_id: 'frame',
    template: {
        canvas: { width: 1200, height: 1800, background: '#ffffff' },
        overlay: '/templates/frame/overlay.png',
        background: null,
        slots: [
            { x: 50, y: 50, width: 525, height: 825, fit: 'cover', position: 'center', rotation: 0 },
            { x: 625, y: 50, width: 525, height: 825, fit: 'cover', position: 'center', rotation: 0 }
        ]
    },
    ...overrides
});

test('restore uses only thumbnails and exactly required slots; repeated selections retain keyboard elements', async () => {
    const h = harness(selecting());
    await h.api.restore();
    const buttons = h.node('grid').children;
    assert.equal(h.booth.flowState, 'selecting');
    assert.ok(h.node('compose').disabled);
    assert.ok(buttons.every((button) => button.children[0].src.startsWith('/api/captureSessionImage.php')));
    buttons[0].click();
    buttons[1].click();
    buttons[2].click();
    assert.equal(h.node('counter').textContent, 'Đã chọn 2 / 2');
    assert.equal(buttons[2].attributes['aria-pressed'], 'false');
    assert.equal(h.node('compose').disabled, false);
    h.node('compose').click();
    h.node('compose').click();
    await flush();
    assert.equal(h.calls.filter((c) => c.action === 'compose').length, 1);
    assert.equal(h.node('photo').src, '/data/images/final.jpg');
    assert.equal(h.node('confirm').hidden, false);
    assert.equal(h.node('confirm').disabled, true);
    h.node('photo').onload();
    h.node('confirm').click();
    await flush();
    assert.equal(h.calls.filter((c) => c.action === 'confirm').length, 1);
    assert.equal(h.node('download').href, '/data/images/final.jpg');
});

test('double print submits once and displays exact backend final asset', async () => {
    const h = harness({
        ...selecting(),
        state: 'sheet-preview',
        confirmed: true,
        final: '/data/images/final.jpg',
        sheet: '/data/images/a5.jpg',
        sheet_format: 'A5-pair'
    });
    await h.api.restore();
    assert.equal(h.node('print').disabled, true);
    h.node('photo').onload();
    h.node('print').click();
    h.node('print').click();
    await flush();
    assert.equal(h.calls.filter((c) => c.action === 'print').length, 1);
    assert.equal(h.booth.flowState, 'complete');
    assert.equal(h.node('photo').src, '/data/images/a5.jpg');
    await h.advance(8000);
    assert.equal(h.booth.flowState, 'idle');
});

test('lost print response recovers by status without retrying the physical job', async () => {
    const h = harness(
        {
            ...selecting(),
            state: 'sheet-preview',
            confirmed: true,
            final: '/data/images/final.jpg',
            sheet: '/data/images/a5.jpg',
            sheet_format: 'A5-pair'
        },
        { lostPrintResponse: true }
    );
    await h.api.restore();
    h.node('photo').onload();
    h.node('print').click();
    await flush();
    assert.equal(h.booth.flowState, 'error');
    h.node('refresh').click();
    await flush();
    assert.equal(h.booth.flowState, 'complete');
    assert.equal(h.calls.filter((c) => c.action === 'print').length, 1);
});

test('a failed sheet preview cannot be printed until it loads successfully', async () => {
    const h = harness({
        ...selecting(),
        state: 'sheet-preview',
        confirmed: true,
        final: '/data/images/final.jpg',
        sheet: '/data/images/a5.jpg',
        sheet_format: 'A5-pair'
    });
    await h.api.restore();
    h.node('photo').onerror();
    assert.equal(h.node('print').disabled, true);
    assert.equal(h.node('refresh').hidden, false);
    h.node('print').click();
    assert.equal(h.calls.filter((call) => call.action === 'print').length, 0);
    h.node('refresh').click();
    await flush();
    h.node('photo').onload();
    assert.equal(h.node('print').disabled, false);
});

test('slow final JPEG keeps completion locked and offers status refresh', async () => {
    const h = harness({ ...selecting(), state: 'final-preview', final: '/data/images/final.jpg' });
    await h.api.restore();
    await h.advance(15000);
    assert.equal(h.node('waiting').hidden, true);
    assert.equal(h.node('confirm').disabled, true);
    assert.equal(h.node('refresh').hidden, false);
    h.node('confirm').click();
    assert.equal(h.calls.filter((call) => call.action === 'confirm').length, 0);
});

test('confirmed frame can be duplicated into an edge-to-edge A5 sheet before printing', async () => {
    const h = harness({
        ...selecting(),
        state: 'confirmed',
        confirmed: true,
        final: '/data/images/final.jpg',
        sheet: null
    });
    await h.api.restore();
    assert.equal(h.node('print').hidden, true);
    assert.equal(h.node('duplicate').hidden, false);
    h.node('duplicate').click();
    await flush();
    assert.equal(h.calls.filter((call) => call.action === 'pair_duplicate').length, 1);
    assert.equal(h.booth.flowState, 'sheet-preview');
    assert.equal(h.node('photo').src, '/data/images/a5.jpg');
    h.node('photo').naturalWidth = 2002;
    h.node('photo').naturalHeight = 3001;
    h.node('photo').onload();
    assert.equal(h.node('photo-shell').style.aspectRatio, '2002 / 3001');
    assert.equal(h.node('download').href, '/data/images/a5.jpg');
    assert.equal(h.node('download').textContent, 'Tải JPEG A5');
    assert.equal(h.node('print').hidden, false);
});

test('a pending first frame is automatically paired after confirming the second frame', async () => {
    const h = harness(
        { ...selecting(), state: 'final-preview', confirmed: false, final: '/data/images/second.jpg', sheet: null },
        { pairPending: true }
    );
    await h.api.restore();
    h.node('photo').onload();
    h.node('confirm').click();
    await flush();
    assert.deepEqual(
        h.calls.filter((call) => ['confirm', 'pair_finish'].includes(call.action)).map((call) => call.action),
        ['confirm', 'pair_finish']
    );
    assert.equal(h.booth.flowState, 'sheet-preview');
});

test('chosen directory receives the confirmed JPEG automatically', async () => {
    const writes = [];
    const directory = {
        name: 'Anh SGU',
        queryPermission: async () => 'granted',
        getFileHandle: async (name) => ({
            createWritable: async () => ({
                write: async (blob) => writes.push({ name, size: blob.size }),
                close: async () => {},
                abort: async () => {}
            })
        })
    };
    const window = { isSecureContext: true, showDirectoryPicker: async () => directory };
    const h = harness(
        { ...selecting(), state: 'confirmed', confirmed: true, final: '/data/images/final.jpg', sheet: null },
        { window }
    );
    await h.api.restore();
    h.node('save-directory').click();
    await flush();
    assert.deepEqual(writes, [{ name: 'final.jpg', size: 4 }]);
    assert.match(h.node('save-status').textContent, /Đã tự lưu/);
});

test('save directory can be chosen from the frame selection screen', async () => {
    const writes = [];
    const directory = {
        name: 'Anh SGU',
        queryPermission: async () => 'granted',
        getFileHandle: async (name) => ({
            createWritable: async () => ({
                write: async () => writes.push(name),
                close: async () => {},
                abort: async () => {}
            })
        })
    };
    const h = harness(
        { ...selecting(), state: 'confirmed', confirmed: true, final: '/data/images/final.jpg', sheet: null },
        { window: { isSecureContext: true, showDirectoryPicker: async () => directory } }
    );
    await h.api.restore();
    h.node('[data-template-save-directory]').click();
    await flush();
    assert.deepEqual(writes, ['final.jpg']);
    assert.equal(h.node('[data-template-save-directory]').textContent, 'Đổi nơi lưu ảnh');
    assert.equal(h.node('save-directory').textContent, 'Đổi nơi lưu ảnh');
    assert.match(h.node('[data-template-save-status]').textContent, /Anh SGU/);
});

test('choosing a directory on the A5 preview saves both the frame and print sheet in order', async () => {
    const writes = [];
    const directory = {
        name: 'Anh SGU',
        queryPermission: async () => 'granted',
        getFileHandle: async (name) => ({
            createWritable: async () => ({
                write: async () => writes.push(name),
                close: async () => {},
                abort: async () => {}
            })
        })
    };
    const h = harness(
        {
            ...selecting(),
            state: 'sheet-preview',
            confirmed: true,
            final: '/data/images/final.jpg',
            sheet: '/data/images/a5.jpg',
            sheet_format: 'A5-pair'
        },
        { window: { isSecureContext: true, showDirectoryPicker: async () => directory } }
    );
    await h.api.restore();
    h.node('save-directory').click();
    await flush();
    await flush();
    assert.deepEqual(writes, ['final.jpg', 'a5.jpg']);
});

test('choosing a second frame keeps the first and returns to template selection', async () => {
    const events = [];
    const templateSelection = {
        setSelectedTemplateId() {},
        showError: (message) => events.push(message),
        showSelection: () => events.push('selection')
    };
    const h = harness(
        { ...selecting(), state: 'confirmed', confirmed: true, final: '/data/images/first.jpg', template_id: 'frame' },
        { templateSelection }
    );
    await h.api.restore();
    h.node('second').click();
    await flush();
    assert.equal(h.calls.filter((call) => call.action === 'pair_hold').length, 1);
    assert.equal(h.node('root').hidden, true);
    assert.equal(h.booth.flowState, 'idle');
    assert.match(events[0], /khung thứ nhất/i);
    assert.equal(events[1], 'selection');
});

test('a pending first frame can be cancelled before the next visitor starts', async () => {
    const events = [];
    const templateSelection = {
        showError: (message) => events.push(message),
        showSelection: () => events.push('selection')
    };
    const h = harness(null, { pairPending: true, templateSelection });
    await h.api.restore();
    const cancel = h.node('[data-template-pair-cancel]');
    assert.equal(cancel.hidden, false);
    cancel.click();
    await flush();
    assert.equal(h.calls.filter((call) => call.action === 'pair_cancel').length, 1);
    assert.equal(cancel.hidden, true);
    assert.match(events.at(-2), /Đã hủy ảnh thứ nhất/);
});

test('double start is guarded across countdown and capture', async () => {
    const h = harness({ ...selecting(), state: 'preview', shots: [], target: 1, required: 1 });
    const first = h.api.start();
    await h.api.start();
    await flush();
    await h.advance(1000);
    await h.advance(200);
    await first;
    assert.equal(h.calls.filter((c) => c.action === 'start').length, 1);
    assert.equal(h.calls.filter((c) => c.action === 'capture').length, 1);
    assert.equal(h.booth.flowState, 'selecting');
});

test('preview loss during countdown never sends shutter command', async () => {
    const h = harness({ ...selecting(), state: 'preview', shots: [], target: 1, required: 1 });
    const first = h.api.start();
    await flush();
    h.setReady(false);
    await h.advance(1000);
    await first;
    assert.equal(h.booth.flowState, 'error');
    assert.equal(h.calls.filter((c) => c.action === 'capture').length, 0);
});

test('Cam Link loss while encoding JPEG prevents upload and keeps the session recoverable', async () => {
    const h = harness(browserSession(), { browser: true, losePreviewBeforeBlob: true });
    const first = h.api.start();
    await flush();
    await h.advance(1000);
    await first;
    assert.equal(h.booth.flowState, 'error');
    assert.match(h.node('message').textContent, /Preview đã mất kết nối/);
    assert.equal(h.calls.filter((call) => call.action === 'capture').length, 0);
    assert.equal(h.node('reconnect').hidden, false);
});

test('offline Windows agent reports AGENT_UNREACHABLE instead of a preview error and never sends shutter', async () => {
    const photoCamera = {
        status: {},
        check: async () => {
            photoCamera.status = { agent_online: false, camera_connected: false, capture_ready: false };
            return false;
        }
    };
    const h = harness({ ...selecting(), state: 'preview', shots: [], target: 1, required: 1 }, { photoCamera });
    await h.api.start();
    await flush();
    assert.equal(h.booth.flowState, 'error');
    assert.match(h.node('message').textContent, /Windows Camera Agent chưa chạy/);
    assert.equal(h.node('reconnect').hidden, false);
    assert.equal(h.calls.filter((c) => c.action === 'capture').length, 0);
});

test('restoring a native session without shots returns to the welcome screen', async () => {
    const shown = [];
    const templateSelection = {
        setSelectedTemplateId() {},
        getSelectedTemplateId: () => 'frame',
        showPreview: () => shown.push('preview')
    };
    const h = harness({ ...selecting(), state: 'preview', shots: [], template_id: 'frame' }, { templateSelection });
    await h.api.restore();
    assert.equal(h.node('root').hidden, true);
    assert.equal(h.booth.takingPic, false);
    assert.equal(h.booth.flowState, 'idle');
    assert.deepEqual(shown, ['preview']);
});

test('restoring a native session with shots keeps the live preview visible', async () => {
    const h = harness({
        ...selecting(),
        state: 'preview',
        shots: [{ id: '0', thumbnail: '/api/captureSessionImage.php?shot=0' }]
    });
    await h.api.restore();
    assert.equal(h.node('root').hidden, false);
    assert.equal(h.node('camera').hidden, false);
    assert.equal(h.node('resume').hidden, false);
});

test('the waiting screen covers the last shot and compose until the final JPEG has loaded', async () => {
    const h = harness(browserSession(), { browser: true });
    const first = h.api.start();
    await flush();
    await h.advance(1000);
    // Between shots: no waiting screen, and the dead exit bar is hidden during countdown.
    assert.equal(h.node('waiting').hidden, true);
    assert.equal(h.node('actions').hidden, true);
    await h.advance(1000);
    await first;
    assert.equal(h.booth.flowState, 'final-preview');
    assert.equal(h.node('waiting').hidden, false);
    assert.equal(h.node('message').textContent, '');
    h.node('photo').onload();
    assert.equal(h.node('waiting').hidden, true);
    assert.equal(h.node('actions').hidden, false);
});

test('an empty native session left over after switching to CamLink capture is recreated before capturing', async () => {
    const h = harness(browserSession({ capture_mode: 'native' }), { browser: true, recreate: browserSession() });
    const first = h.api.start();
    await flush();
    await h.advance(1000);
    await h.advance(1000);
    await first;
    assert.deepEqual(h.calls.map((call) => call.action).slice(0, 3), ['start', 'cancel', 'start']);
    assert.ok(h.calls.find((call) => call.action === 'capture').data.get('photo') instanceof Blob);
});

test('one press captures every slot automatically, each with its own countdown, then composes', async () => {
    const h = harness(browserSession(), { browser: true });
    const first = h.api.start();
    await flush();
    await h.advance(1000);
    const upload = h.calls.find((call) => call.action === 'capture');
    assert.ok(upload.data.get('photo') instanceof Blob);
    assert.equal(h.node('template').children[0].children[0].src, '/api/captureSessionImage.php?shot=0');
    // The next countdown starts without another press.
    assert.equal(h.booth.flowState, 'countdown');
    assert.equal(h.node('countdown').hidden, false);
    await h.advance(1000);
    await first;
    assert.equal(h.calls.filter((call) => call.action === 'capture').length, 2);
    assert.equal(h.calls.filter((call) => call.action === 'compose').length, 1);
    assert.equal(h.booth.flowState, 'final-preview');
});

test('a four-slot kiosk session completes four countdowns and one composition', async () => {
    const four = browserSession({
        required: 4,
        target: 4,
        template: {
            ...browserSession().template,
            slots: Array.from({ length: 4 }, (_, index) => ({
                x: index % 2 ? 625 : 50,
                y: index < 2 ? 50 : 925,
                width: 525,
                height: 825,
                fit: 'cover',
                position: 'center',
                rotation: 0
            }))
        }
    });
    const h = harness(four, { browser: true });
    const session = h.api.start();
    await flush();
    for (let shot = 0; shot < 4; shot++) await h.advance(1000);
    await session;
    assert.equal(h.calls.filter((call) => call.action === 'capture').length, 4);
    assert.equal(h.calls.filter((call) => call.action === 'compose').length, 1);
    assert.equal(h.booth.flowState, 'final-preview');
    assert.equal(h.node('template').children.filter((child) => child.children.length === 1).length, 4);
});

test('full browser template composes automatically and retake replaces only the chosen slot', async () => {
    const complete = browserSession({
        state: 'final-preview',
        final: '/data/images/final.jpg',
        selected: ['0', '1'],
        shots: [
            { id: '0', thumbnail: '/api/captureSessionImage.php?shot=0' },
            { id: '1', thumbnail: '/api/captureSessionImage.php?shot=1' }
        ]
    });
    const h = harness(complete, { browser: true });
    await h.api.restore();
    h.node('template').children[1].click();
    h.node('retake').click();
    await flush();
    await h.advance(1000);
    await flush();
    assert.equal(h.calls.filter((call) => call.action === 'replace').length, 1);
    assert.equal(h.calls.filter((call) => call.action === 'compose').length, 1);
    assert.equal(h.calls.find((call) => call.action === 'replace').data.get('slot'), '1');
    assert.equal(h.booth.flowState, 'final-preview');
});

test('native final preview follows selected order and reports thumbnail failures instead of a black slot', async () => {
    const complete = browserSession({
        state: 'final-preview',
        capture_mode: 'native',
        final: '/data/images/native-final.jpg',
        selected: ['2', '0'],
        shots: [
            { id: '0', thumbnail: '/api/captureSessionImage.php?shot=0' },
            { id: '1', thumbnail: '/api/captureSessionImage.php?shot=1' },
            { id: '2', thumbnail: '/api/captureSessionImage.php?shot=2' }
        ]
    });
    const h = harness(complete);
    await h.api.restore();

    const slots = h.node('template').children;
    assert.equal(slots[0].children[0].src, '/api/captureSessionImage.php?shot=2');
    assert.equal(slots[1].children[0].src, '/api/captureSessionImage.php?shot=0');
    assert.equal(h.node('photo').src, '/data/images/native-final.jpg');

    slots[0].children[0].onerror();
    assert.equal(slots[0].dataset.imageState, 'error');
    assert.match(h.node('message').textContent, /Không tải được thumbnail cho ô 1/);
});

const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const { webcrypto } = require('node:crypto');
const source = fs.readFileSync(path.join(__dirname, '../../assets/js/photo-camera.js'), 'utf8');
const deferred = () => { let resolve; const promise = new Promise((done) => { resolve = done; }); return { promise, resolve }; };

function harness(options = {}) {
    const h = { requests: [], events: [], states: [], timers: new Map() };
    h.document = new EventTarget();
    h.document.querySelectorAll = () => [];
    h.document.querySelector = () => null;
    h.document.hidden = false;
    h.booth = { takingPic: false, setFlowState: (state) => h.states.push(state) };
    h.document.addEventListener('photobooth.photo-camera.status', (event) => h.events.push(event.detail));
    const context = vm.createContext({
        config: { windows_agent: { enabled: options.enabled !== false }, sgu: { enabled: options.sgu === true }, dev: {} },
        environment: { publicFolders: { api: '/booth/api' } },
        document: h.document,
        CustomEvent: class extends Event { constructor(name, args) { super(name); this.detail = args.detail; } },
        AbortController, crypto: webcrypto,
        $: () => {},
        photoBooth: h.booth,
        setTimeout: (fn, delay) => { const id = Symbol(); h.timers.set(id, { fn, delay }); return id; },
        clearTimeout: (id) => h.timers.delete(id),
        fetch: async (url, settings) => {
            h.requests.push({ url, settings });
            if (options.error) throw new Error('offline');
            if (options.wait) await options.wait;
            return { ok: true, json: async () => options.status || { success: true, connected: true } };
        }
    });
    vm.runInContext(source, context);
    h.api = vm.runInContext('photoboothPhotoCamera', context);
    return h;
}

test('USB readiness is fetched from PHP, never from the preview state', async () => {
    for (const connected of [true, false]) {
        const h = harness({ status: { success: true, connected } });
        assert.equal(await h.api.check(), connected);
        assert.equal(h.requests[0].url, '/booth/api/cameraStatus.php');
        assert.equal(h.api.status.connected, connected);
        assert.equal(h.api.status.agent_online, true);
        assert.equal(h.api.status.camera_connected, connected);
        assert.equal(h.api.status.capture_ready, connected);
        assert.equal(h.requests[0].settings.headers, undefined);
    }
});

test('agent, USB camera and capture readiness remain distinct', async () => {
    const h = harness({
        status: {
            success: false,
            agent_online: true,
            camera_connected: false,
            camera_busy: false,
            capture_ready: false,
            last_error: 'CAMERA_NOT_CONNECTED',
            error_code: 'CAMERA_NOT_CONNECTED'
        }
    });
    assert.equal(await h.api.check(), false);
    assert.equal(h.api.status.agent_online, true);
    assert.equal(h.api.status.camera_connected, false);
    assert.equal(h.api.status.capture_ready, false);
    assert.equal(h.api.status.last_error, 'CAMERA_NOT_CONNECTED');
});

test('offline agent blocks admission with a specific message', async () => {
    const h = harness({ error: true });
    assert.equal(await h.api.check(), false);
    assert.equal(h.api.status.error_code, 'AGENT_UNREACHABLE');
    assert.equal(h.api.pending, false);
    assert.equal(h.timers.size, 0);
});

test('double clicks cannot both pass a pending readiness check', async () => {
    const wait = deferred();
    const h = harness({ wait: wait.promise });
    const first = h.api.check();
    assert.equal(h.api.pending, true);
    assert.equal(await h.api.check(), false);
    assert.equal(h.requests.length, 1);
    wait.resolve();
    assert.equal(await first, true);
    assert.equal(h.api.pending, false);
});

test('legacy mode does not require or contact the agent', async () => {
    const h = harness({ enabled: false });
    assert.equal(await h.api.check(), true);
    assert.equal(h.requests.length, 0);
});

test('SGU cannot admit capture when the Windows agent is not configured', async () => {
    const h = harness({ enabled: false, sgu: true });
    assert.equal(await h.api.check(), false);
    assert.equal(h.api.status.error_code, 'AGENT_NOT_CONFIGURED');
    assert.equal(h.requests.length, 0);
});

test('malformed or failed backend success never publishes Connected', async () => {
    for (const status of [{ success: false, connected: true }, { success: true, connected: 'true' }, { connected: true }]) {
        const h = harness({ status });
        assert.equal(await h.api.check(), false);
        assert.equal(h.api.status.connected, false);
    }
});

test('a stale progress response cannot replace a completed or failed capture screen', async () => {
    const wait = deferred();
    const h = harness({ wait: wait.promise, status: { success: true, state: 'transferring' } });
    h.api.watchProgress('a'.repeat(32));
    const timer = [...h.timers.entries()].find(([, value]) => value.delay === 1000);
    h.timers.delete(timer[0]);
    const polling = timer[1].fn();
    h.api.stopProgress();
    wait.resolve();
    await polling;
    assert.deepEqual(h.states, []);
    assert.equal(h.timers.size, 0);
});

test('capture IDs are random safe filenames, not user-supplied paths', () => {
    const h = harness();
    const first = h.api.captureId();
    assert.match(first, /^[a-f0-9]{32}$/);
    assert.notEqual(first, h.api.captureId());
});

test('idle USB health polling is single-flight and skips active capture and hidden tabs', async () => {
    const h = harness();
    const flush = async () => { for (let i = 0; i < 15; i++) await Promise.resolve(); };
    const poll = async () => {
        const timer = [...h.timers.entries()].find(([, value]) => value.delay === 10000);
        assert.ok(timer);
        h.timers.delete(timer[0]);
        await timer[1].fn();
    };
    h.api.init(); h.api.init(); await flush();
    assert.equal(h.requests.length, 1);
    await poll(); assert.equal(h.requests.length, 2);
    h.booth.takingPic = true;
    await poll(); assert.equal(h.requests.length, 2);
    h.booth.takingPic = false; h.document.hidden = true;
    await poll(); assert.equal(h.requests.length, 2);
    h.document.hidden = false;
    h.document.dispatchEvent(new Event('visibilitychange'));
    h.document.dispatchEvent(new Event('visibilitychange'));
    await flush();
    assert.equal(h.requests.length, 3);
    assert.equal([...h.timers.values()].filter((timer) => timer.delay === 10000).length, 1);
});

const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const path = require('node:path');

const source = fs.readFileSync(path.join(__dirname, '../../assets/js/save-image.js'), 'utf8');
const deferred = () => {
    let resolve;
    const promise = new Promise((done) => { resolve = done; });
    return { promise, resolve };
};
const failure = (name) => Object.assign(new Error(name), { name });
const flush = async () => { for (let i = 0; i < 40; i++) await Promise.resolve(); };

function harness(options = {}) {
    const h = { calls: [], events: [], timers: new Map(), downloads: [], filename: 'final.jpg', active: true, storage: new Map(), pickerSettingsHistory: [] };
    const label = { textContent: 'Chọn nơi lưu ảnh' };
    h.status = { textContent: '' };
    h.button = {
        disabled: true,
        querySelector: () => label,
        setAttribute(name, value) { this[name] = value; },
        addEventListener(name, handler) { h.click = () => handler({ preventDefault() {}, stopPropagation() {} }); }
    };
    const result = {
        getAttribute: () => h.filename,
        classList: { contains: () => h.active }
    };
    const document = {
        querySelector: (selector) => selector === '[data-stage="result"]' ? result : h.button,
        getElementById: () => h.status,
        dispatchEvent: (event) => h.events.push(event.detail.busy),
        body: { append(link) { h.link = link; } },
        createElement: () => ({
            click() { h.downloads.push({ url: this.href, name: this.download }); },
            remove() { h.removed = true; }
        })
    };
    const blob = new Blob(['finished full-size photo'], { type: 'image/jpeg' });
    const writable = {
        async write(value) { h.calls.push('write'); h.written = value; if (options.writeError) throw options.writeError; },
        async close() { h.calls.push('close'); if (options.close) await options.close(); if (options.closeError) throw options.closeError; },
        async abort() { h.calls.push('abort'); }
    };
    h.handle = {
        name: 'Ảnh kỷ niệm.jpg',
        async createWritable() { h.calls.push('createWritable'); if (options.permissionError) throw options.permissionError; return writable; }
    };
    const window = {
        isSecureContext: options.secure !== false,
        localStorage: {
            getItem(key) { return h.storage.get(key) ?? null; },
            setItem(key, value) { h.storage.set(key, value); }
        },
        showSaveFilePicker: options.unsupported ? undefined : async (settings) => {
            h.calls.push('picker'); h.pickerSettings = settings; h.pickerSettingsHistory.push(settings);
            if (options.pickerError) throw options.pickerError;
            if (options.picker) await options.picker();
            return h.handle;
        }
    };
    vm.runInNewContext(source, {
        document, window, AbortController,
        config: { sgu: { enabled: options.sgu === true } },
        environment: { publicFolders: { images: '/booth/data/images' } },
        CustomEvent: class { constructor(name, args) { this.detail = args.detail; } },
        MutationObserver: class { constructor(callback) { h.refresh = callback; } observe() {} },
        setTimeout: (callback) => { const id = Symbol(); h.timers.set(id, callback); return id; },
        clearTimeout: (id) => h.timers.delete(id),
        fetch: async (url, settings) => {
            h.calls.push('fetch'); h.request = { url, settings };
            if (options.fetch) return options.fetch(settings.signal);
            if (options.fetchError) throw options.fetchError;
            return { ok: options.ok !== false, status: 404, blob: async () => options.blob || blob };
        }
    });
    h.label = label;
    h.blob = blob;
    return h;
}

test('opens the picker synchronously, then saves the complete original only after close succeeds', async () => {
    const closing = deferred();
    const h = harness({ close: () => closing.promise });
    const saving = h.click();
    assert.deepEqual(h.calls, ['picker']);
    assert.equal(h.button.disabled, true);
    await flush();
    assert.equal(h.calls.at(-1), 'close');
    assert.doesNotMatch(h.status.textContent, /Đã lưu/);
    closing.resolve();
    await saving;
    assert.equal(h.request.url, '/booth/data/images/final.jpg');
    assert.equal(h.request.settings.cache, 'no-store');
    assert.equal(h.pickerSettings.suggestedName, 'photobooth-final.jpg');
    assert.equal(h.pickerSettings.types[0].accept['image/jpeg'][0], '.jpg');
    assert.equal(h.written, h.blob);
    assert.match(h.status.textContent, /Đã lưu “Ảnh kỷ niệm.jpg”/);
    assert.deepEqual(h.events, [true, false]);
    assert.equal(h.button.disabled, false);
    assert.equal(h.timers.size, 0);
});

test('cancelling the picker does not download or create a writable file', async () => {
    const h = harness({ pickerError: failure('AbortError') });
    await h.click();
    assert.deepEqual(h.calls, ['picker']);
    assert.equal(h.downloads.length, 0);
    assert.match(h.status.textContent, /Đã hủy/);
    assert.equal(h.button.disabled, false);
    assert.deepEqual(h.events, [true, false]);
});

test('double clicks open only one picker and retain the selected result during navigation', async () => {
    const picking = deferred();
    const h = harness({ picker: () => picking.promise });
    const first = h.click();
    await h.click();
    h.filename = 'next.png';
    h.refresh();
    picking.resolve();
    await first;
    assert.equal(h.calls.filter((name) => name === 'picker').length, 1);
    assert.equal(h.request.url, '/booth/data/images/final.jpg');
    assert.equal(h.status.textContent, '');
    assert.equal(h.button.disabled, false);
});

test('a missing result or inactive result cannot trigger a save', async () => {
    const h = harness();
    for (const name of ['', '../secret.jpg', 'not-an-image.php']) {
        h.filename = name;
        h.refresh();
        assert.equal(h.button.disabled, true);
        await h.click();
    }
    h.filename = 'valid.png';
    h.active = false;
    h.refresh();
    await h.click();
    assert.deepEqual(h.calls, []);
});

test('HTTP, network, HTML and empty responses never open a writable stream', async () => {
    for (const options of [
        { ok: false },
        { fetchError: new Error('offline') },
        { blob: new Blob(['Login page'], { type: 'text/html' }) },
        { blob: new Blob([], { type: 'image/jpeg' }) }
    ]) {
        const h = harness(options);
        await h.click();
        assert.deepEqual(h.calls, ['picker', 'fetch']);
        assert.match(h.status.textContent, /Không tải được ảnh/);
        assert.equal(h.button.disabled, false);
        assert.equal(h.timers.size, 0);
    }
});

test('slow downloads time out, release the button and report failure rather than cancellation', async () => {
    const h = harness({ fetch: (signal) => new Promise((resolve, reject) => {
        signal.addEventListener('abort', () => reject(failure('AbortError')));
    }) });
    const saving = h.click();
    await flush();
    for (const callback of h.timers.values()) callback();
    await saving;
    assert.match(h.status.textContent, /Không tải được ảnh/);
    assert.equal(h.button.disabled, false);
    assert.equal(h.timers.size, 0);
});

test('denied write permission is not reported as saved', async () => {
    const h = harness({ permissionError: failure('NotAllowedError') });
    await h.click();
    assert.deepEqual(h.calls, ['picker', 'fetch', 'createWritable']);
    assert.match(h.status.textContent, /Chưa lưu được ảnh/);
    assert.equal(h.button.disabled, false);
});

test('failed writes and failed commits abort the stream without a false success', async () => {
    for (const options of [{ writeError: failure('AbortError') }, { closeError: new Error('disk full') }]) {
        const h = harness(options);
        await h.click();
        assert.equal(h.calls.at(-1), 'abort');
        assert.match(h.status.textContent, /Chưa lưu được ảnh/);
        assert.equal(h.button.disabled, false);
    }
});

test('unsupported browsers and insecure contexts use an honest native download fallback', async () => {
    for (const options of [{ unsupported: true }, { secure: false }]) {
        const h = harness(options);
        assert.equal(h.label.textContent, 'Tải ảnh về máy');
        assert.match(h.status.textContent, /theo cài đặt trình duyệt/);
        await h.click();
        assert.deepEqual(h.calls, []);
        assert.deepEqual(h.downloads, [{ url: '/booth/data/images/final.jpg', name: 'photobooth-final.jpg' }]);
        assert.equal(h.removed, true);
        assert.match(h.status.textContent, /Đã gửi yêu cầu tải ảnh/);
    }
});

test('each click uses the current finished format, including PNG and video', async () => {
    for (const [filename, mime] of [['collage.png', 'image/png'], ['clip.mp4', 'video/mp4']]) {
        const h = harness({ blob: new Blob(['finished file'], { type: mime }) });
        h.filename = filename;
        h.refresh();
        await h.click();
        assert.equal(h.request.url, '/booth/data/images/' + filename);
        assert.equal(h.pickerSettings.types[0].accept[mime][0], '.' + filename.split('.').pop());
        assert.match(h.status.textContent, /Đã lưu/);
    }
});

test('SGU downloads receive unique sequential names in picker and fallback modes', async () => {
    const picker = harness({ sgu: true });
    await picker.click();
    await picker.click();
    assert.equal(picker.pickerSettingsHistory[0].suggestedName, 'SGU-' + new Date().getFullYear() + String(new Date().getMonth() + 1).padStart(2, '0') + String(new Date().getDate()).padStart(2, '0') + '-001.jpg');
    assert.equal(picker.pickerSettingsHistory[1].suggestedName, picker.pickerSettingsHistory[0].suggestedName.replace('-001.', '-002.'));
    assert.equal(picker.storage.get('photobooth.sgu.download-sequence.v1'), '2');

    const fallback = harness({ sgu: true, unsupported: true });
    await fallback.click();
    await fallback.click();
    assert.equal(fallback.downloads[0].name, picker.pickerSettingsHistory[0].suggestedName);
    assert.equal(fallback.downloads[1].name, picker.pickerSettingsHistory[1].suggestedName);
});

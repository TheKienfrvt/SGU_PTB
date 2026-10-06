const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');

const source = fs.readFileSync('assets/js/template-selection.js', 'utf8');

class Element extends EventTarget {
    constructor() {
        super();
        this.dataset = {};
        this.disabled = false;
        this.hidden = false;
        this.textContent = '';
        this.files = [];
        this.attributes = {};
        const classes = new Set();
        this.classList = {
            add: (...names) => names.forEach((name) => classes.add(name)),
            remove: (...names) => names.forEach((name) => classes.delete(name)),
            contains: (name) => classes.has(name),
            toggle: (name, enabled) => {
                if (enabled) classes.add(name);
                else classes.delete(name);
            }
        };
    }

    setAttribute(name, value) {
        this.attributes[name] = value;
    }

    focus() {}

    click() {
        if (!this.disabled) this.dispatchEvent(new Event('click'));
    }
}

function harness(ids, persisted = new Map(), prepareResult = true) {
    const root = new Element();
    const continueButton = new Element();
    const clearButton = new Element();
    const selectionStatus = new Element();
    const title = new Element();
    const error = new Element();
    const start = new Element();
    const back = new Element();
    const addButton = new Element();
    const uploadPanel = new Element();
    const uploadForm = new Element();
    const uploadFile = new Element();
    const uploadStatus = new Element();
    const uploadSubmit = new Element();
    const uploadCancel = new Element();
    uploadPanel.hidden = true;
    uploadForm.reset = () => {
        uploadFile.files = [];
    };
    const cards = ids.map((id) => {
        const card = new Element();
        card.dataset.templateId = id;
        const name = new Element();
        name.textContent = id;
        card.querySelector = (selector) => selector === '.sgu-template-card__name' ? name : null;
        return card;
    });
    root.classList.add('stage--active');
    root.querySelector = (selector) => {
        if (selector === '[data-template-continue]') return continueButton;
        if (selector === '[data-template-clear]') return clearButton;
        if (selector === '[data-template-selection-status]') return selectionStatus;
        if (selector === '[data-template-error]') return error;
        if (selector === '[data-template-add-open]') return addButton;
        if (selector === '[data-template-upload]') return uploadPanel;
        if (selector === '[data-template-upload-form]') return uploadForm;
        if (selector === '[data-template-upload-file]') return uploadFile;
        if (selector === '[data-template-upload-status]') return uploadStatus;
        if (selector === '[data-template-upload-submit]') return uploadSubmit;
        if (selector === '[data-template-upload-cancel]') return uploadCancel;
        if (selector === 'h1') return title;
        return null;
    };
    let previewCalls = 0;
    let reloadCalls = 0;
    const prepared = [];
    const fetchCalls = [];
    class FormDataStub {
        constructor() {
            this.values = new Map();
        }

        set(key, value) {
            this.values.set(key, value);
        }
    }
    const document = {
        querySelector: (selector) => {
            if (selector === '[data-template-selection]') return root;
            if (selector === '[data-stage="start"]') return start;
            return null;
        },
        querySelectorAll: (selector) => {
            if (selector === '[data-template-select]') return cards;
            if (selector === '[data-template-back]') return [back];
            return [];
        }
    };
    const context = vm.createContext({
        document,
        window: {
            sessionStorage: {
                getItem: (key) => persisted.get(key) || null,
                setItem: (key, value) => persisted.set(key, value),
                removeItem: (key) => persisted.delete(key)
            },
            setTimeout: (callback) => callback(),
            location: { reload: () => reloadCalls++ }
        },
        photoBooth: {
            captureSession: {
                prepare: async (id) => {
                    prepared.push(id);
                    return prepareResult;
                }
            }
        },
        sguKiosk: { activatePreview: () => previewCalls++ },
        csrf: { key: 'csrf', token: 'test-token' },
        environment: { publicFolders: { api: '/api' } },
        FormData: FormDataStub,
        fetch: async (url, options) => {
            fetchCalls.push({ url, options });
            return { ok: true, json: async () => ({ success: true, template: { id: 'uploaded-frame' } }) };
        },
        Event,
        $: (callback) => callback()
    });
    vm.runInContext(source + '\nglobalThis.selection = templateSelection;', context);
    return {
        root,
        continueButton,
        clearButton,
        selectionStatus,
        start,
        back,
        cards,
        error,
        persisted,
        prepared,
        selection: context.selection,
        addButton,
        uploadPanel,
        uploadForm,
        uploadFile,
        uploadStatus,
        uploadSubmit,
        uploadCancel,
        fetchCalls,
        previewCalls: () => previewCalls,
        reloadCalls: () => reloadCalls
    };
}

const flush = async () => {
    for (let i = 0; i < 10; i++) await Promise.resolve();
};

test('continue is locked until a server-rendered card is selected', () => {
    const h = harness(['birthday-01']);
    assert.equal(h.continueButton.disabled, true);
    assert.equal(h.clearButton.disabled, true);
    assert.equal(h.selectionStatus.textContent, 'Chọn một khung ảnh để tiếp tục');
    h.continueButton.click();
    assert.equal(h.previewCalls(), 0);
    assert.equal(h.start.classList.contains('stage--active'), false);
});

test('bỏ chọn clears only the browser selection and disables continuing', () => {
    const h = harness(['birthday-01']);
    h.cards[0].click();
    assert.equal(h.clearButton.disabled, false);
    h.clearButton.click();
    assert.equal(h.selection.getSelectedTemplateId(), '');
    assert.equal(h.cards[0].attributes['aria-pressed'], 'false');
    assert.equal(h.continueButton.disabled, true);
    assert.equal(h.clearButton.disabled, true);
    assert.equal(h.persisted.has('photobooth.sgu.template-id'), false);
    assert.deepEqual(h.prepared, []);
    assert.deepEqual(h.fetchCalls, []);
});

test('continue creates the server session before preview and selection survives back/reload', async () => {
    const h = harness(['birthday-01', 'graduation-01']);
    h.cards[1].click();
    assert.equal(h.selection.getSelectedTemplateId(), 'graduation-01');
    assert.equal(h.root.dataset.selectedTemplateId, 'graduation-01');
    assert.equal(h.cards[1].attributes['aria-pressed'], 'true');
    assert.equal(h.selectionStatus.textContent, 'Đã chọn: graduation-01');
    assert.equal(h.continueButton.disabled, false);

    h.continueButton.click();
    await flush();
    assert.deepEqual(h.prepared, ['graduation-01']);
    assert.equal(h.root.classList.contains('stage--active'), false);
    assert.equal(h.start.classList.contains('stage--active'), true);
    assert.equal(h.previewCalls(), 1);

    h.back.click();
    assert.equal(h.root.classList.contains('stage--active'), true);
    assert.equal(h.cards[1].attributes['aria-pressed'], 'true');

    const reload = harness(['birthday-01', 'graduation-01'], h.persisted);
    assert.equal(reload.selection.getSelectedTemplateId(), 'graduation-01');
    assert.equal(reload.continueButton.disabled, false);
});

test('an empty server-rendered catalog remains a safe empty state', () => {
    const h = harness([]);
    assert.equal(h.continueButton.disabled, true);
    assert.equal(h.selection.getSelectedTemplateId(), '');
    h.continueButton.click();
    assert.equal(h.previewCalls(), 0);
});

test('failed preparation stays in selection and invalid sessions clear remembered frame', async () => {
    const h = harness(['birthday-01'], new Map(), false);
    h.cards[0].click();
    h.continueButton.click();
    await flush();
    assert.equal(h.root.classList.contains('stage--active'), true);
    assert.equal(h.start.classList.contains('stage--active'), false);

    h.selection.requireReselection('Khung đã bị xóa');
    assert.equal(h.selection.getSelectedTemplateId(), '');
    assert.equal(h.continueButton.disabled, true);
    assert.equal(h.error.hidden, false);
    assert.equal(h.error.textContent, 'Khung đã bị xóa');
});

test('operator can upload a PNG frame and the imported card is selected after reload', async () => {
    const h = harness([]);
    h.addButton.click();
    assert.equal(h.uploadPanel.hidden, false);

    h.uploadFile.files = [{ name: 'Mẫu mới.png', type: 'image/png' }];
    h.uploadForm.dispatchEvent(new Event('submit', { cancelable: true }));
    await flush();

    assert.equal(h.fetchCalls.length, 1);
    assert.equal(h.fetchCalls[0].url, '/api/frameTemplates.php');
    assert.equal(h.fetchCalls[0].options.body.values.get('action'), 'add');
    assert.equal(h.fetchCalls[0].options.body.values.get('csrf'), 'test-token');
    assert.equal(h.persisted.get('photobooth.sgu.template-id'), 'uploaded-frame');
    assert.equal(h.reloadCalls(), 1);
});

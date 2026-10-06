/* globals photoBooth sguKiosk csrf */
/* eslint n/no-unsupported-features/node-builtins: "off" */
/* exported templateSelection */

// Cards are rendered by PHP from the validated filesystem catalog. JavaScript
// owns only the selection and the transition into a server-created session.
const templateSelection = (() => {
    const storageKey = 'photobooth.sgu.template-id';
    let root;
    let continueButton;
    let clearButton;
    let selectionStatus;
    let startStage;
    let errorElement;
    let addButton;
    let uploadPanel;
    let uploadForm;
    let uploadFile;
    let uploadStatus;
    let uploadSubmit;
    let selectedId = '';
    let busy = false;
    let uploadBusy = false;

    const cards = () => Array.from(document.querySelectorAll('[data-template-select]'));
    const storage = () => {
        try {
            return window.sessionStorage;
        } catch {
            return null;
        }
    };
    const selectedCard = (id) => cards().find((card) => card.dataset.templateId === id) || null;
    const showError = (message = '') => {
        if (!errorElement) {
            return;
        }
        errorElement.textContent = message;
        errorElement.hidden = message === '';
    };
    const update = () => {
        if (!root || !continueButton) {
            return;
        }
        root.dataset.selectedTemplateId = selectedId;
        cards().forEach((card) => {
            const selected = card.dataset.templateId === selectedId;
            card.setAttribute('aria-pressed', String(selected));
            card.classList.toggle('sgu-template-card--selected', selected);
        });
        if (selectionStatus) {
            const name = selectedCard(selectedId)?.querySelector('.sgu-template-card__name')?.textContent?.trim();
            selectionStatus.textContent = name ? `Đã chọn: ${name}` : 'Chọn một khung ảnh để tiếp tục';
        }
        continueButton.disabled = selectedId === '' || busy;
        continueButton.setAttribute('aria-disabled', String(continueButton.disabled));
        continueButton.textContent = busy ? 'Đang tạo phiên…' : 'Tiếp tục';
        if (clearButton) {
            clearButton.disabled = selectedId === '' || busy;
            clearButton.setAttribute('aria-disabled', String(clearButton.disabled));
        }
    };
    const select = (id) => {
        if (busy || !selectedCard(id)) {
            return false;
        }
        selectedId = id;
        storage()?.setItem(storageKey, selectedId);
        showError();
        update();
        return true;
    };
    const clearSelection = () => {
        if (busy || selectedId === '') {
            return;
        }
        selectedId = '';
        storage()?.removeItem(storageKey);
        showError();
        update();
    };
    const showUploadStatus = (message = '', failed = false) => {
        if (!uploadStatus) {
            return;
        }
        uploadStatus.textContent = message;
        uploadStatus.hidden = message === '';
        uploadStatus.classList.toggle('sgu-template-upload__status--error', failed);
    };
    const setUploadBusy = (enabled) => {
        uploadBusy = enabled;
        if (uploadSubmit) {
            uploadSubmit.disabled = enabled;
            uploadSubmit.textContent = enabled ? 'Đang xử lý…' : 'Thêm khung';
        }
        if (addButton) {
            addButton.disabled = enabled;
        }
    };
    const openUploader = () => {
        if (!uploadPanel || uploadBusy) {
            return;
        }
        showUploadStatus();
        uploadPanel.hidden = false;
        uploadFile?.focus();
    };
    const closeUploader = () => {
        if (!uploadPanel || uploadBusy) {
            return;
        }
        uploadPanel.hidden = true;
        uploadForm?.reset();
        showUploadStatus();
    };
    const uploadTemplate = async (event) => {
        event.preventDefault();
        if (uploadBusy || !uploadForm || !uploadFile) {
            return;
        }
        const file = uploadFile.files?.[0];
        if (!file) {
            showUploadStatus('Vui lòng chọn một file PNG.', true);
            return;
        }

        setUploadBusy(true);
        showUploadStatus('Đang kiểm tra và tạo khung…');
        let completed = false;
        try {
            const formData = new FormData(uploadForm);
            formData.set('action', 'add');
            formData.set(csrf.key, csrf.token);
            const response = await fetch(`${environment.publicFolders.api}/frameTemplates.php`, {
                method: 'POST',
                body: formData,
                credentials: 'same-origin',
                headers: { Accept: 'application/json' }
            });
            const payload = await response.json();
            if (!response.ok || payload.success !== true || typeof payload.template?.id !== 'string') {
                throw new Error(payload.message || 'Không thể thêm khung.');
            }
            storage()?.setItem(storageKey, payload.template.id);
            showUploadStatus('Đã thêm khung. Đang tải lại danh sách…');
            completed = true;
            window.setTimeout(() => window.location.reload(), 350);
        } catch (error) {
            showUploadStatus(error instanceof Error ? error.message : 'Không thể thêm khung. Vui lòng thử lại.', true);
        } finally {
            if (!completed) {
                setUploadBusy(false);
            }
        }
    };
    const showSelection = () => {
        if (!root || !startStage) {
            return;
        }
        startStage.classList.remove('stage--active');
        root.classList.add('stage--active');
        root.querySelector('h1')?.focus({ preventScroll: true });
    };
    const showPreview = () => {
        if (selectedId === '' || !root || !startStage) {
            return;
        }
        root.classList.remove('stage--active');
        startStage.classList.add('stage--active');
        if (typeof sguKiosk !== 'undefined') {
            sguKiosk.activatePreview();
        }
    };
    const continueWithTemplate = async () => {
        if (busy || selectedId === '' || typeof photoBooth === 'undefined' || !photoBooth.captureSession) {
            return;
        }
        busy = true;
        showError();
        update();
        try {
            if (await photoBooth.captureSession.prepare(selectedId)) {
                showPreview();
            }
        } catch {
            showError('Không thể tạo phiên chụp. Vui lòng thử lại.');
        } finally {
            busy = false;
            update();
        }
    };

    return {
        getSelectedTemplateId: () => selectedId,
        setSelectedTemplateId: select,
        showSelection,
        showPreview,
        showError,
        requireReselection: (message) => {
            selectedId = '';
            storage()?.removeItem(storageKey);
            update();
            showError(message || 'Khung đã chọn không còn hợp lệ. Vui lòng chọn lại.');
            showSelection();
        },
        init: () => {
            root = document.querySelector('[data-template-selection]');
            if (!root) {
                return;
            }
            continueButton = root.querySelector('[data-template-continue]');
            clearButton = root.querySelector('[data-template-clear]');
            selectionStatus = root.querySelector('[data-template-selection-status]');
            errorElement = root.querySelector('[data-template-error]');
            addButton = root.querySelector('[data-template-add-open]');
            uploadPanel = root.querySelector('[data-template-upload]');
            uploadForm = root.querySelector('[data-template-upload-form]');
            uploadFile = root.querySelector('[data-template-upload-file]');
            uploadStatus = root.querySelector('[data-template-upload-status]');
            uploadSubmit = root.querySelector('[data-template-upload-submit]');
            startStage = document.querySelector('[data-stage="start"]');
            const remembered = storage()?.getItem(storageKey) || '';
            if (remembered !== '') {
                if (!select(remembered)) {
                    storage()?.removeItem(storageKey);
                    update();
                }
            } else {
                update();
            }
            cards().forEach((card) => card.addEventListener('click', () => select(card.dataset.templateId)));
            continueButton?.addEventListener('click', continueWithTemplate);
            clearButton?.addEventListener('click', clearSelection);
            addButton?.addEventListener('click', openUploader);
            uploadForm?.addEventListener('submit', uploadTemplate);
            root.querySelector('[data-template-upload-cancel]')?.addEventListener('click', closeUploader);
            uploadPanel?.addEventListener('click', (event) => {
                if (event.target === uploadPanel) {
                    closeUploader();
                }
            });
            document.addEventListener?.('keydown', (event) => {
                if (event.key === 'Escape' && uploadPanel?.hidden === false) {
                    closeUploader();
                }
            });
            document.querySelectorAll('[data-template-back]').forEach((button) => {
                button.addEventListener('click', showSelection);
            });
            // This is the mandatory first screen. Session restore may place its
            // own full-screen stage above it a moment later.
            showSelection();
        }
    };
})();

$(function () {
    templateSelection.init();
});

<?php

use Photobooth\Utility\PathUtility;

$escape = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
?>
<section
    class="stage stage--template stage--active"
    data-template-selection
    data-selected-template-id=""
    aria-labelledby="template-selection-title"
>
    <div class="sgu-template-selection">
        <header class="sgu-template-selection__header">
            <div class="sgu-template-selection__brand">
                <img src="<?= PathUtility::getPublicPath('resources/img/sgu/sgu-logo.png') ?>" alt="Đại học Sài Gòn">
                <div>
                    <p>SGU PHOTOBOOTH</p>
                    <h1 id="template-selection-title" tabindex="-1">Chọn khung ảnh</h1>
                </div>
            </div>
            <button type="button" class="button sgu-template-selection__add" data-template-add-open>
                <span aria-hidden="true">+</span> Thêm khung mới
            </button>
        </header>
        <div class="sgu-template-upload" data-template-upload hidden>
            <form class="sgu-template-upload__panel" data-template-upload-form enctype="multipart/form-data" role="dialog" aria-modal="true" aria-labelledby="template-upload-title">
                <h2 id="template-upload-title">Thêm khung ảnh</h2>
                <p>Chọn PNG có các ô đặt ảnh trong suốt. Hệ thống sẽ tự nhận diện ô ảnh và tạo cấu hình.</p>
                <label for="template-upload-name">Tên hiển thị</label>
                <input id="template-upload-name" name="name" type="text" maxlength="120" placeholder="Tự lấy từ tên file nếu để trống">
                <label for="template-upload-file">File khung PNG</label>
                <input id="template-upload-file" name="frame" type="file" accept="image/png,.png" required data-template-upload-file>
                <p class="sgu-template-upload__hint">Tối đa 8 ô ảnh. Các ô phải trong suốt hoàn toàn và không chạm mép ngoài của khung.</p>
                <p class="sgu-template-upload__status" data-template-upload-status role="status" hidden></p>
                <div class="sgu-template-upload__actions">
                    <button type="button" class="button button--secondary" data-template-upload-cancel>Hủy</button>
                    <button type="submit" class="button" data-template-upload-submit>Thêm khung</button>
                </div>
            </form>
        </div>
        <div class="sgu-template-selection__content">
            <div class="sgu-template-selection__intro">
                <span class="sgu-template-selection__step">BƯỚC 01 / CHỌN KHUNG</span>
                <h2>Bắt đầu với một khung ảnh</h2>
                <p>Chọn kiểu bạn thích. Bạn sẽ xem trước camera ở bước tiếp theo.</p>
            </div>
            <?php if ($frameTemplates === []): ?>
                <p class="sgu-template-selection__empty" data-template-empty role="status">
                    Chưa có khung nào. Nhấn “Thêm khung” và chọn file PNG để bắt đầu.
                </p>
            <?php else: ?>
                <p class="sgu-template-selection__help" id="template-selection-help">CÓ <?= count($frameTemplates) ?> KHUNG ẢNH</p>
                <div class="sgu-template-selection__grid" role="list" aria-describedby="template-selection-help">
                    <?php foreach ($frameTemplates as $frame): ?>
                        <button
                            type="button"
                            class="sgu-template-card"
                            data-template-select
                            data-template-id="<?= $escape($frame['id']) ?>"
                            style="--template-aspect: <?= $frame['canvas']['width'] ?> / <?= $frame['canvas']['height'] ?>"
                            aria-pressed="false"
                            role="listitem"
                        >
                            <span class="sgu-template-card__preview">
                                <img src="<?= PathUtility::getPublicPath($frame['thumbnail']) ?>" alt="<?= $escape($frame['name']) ?>">
                                <span class="sgu-template-card__selected" aria-hidden="true">✓</span>
                            </span>
                            <span class="sgu-template-card__details">
                                <span class="sgu-template-card__name"><?= $escape($frame['name']) ?></span>
                                <span class="sgu-template-card__size"><?= $frame['canvas']['width'] ?> × <?= $frame['canvas']['height'] ?> px</span>
                            </span>
                        </button>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
            <p class="sgu-template-selection__error" data-template-error role="alert" hidden></p>
        </div>
        <footer class="sgu-template-selection__actions">
            <div class="sgu-template-selection__status">
                <p class="sgu-template-selection__selection" data-template-selection-status aria-live="polite">Chọn một khung ảnh để tiếp tục</p>
                <p class="sgu-template-selection__save-status" data-template-save-status role="status" aria-live="polite" hidden></p>
            </div>
            <div class="sgu-template-selection__action-buttons">
                <button type="button" class="button button--secondary" data-template-pair-cancel hidden>Hủy ảnh thứ nhất</button>
                <button type="button" class="button sgu-template-selection__save" data-template-save-directory>Chọn nơi lưu ảnh</button>
                <button type="button" class="button sgu-template-selection__clear" data-template-clear disabled aria-disabled="true">Bỏ chọn khung</button>
                <button type="button" class="button sgu-template-selection__continue" data-template-continue disabled aria-disabled="true">Tiếp tục</button>
            </div>
        </footer>
    </div>
</section>

<?php

use Photobooth\Utility\PathUtility;

?>
<section class="sgu-session" data-capture-session hidden aria-label="Phiên chụp SGU">
    <header class="sgu-session__header">
        <img src="<?= PathUtility::getPublicPath('resources/img/sgu/sgu-logo.png') ?>" alt="Đại học Sài Gòn">
        <div><span>SGU PHOTOBOOTH</span><h2 data-session-title tabindex="-1">Chuẩn bị chụp</h2></div>
        <span data-session-counter aria-live="polite"></span>
    </header>
    <div class="sgu-session__body">
        <div class="sgu-session__workspace" data-session-workspace>
            <div class="sgu-session__camera" data-session-camera hidden>
                <div class="sgu-session__countdown" data-session-countdown aria-live="assertive" hidden></div>
            </div>
            <div class="sgu-session__template-shell" data-session-template-shell hidden>
                <div class="sgu-session__template" data-session-template hidden aria-label="Ảnh đang được tự động điền vào khung"></div>
            </div>
            <div class="sgu-session__photo-shell" data-session-photo-shell hidden>
                <img class="sgu-session__photo" data-session-photo alt="Ảnh vừa chụp">
            </div>
        </div>
        <div class="sgu-session__grid" data-session-grid hidden aria-label="Chọn ảnh để ghép"></div>
    </div>
    <p class="sgu-session__message" data-session-message role="status"></p>
    <footer class="sgu-session__actions" data-session-actions>
        <button type="button" data-session-exit>Kết thúc lượt</button>
        <button type="button" data-session-refresh hidden>Kiểm tra trạng thái</button>
        <button type="button" data-session-reconnect hidden>Kết nối lại Cam Link</button>
        <button type="button" data-session-resume hidden>Tiếp tục chụp</button>
        <button type="button" data-session-capture hidden>CHỤP</button>
        <button type="button" data-session-retake hidden disabled>Chụp lại</button>
        <button type="button" data-session-compose hidden disabled>Ghép ảnh đã chọn</button>
        <button type="button" data-session-confirm hidden>Hoàn tất</button>
        <button type="button" data-session-duplicate hidden>Dùng 2 ảnh giống nhau</button>
        <button type="button" data-session-second hidden>Chụp khung thứ hai</button>
        <a class="sgu-session__download" data-session-download hidden download>Tải JPEG</a>
        <button type="button" data-session-print hidden>In ảnh</button>
        <button type="button" data-session-save-directory>Chọn nơi lưu ảnh</button>
        <p class="sgu-session__save-status" data-session-save-status role="status" aria-live="polite"></p>
    </footer>
    <!-- Covers the upload/compose/final-image load after the last shot. -->
    <div class="sgu-session__waiting" data-session-waiting role="status" aria-live="polite" hidden>
        <img src="<?= PathUtility::getPublicPath('resources/img/sgu/sgu-logo.png') ?>" alt="">
        <span class="sgu-session__spinner" aria-hidden="true"></span>
        <p class="sgu-session__waiting-title">Chờ xíu nhé!</p>
        <p class="sgu-session__waiting-text">Đang ghép ảnh của bạn…</p>
    </div>
</section>

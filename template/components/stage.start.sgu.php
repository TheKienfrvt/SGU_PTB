<?php

use Photobooth\Utility\PathUtility;

$sguLogo = PathUtility::getPublicPath('resources/img/sgu/sgu-logo.png');
$sguCampus = PathUtility::getPublicPath('sgu/IMG_5492.JPG');
?>
<!-- SGU kiosk welcome screen. The capture button keeps the established .takePic hook. -->
<div class="stage stage--start stage--sgu rotarygroup" data-stage="start" style="--sgu-campus-image: url('<?= $sguCampus ?>')">
    <div class="sgu-welcome">
        <header class="sgu-welcome__header">
            <img class="sgu-welcome__logo" src="<?= $sguLogo ?>" alt="Đại học Sài Gòn">
            <div class="sgu-camera-status" role="status" aria-live="polite" data-sgu-camera-status>
                <span class="sgu-camera-status__dot" aria-hidden="true"></span>
                <span data-sgu-camera-status-text>Đang kiểm tra camera</span>
            </div>
        </header>

        <main class="sgu-welcome__content">
            <h1>SGU PHOTOBOOTH</h1>
            <p class="sgu-welcome__lead">Lưu lại khoảnh khắc tại SGU</p>

            <div class="sgu-welcome__preview" data-sgu-preview-slot data-ready="false" aria-label="Xem trước camera trực tiếp"></div>
            <p class="sgu-welcome__camera-help" data-sgu-camera-help aria-live="polite">Đang kiểm tra quyền camera…</p>
            <label class="sgu-welcome__device" hidden>Camera xem trước
                <select data-preview-device aria-label="Chọn camera xem trước" disabled></select>
            </label>

            <?php if ($config['button']['force_buzzer'] || !$config['picture']['enabled']): ?>
                <div class="sgu-welcome__fallback">
                    <?php include PathUtility::getAbsolutePath('template/components/actionBtn.php'); ?>
                </div>
            <?php else: ?>
                <button class="button sgu-welcome__start takePic" type="button" disabled aria-disabled="true">
                    <i class="<?= htmlspecialchars($config['icons']['take_picture'], ENT_QUOTES, 'UTF-8') ?>" aria-hidden="true"></i>
                    <span><?= ($config['sgu']['capture_mode'] ?? 'browser') === 'browser' ? 'CHỤP' : 'Bắt đầu chụp' ?></span>
                </button>
            <?php endif; ?>
            <p class="sgu-welcome__start-reason" data-sgu-preflight role="status" aria-live="polite">Đang kiểm tra thiết bị</p>
            <?php if ($config['sgu']['session_enabled']): ?>
                <button class="sgu-welcome__reconnect" type="button" data-template-back>Chọn khung khác</button>
            <?php endif; ?>

            <button class="sgu-welcome__reconnect" type="button" data-sgu-reconnect>
                Cho phép Cam Link
            </button>
            <?php if (($config['sgu']['capture_mode'] ?? 'browser') === 'native' && $config['windows_agent']['enabled']): ?>
                <p class="sgu-welcome__capture-status" data-windows-agent-status role="status">Windows Agent: Đang kiểm tra…</p>
                <p class="sgu-welcome__capture-status" data-photo-camera-status role="status">Photo Camera: Đang kiểm tra kết nối USB…</p>
                <p class="sgu-welcome__capture-status" data-photo-capture-status role="status">Capture: Đang kiểm tra…</p>
                <button class="sgu-welcome__reconnect" type="button" data-photo-camera-reconnect>Thử kết nối lại máy ảnh USB</button>
            <?php else: ?>
                <p class="sgu-welcome__capture-status" data-sgu-capture-status hidden>Đang kiểm tra hệ thống chụp ảnh</p>
            <?php endif; ?>
        </main>

        <footer class="sgu-welcome__footer">
            <span>Chạm màn hình để bắt đầu</span>
            <span aria-hidden="true">•</span>
            <span data-sgu-preflight>Đang kiểm tra thiết bị</span>
        </footer>
    </div>
</div>

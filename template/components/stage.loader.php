
<div class="stage stage--loader rotarygroup" data-stage="loader">
    <div class="stage-inner">
        <?php if ($config['sgu']['enabled']): ?>
            <div class="sgu-capture-preview" data-sgu-capture-preview hidden aria-label="Xem trước camera khi đếm ngược"></div>
            <div class="sgu-capture-header" aria-live="polite">
                <img src="<?= \Photobooth\Utility\PathUtility::getPublicPath('resources/img/sgu/sgu-logo.png') ?>" alt="Đại học Sài Gòn">
                <div>
                    <span class="sgu-capture-header__label">SGU PHOTOBOOTH</span>
                    <span class="sgu-capture-header__status" data-sgu-flow-status>Đang chuẩn bị camera</span>
                </div>
            </div>
        <?php endif; ?>
        <canvas id="video--sensor"></canvas>
        <div class="stage-image <?=$config['picture']['flip']?>"></div>
        <div class="stage-message"></div>
        <div class="buttonbar buttonbar--bottom"></div>
    </div>
</div>

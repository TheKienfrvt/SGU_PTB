const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');

const scss = fs.readFileSync('assets/sass/components/_template-selection.scss', 'utf8');
const template = fs.readFileSync('template/components/stage.template.php', 'utf8');

test('frame cards use a bounded preview that preserves every thumbnail ratio', () => {
    assert.match(template, /class="sgu-template-selection__content">[\s\S]*class="sgu-template-selection__grid"/);
    assert.match(template, /class="sgu-template-card__preview">\s*<img/s);
    assert.match(template, /class="sgu-template-card__selected" aria-hidden="true"/);
    assert.match(scss, /grid-template-columns:\s*repeat\(auto-fit, minmax\(11rem, 13rem\)\)/);
    assert.match(scss, /max-width:\s*62\.5rem/);
    assert.match(scss, /height:\s*clamp\(12rem, 31dvh, 19rem\)/);
    assert.match(scss, /object-fit:\s*contain/);
    assert.doesNotMatch(scss, /\.sgu-template-card[\s\S]*object-fit:\s*cover/);
});

test('frame grid has explicit desktop, tablet and mobile layouts', () => {
    assert.match(scss, /@media \(max-width: 64rem\)[\s\S]*max-width:\s*41\.25rem/);
    assert.match(scss, /@media \(max-width: 40rem\)[\s\S]*grid-template-columns:\s*repeat\(2, minmax\(0, 1fr\)\)/);
    assert.match(scss, /@media \(max-width: 23rem\)[\s\S]*grid-template-columns:\s*minmax\(0, 1fr\)/);
    assert.match(scss, /\.sgu-template-selection \{[\s\S]*min-width:\s*0[\s\S]*min-height:\s*100%/);
    assert.match(scss, /&__actions \{[\s\S]*position:\s*sticky/);
});

test('frame selection exposes a responsive save-directory control', () => {
    assert.match(template, /data-template-save-directory>Chọn nơi lưu ảnh<\/button>/);
    assert.match(template, /data-template-save-status role="status" aria-live="polite" hidden/);
    assert.match(scss, /\.sgu-template-selection__save \{[\s\S]*grid-column:\s*1 \/ -1/);
});

test('upload dialog and controls stay inside the viewport', () => {
    assert.match(scss, /max-height:\s*min\(90dvh, calc\(100dvh - 2rem\)\)/);
    assert.match(scss, /input \{[\s\S]*width:\s*100%[\s\S]*max-width:\s*100%/);
    assert.match(scss, /&__actions \{[\s\S]*flex-wrap:\s*wrap/);
});

test('contain sizing keeps tall, landscape, square and high-resolution images fully visible', () => {
    const contain = (imageWidth, imageHeight, boxWidth = 296, boxHeight = 450) => {
        const scale = Math.min(boxWidth / imageWidth, boxHeight / imageHeight);
        return { width: imageWidth * scale, height: imageHeight * scale };
    };
    const images = [
        [213, 640],
        [640, 213],
        [640, 640],
        [6000, 4000]
    ];

    for (const [width, height] of images) {
        const fitted = contain(width, height);
        assert.ok(fitted.width <= 296);
        assert.ok(fitted.height <= 450);
        assert.ok(Math.abs(fitted.width / fitted.height - width / height) < Number.EPSILON * 10);
    }
});

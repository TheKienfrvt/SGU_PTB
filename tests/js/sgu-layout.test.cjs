const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');

const sessionTemplate = fs.readFileSync('template/components/stage.session.php', 'utf8');
const sessionScss = fs.readFileSync('assets/sass/components/_capture-session.scss', 'utf8');
const kioskScss = fs.readFileSync('assets/sass/components/_sgu-kiosk.scss', 'utf8');
const selectionScss = fs.readFileSync('assets/sass/components/_template-selection.scss', 'utf8');
const index = fs.readFileSync('index.php', 'utf8');
const core = fs.readFileSync('assets/js/core.js', 'utf8');

test('session countdown is owned by the camera preview and cannot take grid space', () => {
    assert.match(
        sessionTemplate,
        /data-session-camera[^>]*>[\s\S]*data-session-countdown[\s\S]*<\/div>\s*<div class="sgu-session__template-shell"/
    );
    assert.match(sessionScss, /&__countdown\s*\{[\s\S]*position:\s*absolute/);
    assert.match(sessionScss, /top:\s*50%[\s\S]*left:\s*50%[\s\S]*transform:\s*translate\(-50%, -50%\)/);
    assert.match(sessionScss, /width:\s*clamp\(6rem, 8vw, 8rem\)/);
    assert.doesNotMatch(sessionScss, /&__countdown\s*\{[\s\S]{0,120}position:\s*relative/);
});

test('all SGU stages use the original campus asset and offline Be Vietnam Pro', () => {
    assert.match(index, /sgu\/IMG_5492\.JPG/);
    assert.match(kioskScss, /font-family:\s*'Be Vietnam Pro'/);
    assert.match(selectionScss, /var\(--sgu-campus-image\)[\s\S]*cover no-repeat/);
    assert.match(sessionScss, /var\(--sgu-campus-image\)[\s\S]*cover no-repeat/);
    assert.doesNotMatch(index, /sgu\/trường\.png/);
});

test('legacy capture countdown is centered in video and loader keeps campus backdrop', () => {
    assert.match(core, /data-sgu-capture-preview\]\s+#preview-wrapper/);
    assert.match(kioskScss, /\.stage--loader\s*\{[\s\S]*isolation:\s*isolate/);
    assert.match(kioskScss, /\.stage--loader\s*\{[\s\S]*background-image:\s*var\(--sgu-campus-image\)/);
    assert.match(kioskScss, /\.sgu-capture-preview\s*\{[\s\S]*\.countdown\s*\{[\s\S]*place-items:\s*center/);
    assert.match(kioskScss, /\.countdown-number\s*\{[\s\S]*width:\s*clamp\(5\.25rem, 7vw, 8rem\)/);
});

test('camera and frame are responsive while final previews remain large and centered', () => {
    const columns = sessionScss.match(/grid-template-columns:\s*minmax\(0, ([\d.]+)fr\) minmax\(15rem, ([\d.]+)fr\)/);
    assert.ok(columns, 'desktop camera and frame columns are defined');
    assert.ok(Number(columns[1]) > Number(columns[2]), 'camera takes more width than the frame');
    assert.match(sessionScss, /\[data-state='final-preview'\][\s\S]*repeat\(2, minmax\(12rem, 25rem\)\)/);
    assert.match(sessionScss, /@media \(max-width: 56rem\), \(orientation: portrait\)[\s\S]*grid-template-columns:\s*minmax\(0, 1fr\)/);
});

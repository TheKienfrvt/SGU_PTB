<?php

/** @var array $config */
require_once '../lib/boot.php';

use Photobooth\Capture\FrameTemplateImporter;
use Photobooth\Utility\PathUtility;

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$respond = static function (array $payload, int $status = 200): never {
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit();
};

$messages = [
    'INVALID_UPLOAD' => 'Không nhận được file khung. Vui lòng chọn lại file PNG.',
    'FILE_TOO_LARGE' => 'File khung vượt quá giới hạn dung lượng cho phép.',
    'INVALID_PNG' => 'Khung phải là file PNG hợp lệ.',
    'INVALID_DIMENSIONS' => 'Kích thước khung không hợp lệ hoặc vượt quá giới hạn xử lý.',
    'NO_PHOTO_SLOTS' => 'Không tìm thấy ô ảnh trong suốt. Hãy xuất PNG với các ô đặt ảnh có nền trong suốt.',
    'INVALID_NAME' => 'Tên khung không hợp lệ.',
    'WRITE_FAILED' => 'Không thể lưu khung. Hãy kiểm tra quyền ghi thư mục templates/frames.',
];

try {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || ($_POST['action'] ?? '') !== 'add') {
        throw new RuntimeException('INVALID_REQUEST');
    }
    if (!$config['sgu']['enabled'] || !$config['sgu']['session_enabled'] || $config['ui']['selfie_mode']) {
        throw new RuntimeException('SESSION_DISABLED');
    }
    checkCsrfOrFail($_POST);

    $upload = $_FILES['frame'] ?? null;
    if (!is_array($upload)) {
        throw new RuntimeException('INVALID_UPLOAD');
    }
    $error = $upload['error'] ?? UPLOAD_ERR_NO_FILE;
    if ($error === UPLOAD_ERR_INI_SIZE || $error === UPLOAD_ERR_FORM_SIZE) {
        throw new RuntimeException('FILE_TOO_LARGE');
    }
    $temporary = $upload['tmp_name'] ?? null;
    $originalName = $upload['name'] ?? null;
    if ($error !== UPLOAD_ERR_OK || !is_string($temporary) || !is_string($originalName)
        || !is_uploaded_file($temporary)) {
        throw new RuntimeException('INVALID_UPLOAD');
    }

    $requestedName = $_POST['name'] ?? '';
    if (!is_string($requestedName)) {
        throw new RuntimeException('INVALID_NAME');
    }
    $template = (new FrameTemplateImporter(
        PathUtility::getAbsolutePath('templates/frames'),
        (int) $config['sgu']['max_pixels'],
        (int) $config['sgu']['max_bytes'],
    ))->importPng($temporary, $requestedName, $originalName);

    $respond([
        'success' => true,
        'message' => 'Đã thêm khung thành công.',
        'template' => $template,
    ], 201);
} catch (RuntimeException $error) {
    $code = $error->getMessage();
    $respond([
        'success' => false,
        'code' => $code,
        'message' => $messages[$code] ?? 'Không thể thêm khung. Vui lòng thử lại.',
    ], in_array($code, ['INVALID_REQUEST', 'SESSION_DISABLED'], true) ? 403 : 422);
} catch (Throwable) {
    $respond([
        'success' => false,
        'code' => 'IMPORT_FAILED',
        'message' => 'Không thể thêm khung. Vui lòng thử lại.',
    ], 500);
}

<?php

namespace Photobooth\Capture;

final class CameraException extends \RuntimeException
{
    public const MESSAGES = [
        'CAMERA_NOT_CONNECTED' => 'Máy ảnh chụp chưa được kết nối qua USB.',
        'CAMERA_BUSY' => 'Máy ảnh đang bận. Vui lòng thử lại.',
        'CAMERA_CONTROLLER_NOT_INSTALLED' => 'Chưa cài bộ điều khiển camera trên Windows.',
        'CAMERA_MODEL_MISMATCH' => 'Máy ảnh USB không khớp model đã cấu hình.',
        'CAPTURE_TIMEOUT' => 'Máy ảnh không trả ảnh trong thời gian cho phép.',
        'CAPTURE_FAILED' => 'Không chụp được ảnh. Kiểm tra máy ảnh rồi thử lại.',
        'TRANSFER_FAILED' => 'Máy ảnh đã chụp nhưng tải JPEG về máy thất bại.',
        'INVALID_IMAGE' => 'File nhận từ máy ảnh không phải JPEG hợp lệ.',
        'INTEGRITY_FAILED' => 'JPEG nhận được không khớp mã kiểm tra của ảnh gốc.',
        'AGENT_UNREACHABLE' => 'Không kết nối được dịch vụ điều khiển camera trên Windows.',
        'AGENT_AUTH_FAILED' => 'Mã kết nối dịch vụ camera chưa đúng. Kiểm tra cấu hình máy chủ.',
        'AGENT_NOT_CONFIGURED' => 'Dịch vụ camera chưa được cấu hình đầy đủ.',
        'STORAGE_FAILED' => 'Không lưu được ảnh gốc. Kiểm tra bộ nhớ máy chủ.',
        'PROCESSING_FAILED' => 'Đã nhận JPEG từ camera nhưng xử lý ảnh thất bại.',
        'CAPTURE_UNCERTAIN' => 'Lượt chụp trước bị gián đoạn. Kiểm tra máy ảnh trước khi chụp lại.',
        'UNSUPPORTED_CAPTURE_MODE' => 'Chế độ Windows Camera Agent chỉ nhận ảnh JPEG từ máy ảnh USB.',
    ];

    public readonly string $errorCode;

    public function __construct(string $errorCode)
    {
        $this->errorCode = isset(self::MESSAGES[$errorCode]) ? $errorCode : 'CAPTURE_FAILED';
        parent::__construct(self::MESSAGES[$this->errorCode]);
    }

    public function response(): array
    {
        return ['success' => false, 'error' => $this->getMessage(), 'error_code' => $this->errorCode];
    }
}

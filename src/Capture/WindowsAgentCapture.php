<?php

namespace Photobooth\Capture;

use Photobooth\Enum\FolderEnum;
use Photobooth\PhotoboothCapture;
use Photobooth\Service\LoggerService;

final class WindowsAgentCapture
{
    public function __construct(private array $settings)
    {
    }

    public function capture(PhotoboothCapture $capture, string $id): array
    {
        OriginalStorage::validateId($id);
        $logger = LoggerService::getInstance()->getLogger('main');
        $start = microtime(true);
        try {
            $client = new WindowsAgentClient($this->settings);
            $logger->info('camera status request', ['capture_id' => $id]);
            $status = $client->status();
            if (($status['connected'] ?? false) !== true) {
                throw new CameraException('CAMERA_NOT_CONNECTED');
            }
            $logger->info('capture requested', ['capture_id' => $id]);
            $metadata = $client->capture($id);
            if (($metadata['capture_id'] ?? '') !== $id) {
                throw new CameraException('TRANSFER_FAILED');
            }
            $logger->info('transfer started', ['capture_id' => $id]);
            $input = $client->download($id);
            try {
                $storage = new OriginalStorage(FolderEnum::DATA->absolute() . '/original', $this->settings['max_bytes']);
                $record = $storage->receive($input, $metadata, $capture->tmpFile, $capture->fileName);
            } finally {
                fclose($input);
            }
            $logger->info('original saved', ['capture_id' => $id, 'sha256' => $record['sha256'],
                'size' => $record['size'], 'output' => $record['work_file'],
                'elapsed_ms' => (int) ((microtime(true) - $start) * 1000)]);
            return $record;
        } catch (CameraException $e) {
            $logger->error('capture failed', ['capture_id' => $id, 'error_code' => $e->errorCode,
                'elapsed_ms' => (int) ((microtime(true) - $start) * 1000)]);
            throw $e;
        }
    }
}

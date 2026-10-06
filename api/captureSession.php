<?php

/** @var array $config */
require_once '../lib/boot.php';

use Photobooth\Capture\CameraException;
use Photobooth\Capture\CapturePolicy;
use Photobooth\Capture\CaptureSession;
use Photobooth\Capture\FrameTemplateCatalog;
use Photobooth\Capture\SessionCommand;
use Photobooth\Capture\SessionImagePipeline;
use Photobooth\Capture\SessionStore;
use Photobooth\Capture\WindowsAgentCapture;
use Photobooth\Enum\FolderEnum;
use Photobooth\PhotoboothCapture;
use Photobooth\Service\DatabaseManagerService;
use Photobooth\Service\LoggerService;
use Photobooth\Service\PrintManagerService;
use Photobooth\Utility\ImageUtility;
use Photobooth\Utility\PathUtility;

header('Content-Type: application/json');
header('Cache-Control: no-store');
$deviceLock = false;
$action = $_POST['action'] ?? 'status';
$id = null;
$service = null;
try {
    if (!is_string($action)) {
        throw new RuntimeException('INVALID_REQUEST');
    }
    if (!$config['sgu']['enabled'] || !$config['sgu']['session_enabled'] || $config['ui']['selfie_mode']) {
        throw new RuntimeException('SESSION_DISABLED');
    }
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        checkCsrfOrFail($_POST);
    } elseif ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        throw new RuntimeException('INVALID_REQUEST');
    }
    if (in_array($action, ['start', 'capture', 'replace'], true)) {
        CapturePolicy::assertAllowed($config, $_POST);
    }

    $catalog = new FrameTemplateCatalog(
        PathUtility::getAbsolutePath('templates/frames'),
        (int) $config['sgu']['max_pixels']
    );
    $store = new SessionStore(session_save_path() . '/capture-sessions');
    $owner = hash('sha256', (string) session_id());
    $service = new CaptureSession($store, $owner);
    $validateTemplate = static function (array $session) use ($catalog): array {
        $templateId = $session['template_id'] ?? null;
        if (!is_string($templateId) || $templateId === '') {
            throw new RuntimeException('INVALID_TEMPLATE');
        }
        $template = $catalog->renderDefinition($templateId);
        $slots = $template['photo_slots'];
        $canvas = $template['canvas'];
        if (count($slots) !== (int) ($session['settings']['required_slots'] ?? 0)
            || (int) $canvas['width'] !== (int) ($session['settings']['output_width'] ?? 0)
            || (int) $canvas['height'] !== (int) ($session['settings']['output_height'] ?? 0)) {
            // The operator changed this template during an active visit. Never
            // silently compose the customer's photos against new geometry.
            throw new RuntimeException('INVALID_TEMPLATE');
        }

        return $template;
    };

    if ($action === 'pair_cancel') {
        unset($_SESSION['print_pair']);
        echo json_encode(['success' => true, 'session' => null, 'pair_pending' => false]);
        exit;
    }

    if ($action === 'start') {
        if (!function_exists('exif_read_data')) {
            throw new RuntimeException('EXIF_UNAVAILABLE');
        }
        $existingId = $_SESSION['capture_session'] ?? null;
        $id = is_string($existingId) ? $existingId : bin2hex(random_bytes(16));
        if ($existingId === null) {
            $submittedTemplateId = $_POST['template_id'] ?? null;
            if (!is_string($submittedTemplateId)) {
                throw new RuntimeException('INVALID_TEMPLATE');
            }
            // The browser selects only an ID; manifest, geometry and paths are
            // loaded and validated from the operator-owned catalog.
            $template = $catalog->renderDefinition($submittedTemplateId);
            $slotCount = count($template['photo_slots']);
            $settings = $config['sgu'];
            if ($config['dev']['demo_images']) {
                $settings['capture_mode'] = 'native';
            }
            $settings['required_slots'] = $slotCount;
            if (($settings['capture_mode'] ?? 'native') === 'browser') {
                $settings['capture_target'] = $slotCount;
            } else {
                $spareShots = max(0, (int) $config['sgu']['capture_target'] - (int) $config['sgu']['required_slots']);
                $settings['capture_target'] = min(8, $slotCount + $spareShots);
            }
            $settings['output_width'] = (int) $template['canvas']['width'];
            $settings['output_height'] = (int) $template['canvas']['height'];
            // Dynamic sessions always use the catalog overlay, never the old
            // global overlay configuration.
            $settings['overlay'] = '';
            $session = $store->create($id, $owner, $settings, $submittedTemplateId);
        } else {
            $session = $service->status($id);
            $validateTemplate($session);
            $submittedTemplateId = $_POST['template_id'] ?? null;
            if (is_string($submittedTemplateId) && $submittedTemplateId !== ''
                && $submittedTemplateId !== ($session['template_id'] ?? null)) {
                throw new RuntimeException('INVALID_TRANSITION');
            }
        }
        $_SESSION['capture_session'] = $id;
        $store->cleanup();
    } else {
        $existingId = $_SESSION['capture_session'] ?? null;
        if (!is_string($existingId) || $existingId === '') {
            echo json_encode([
                'success' => true,
                'session' => null,
                'pair_pending' => is_array($_SESSION['print_pair'] ?? null),
            ]);
            exit;
        }
        $id = $existingId;
        if ($action !== 'status' && ($_POST['session_id'] ?? '') !== $id) {
            // An expired session may be impossible to restore in the browser.
            if ($action === 'cancel' && ($_POST['session_id'] ?? '') === '') {
                try {
                    $service->status($id);
                } catch (RuntimeException $error) {
                    if (in_array($error->getMessage(), ['SESSION_EXPIRED', 'INVALID_SESSION'], true)) {
                        unset($_SESSION['capture_session']);
                        echo json_encode(['success' => true, 'session' => null]);
                        exit;
                    }
                    throw $error;
                }
            }
            throw new RuntimeException('INVALID_SESSION');
        }
        if ($action === 'cancel') {
            try {
                $service->cancel($id);
            } catch (RuntimeException $error) {
                if (!in_array($error->getMessage(), ['SESSION_EXPIRED', 'INVALID_SESSION'], true)) {
                    throw $error;
                }
            }
            unset($_SESSION['capture_session']);
            unset($_SESSION['print_pair']);
            echo json_encode(['success' => true, 'session' => null]);
            exit;
        }

        // Revalidate on restore and before every mutation. A deleted, disabled
        // or modified frame returns the kiosk to selection instead of crashing.
        $current = $service->status($id);
        $template = $validateTemplate($current);
        if ($action === 'pair_hold') {
            if ($current['state'] !== 'confirmed' || ($current['confirmed'] ?? false) !== true
                || !is_string($current['final'] ?? null) || !is_string($current['final_hash'] ?? null)) {
                throw new RuntimeException('INVALID_TRANSITION');
            }
            $final = FolderEnum::IMAGES->absolute() . '/' . $current['final'];
            if (!is_file($final) || !hash_equals($current['final_hash'], (string) hash_file('sha256', $final))) {
                throw new RuntimeException('FINAL_CHANGED');
            }
            $_SESSION['print_pair'] = [
                'filename' => $current['final'],
                'hash' => $current['final_hash'],
                'template_id' => $current['template_id'],
                'created_at' => time(),
            ];
            $service->cancel($id);
            unset($_SESSION['capture_session']);
            echo json_encode(['success' => true, 'session' => null, 'pair_pending' => true]);
            exit;
        }

        $pendingPair = $_SESSION['print_pair'] ?? null;
        if ($action !== 'pair_finish') {
            session_write_close(); // Allow progress requests while camera/print runs.
        }
        if (in_array($action, ['capture', 'replace', 'print'], true)) {
            $lockDirectory = FolderEnum::VAR->absolute() . '/run';
            if (!is_dir($lockDirectory)) {
                mkdir($lockDirectory, 0700, true);
            }
            $deviceLock = fopen($lockDirectory . ($action === 'print' ? '/session-print.lock' : '/windows-camera.lock'), 'c');
            if ($deviceLock === false || !flock($deviceLock, LOCK_EX | LOCK_NB)) {
                throw new RuntimeException('DEVICE_BUSY');
            }
        }
        $receiveCapture = static function (string $destination, string $captureId) use ($config): void {
            if (($config['sgu']['capture_mode'] ?? 'native') === 'browser' && !$config['dev']['demo_images']) {
                $photo = $_FILES['photo'] ?? null;
                if (!is_array($photo) || ($photo['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK
                    || !is_string($photo['tmp_name'] ?? null) || !is_uploaded_file($photo['tmp_name'])) {
                    throw new RuntimeException('INVALID_JPEG');
                }
                $size = filesize($photo['tmp_name']);
                $reportedSize = $photo['size'] ?? null;
                $mime = (new finfo(FILEINFO_MIME_TYPE))->file($photo['tmp_name']);
                $image = @getimagesize($photo['tmp_name']);
                if ($size === false || $size < 1 || $size > (int) $config['sgu']['max_bytes']
                    || !is_int($reportedSize) || $reportedSize !== $size || $mime !== 'image/jpeg'
                    || $image === false || $image['mime'] !== 'image/jpeg'
                    || $image[0] * $image[1] > (int) $config['sgu']['max_pixels']) {
                    throw new RuntimeException('INVALID_JPEG');
                }
                if (!move_uploaded_file($photo['tmp_name'], $destination)) {
                    throw new RuntimeException('CAPTURE_FAILED');
                }
            } elseif ($config['windows_agent']['enabled']) {
                $capture = new PhotoboothCapture();
                $capture->tmpFile = $destination;
                $capture->fileName = 'sgu_' . $captureId . '.jpg';
                (new WindowsAgentCapture($config['windows_agent']))->capture($capture, $captureId);
            } elseif ($config['dev']['demo_images']) {
                $images = ImageUtility::getDemoImages();
                if (!$images || !copy($images[array_rand($images)], $destination)) {
                    throw new RuntimeException('CAPTURE_FAILED');
                }
            } else {
                $command = trim($config['commands']['take_picture']);
                if ($command === '' || SessionCommand::run(sprintf($command, escapeshellarg($destination)), $config['sgu']['command_timeout']) !== 0) {
                    throw new RuntimeException('CAPTURE_FAILED');
                }
            }
        };
        $session = match ($action) {
            'status' => $current,
            'capture' => $service->capture(
                $id,
                filter_var($_POST['index'] ?? null, FILTER_VALIDATE_INT, FILTER_NULL_ON_FAILURE) ?? -1,
                $receiveCapture
            ),
            'replace' => $service->replace(
                $id,
                filter_var($_POST['slot'] ?? null, FILTER_VALIDATE_INT, FILTER_NULL_ON_FAILURE) ?? -1,
                $receiveCapture
            ),
            'compose' => $service->compose(
                $id,
                is_array($_POST['selected'] ?? null) ? $_POST['selected'] : [],
                static function (array $sources, string $filename, array $settings) use ($template): string {
                    $pipeline = new SessionImagePipeline($settings);
                    $final = FolderEnum::IMAGES->absolute() . '/' . $filename;
                    $pipeline->compose($sources, $final, $template);
                    $pipeline->thumbnail($final, FolderEnum::THUMBS->absolute() . '/' . $filename);

                    return (string) hash_file('sha256', $final);
                }
            ),
            'confirm' => $service->confirm($id, static function (string $filename, string $expectedHash) use ($config): void {
                $final = FolderEnum::IMAGES->absolute() . '/' . $filename;
                if (!is_file($final) || !hash_equals($expectedHash, (string) hash_file('sha256', $final))) {
                    throw new RuntimeException('FINAL_CHANGED');
                }
                if ($config['database']['enabled']) {
                    DatabaseManagerService::getInstance()->appendContentToDB($filename);
                }
            }),
            'pair_duplicate' => $service->composePrintSheet(
                $id,
                static function (string $filename, string $expectedHash, string $sheetName, array $settings): string {
                    $source = FolderEnum::IMAGES->absolute() . '/' . $filename;
                    if (!is_file($source) || !hash_equals($expectedHash, (string) hash_file('sha256', $source))) {
                        throw new RuntimeException('FINAL_CHANGED');
                    }
                    $sheet = FolderEnum::IMAGES->absolute() . '/' . $sheetName;
                    $pipeline = new SessionImagePipeline($settings);
                    $pipeline->composePrintSheet([$source, $source], $sheet);
                    $pipeline->thumbnail($sheet, FolderEnum::THUMBS->absolute() . '/' . $sheetName);

                    return (string) hash_file('sha256', $sheet);
                }
            ),
            'pair_finish' => $service->composePrintSheet(
                $id,
                static function (string $filename, string $expectedHash, string $sheetName, array $settings) use ($pendingPair): string {
                    if (!is_array($pendingPair) || !is_string($pendingPair['filename'] ?? null)
                        || !is_string($pendingPair['hash'] ?? null)) {
                        throw new RuntimeException('PAIR_MISSING');
                    }
                    $first = FolderEnum::IMAGES->absolute() . '/' . basename($pendingPair['filename']);
                    $second = FolderEnum::IMAGES->absolute() . '/' . $filename;
                    if (!is_file($first) || !hash_equals($pendingPair['hash'], (string) hash_file('sha256', $first))
                        || !is_file($second) || !hash_equals($expectedHash, (string) hash_file('sha256', $second))) {
                        throw new RuntimeException('FINAL_CHANGED');
                    }
                    $sheet = FolderEnum::IMAGES->absolute() . '/' . $sheetName;
                    $pipeline = new SessionImagePipeline($settings);
                    $pipeline->composePrintSheet([$first, $second], $sheet);
                    $pipeline->thumbnail($sheet, FolderEnum::THUMBS->absolute() . '/' . $sheetName);

                    return (string) hash_file('sha256', $sheet);
                }
            ),
            'print' => $service->print($id, static function (string $filename, string $expectedHash) use ($config): void {
                if (!$config['print']['from_result'] || trim($config['commands']['print']) === '') {
                    throw new RuntimeException('PRINT_DISABLED');
                }
                if (PrintManagerService::getInstance()->isPrintLocked()) {
                    throw new RuntimeException('PRINT_LOCKED');
                }
                $final = FolderEnum::IMAGES->absolute() . '/' . $filename;
                if (!is_file($final)) {
                    throw new RuntimeException('FINAL_MISSING');
                }
                if (!hash_equals($expectedHash, (string) hash_file('sha256', $final))) {
                    throw new RuntimeException('FINAL_CHANGED');
                }
            }, static function (string $filename) use ($config): void {
                // This exact confirmed JPEG was shown in preview. Never
                // re-encode, rotate or decorate it in the print step.
                $path = escapeshellarg(FolderEnum::IMAGES->absolute() . '/' . $filename);
                $command = $config['print']['max_multi'] > 1
                    ? sprintf($config['commands']['print'], 1, $path)
                    : sprintf($config['commands']['print'], $path);
                $exit = SessionCommand::run($command, $config['sgu']['command_timeout']);
                if (!in_array($exit, [0, 238], true)) {
                    throw new RuntimeException('PRINT_UNCERTAIN');
                }
                $manager = PrintManagerService::getInstance();
                if (!$manager->addToPrintDb($filename, $filename)) {
                    throw new RuntimeException('PRINT_UNCERTAIN');
                }
                $count = $manager->getPrintCountFromDB();
                if ($config['print']['limit'] > 0 && $count !== null && $count % $config['print']['limit'] === 0) {
                    $manager->lockPrint();
                }
                if ($count !== null) {
                    file_put_contents($manager->printCounter, $count, LOCK_EX);
                }
            }),
            default => throw new RuntimeException('INVALID_REQUEST'),
        };
        if ($action === 'pair_finish') {
            unset($_SESSION['print_pair']);
            session_write_close();
        }
    }

    $templatePublic = $catalog->get((string) $session['template_id']);
    $public = [
        'id' => $session['id'],
        'state' => $session['state'],
        'target' => $session['settings']['capture_target'],
        'required' => $session['settings']['required_slots'],
        'capture_mode' => $session['settings']['capture_mode'] ?? 'native',
        'template_id' => $session['template_id'],
        'template' => [
            'canvas' => $templatePublic['canvas'],
            'slots' => $templatePublic['photo_slots'],
            'overlay' => PathUtility::getPublicPath($templatePublic['overlay']),
            'background' => $templatePublic['background'] === null
                ? null
                : PathUtility::getPublicPath($templatePublic['background']),
        ],
        'selected' => $session['selected'],
        'confirmed' => (bool) ($session['confirmed'] ?? false),
        'sheet_format' => is_string($session['sheet_format'] ?? null) ? $session['sheet_format'] : null,
        'shots' => array_map(static fn (string $shot): array => [
            'id' => $shot,
            'thumbnail' => FolderEnum::API->public() . '/captureSessionImage.php?session_id=' . $session['id'] . '&shot=' . $shot,
        ], $session['shots']),
        'final' => $session['final'] === null ? null : FolderEnum::IMAGES->public() . '/' . $session['final'],
        'sheet' => ($session['sheet'] ?? null) === null ? null : FolderEnum::IMAGES->public() . '/' . $session['sheet'],
    ];
    echo json_encode([
        'success' => true,
        'session' => $public,
        'pair_pending' => is_array($_SESSION['print_pair'] ?? null),
    ]);
} catch (Throwable $error) {
    $known = [
        'SESSION_BUSY', 'DEVICE_BUSY', 'INVALID_SESSION', 'SESSION_EXPIRED', 'SESSION_DISABLED', 'INVALID_LAYOUT',
        'INVALID_REQUEST', 'INVALID_TRANSITION', 'INVALID_SELECTION', 'INVALID_JPEG', 'INVALID_OVERLAY', 'IMAGE_MEMORY_LIMIT',
        'IMAGE_WRITE_FAILED', 'CAPTURE_FAILED', 'PRINT_DISABLED', 'PRINT_LOCKED', 'FINAL_MISSING', 'FINAL_CHANGED',
        'PRINT_UNCERTAIN', 'COMMAND_UNCERTAIN', 'STORAGE_FULL', 'EXIF_UNAVAILABLE', 'INVALID_TEMPLATE', 'PAIR_MISSING',
    ];
    $code = $error instanceof CameraException ? $error->errorCode
        : (in_array($error->getMessage(), $known, true) ? $error->getMessage() : 'SESSION_FAILED');
    if ($code === 'INVALID_TEMPLATE') {
        if ($service instanceof CaptureSession && is_string($id)) {
            try {
                $service->cancel($id);
            } catch (Throwable) {
                // The stale session remains private and will be cleaned by TTL.
            }
        }
        if (session_status() !== PHP_SESSION_ACTIVE) {
            @session_start();
        }
        unset($_SESSION['capture_session']);
    }
    LoggerService::getInstance()->getLogger('main')->error('SGU session request failed', [
        'code' => $code,
        'action' => is_string($action) ? $action : 'unknown',
    ]);
    http_response_code(in_array($code, ['SESSION_BUSY', 'DEVICE_BUSY'], true) ? 409 : 400);
    echo json_encode([
        'success' => false,
        'error' => $code,
        'reselect_template' => $code === 'INVALID_TEMPLATE',
    ]);
} finally {
    if (is_resource($deviceLock)) {
        flock($deviceLock, LOCK_UN);
        fclose($deviceLock);
    }
}

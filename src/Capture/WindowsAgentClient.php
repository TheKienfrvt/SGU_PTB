<?php

namespace Photobooth\Capture;

/** Only the PHP process holds the agent credential. No URL from agent responses is followed. */
final class WindowsAgentClient
{
    private string $url;
    private string $token;
    private int $timeout;

    public function __construct(array $settings)
    {
        $this->url = rtrim((string) (getenv('PHOTOBOOTH_CAMERA_AGENT_URL') ?: $settings['url']), '/');
        $this->token = (string) (getenv('PHOTOBOOTH_CAMERA_AGENT_TOKEN') ?: $settings['token']);
        $this->timeout = (int) $settings['timeout'];
        $parts = parse_url($this->url);
        if (!is_array($parts) || !in_array($parts['scheme'] ?? '', ['http', 'https'], true)
            || empty($parts['host']) || isset($parts['user']) || isset($parts['pass'])
            || isset($parts['query']) || isset($parts['fragment']) || !empty($parts['path'])
            || strlen($this->token) < 32 || preg_match('/[\r\n]/', $this->token)) {
            throw new CameraException('AGENT_NOT_CONFIGURED');
        }
    }

    /** @return resource */
    private function open(string $path, ?array $body = null, ?int $timeout = null)
    {
        $headers = "Accept: application/json\r\nX-Photobooth-Agent-Token: " . $this->token . "\r\n";
        $options = [
            'method' => $body === null ? 'GET' : 'POST',
            'header' => $headers,
            'timeout' => $timeout ?? $this->timeout,
            'ignore_errors' => true,
            'follow_location' => 0,
        ];
        if ($body !== null) {
            $options['header'] .= "Content-Type: application/json\r\n";
            $options['content'] = json_encode($body, JSON_THROW_ON_ERROR);
        }
        $stream = @fopen($this->url . $path, 'rb', false, stream_context_create(['http' => $options]));
        if ($stream === false) {
            throw new CameraException($body === null ? 'AGENT_UNREACHABLE' : 'CAPTURE_UNCERTAIN');
        }
        $metadata = stream_get_meta_data($stream);
        $lines = $metadata['wrapper_data'] ?? [];
        $status = 0;
        if (is_array($lines)) {
            foreach ($lines as $line) {
                if (is_string($line) && preg_match('/^HTTP\/\S+ (\d{3})/', $line, $match)) {
                    $status = (int) $match[1];
                }
            }
        }
        if ($status < 200 || $status >= 300) {
            $error = json_decode((string) stream_get_contents($stream, 65536), true);
            fclose($stream);
            if ($status === 401 || $status === 403) {
                throw new CameraException('AGENT_AUTH_FAILED');
            }
            throw new CameraException(is_array($error) && is_string($error['error_code'] ?? null)
                ? $error['error_code'] : 'AGENT_UNREACHABLE');
        }
        return $stream;
    }

    public function json(string $path, ?array $body = null, ?int $timeout = null): array
    {
        $stream = $this->open($path, $body, $timeout);
        try {
            $raw = stream_get_contents($stream, 65537);
            $result = is_string($raw) && strlen($raw) <= 65536 ? json_decode($raw, true) : null;
            if (!is_array($result) || ($result['success'] ?? false) !== true) {
                throw new CameraException(is_array($result) && is_string($result['error_code'] ?? null)
                    ? $result['error_code'] : 'TRANSFER_FAILED');
            }
            return $result;
        } finally {
            fclose($stream);
        }
    }

    public function status(): array
    {
        // Native probe is bounded at six seconds; leave headroom for agent cleanup.
        return $this->json('/camera/status', null, 9);
    }

    public function capture(string $id): array
    {
        return $this->json('/capture', ['capture_id' => $id], $this->timeout + 5);
    }

    public function progress(string $id): array
    {
        OriginalStorage::validateId($id);
        return $this->json('/captures/' . $id, null, 3);
    }

    /** @return resource */
    public function download(string $id)
    {
        if (!preg_match('/^[a-f0-9]{32}$/D', $id)) {
            throw new CameraException('TRANSFER_FAILED');
        }
        return $this->open('/captures/' . $id . '/file');
    }
}

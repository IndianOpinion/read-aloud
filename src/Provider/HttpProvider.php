<?php

declare(strict_types=1);

namespace IndianOpinion\ReadAloud\Provider;

use IndianOpinion\ReadAloud\Audio\AudioFormat;
use IndianOpinion\ReadAloud\Exception\AccountException;
use IndianOpinion\ReadAloud\Exception\InvalidAudioException;
use IndianOpinion\ReadAloud\Exception\ProviderConfigException;
use IndianOpinion\ReadAloud\Exception\RateLimitedException;
use IndianOpinion\ReadAloud\Exception\RejectedTextException;
use IndianOpinion\ReadAloud\Exception\StorageException;
use IndianOpinion\ReadAloud\Exception\TransientProviderException;
use IndianOpinion\ReadAloud\Support\Redactor;
use IndianOpinion\ReadAloud\Support\TempFiles;

/**
 * Generic HTTPS adapter for any speech API that takes a JSON request and
 * returns raw audio bytes, or JSON holding base64 audio.
 *
 * Requests go through the WordPress HTTP API. The safe variant is used,
 * redirects are not followed, every address of the host must be public
 * (EndpointGuard) and the connection is pinned to the checked address,
 * unless the filter read_aloud_allow_local_endpoint returns true.
 */
final class HttpProvider extends AbstractProvider
{
    public const MODE_BINARY = 'binary';
    public const MODE_JSON = 'json_base64';

    /** Largest response read from the endpoint: 64 MiB. */
    public const MAX_RESPONSE_BYTES = 67108864;

    public const DEFAULT_BODY = '{"input": "{text}", "voice": "{voice}", "model": "{model}", "response_format": "{format}", "speed": "{speed}"}';

    /** @var array<string, mixed> */
    private array $settings;

    /**
     * @param array<string, mixed> $settings
     */
    public function __construct(array $settings)
    {
        $this->settings = $settings + [
            'endpoint' => '',
            'auth_header' => 'Authorization',
            'auth_template' => 'Bearer {key}',
            'api_key' => '',
            'body_template' => self::DEFAULT_BODY,
            'response_mode' => self::MODE_BINARY,
            'json_pointer' => '/audio',
            'max_chars' => 4000,
            'formats' => 'mp3',
            'billed_header' => '',
            'duration_pointer' => '',
        ];
    }

    public function id(): string
    {
        return 'http';
    }

    public function label(): string
    {
        return 'Generic HTTPS speech API';
    }

    public function maxChars(): int
    {
        return max(100, (int) $this->settings['max_chars']);
    }

    public function formats(): array
    {
        $formats = array_map('trim', explode(',', strtolower((string) $this->settings['formats'])));

        return array_values(array_filter($formats, [AudioFormat::class, 'isKnown']));
    }

    public function settingsFields(): array
    {
        return [
            ['key' => 'endpoint', 'label' => 'Endpoint URL (https)', 'type' => 'url', 'required' => true, 'secret' => false],
            ['key' => 'auth_header', 'label' => 'Key header name', 'type' => 'text', 'required' => false, 'secret' => false, 'default' => 'Authorization'],
            ['key' => 'auth_template', 'label' => 'Key header value template ({key} is replaced)', 'type' => 'text', 'required' => false, 'secret' => false, 'default' => 'Bearer {key}'],
            ['key' => 'api_key', 'label' => 'API key', 'type' => 'password', 'required' => false, 'secret' => true],
            ['key' => 'body_template', 'label' => 'JSON body template', 'type' => 'textarea', 'required' => true, 'secret' => false, 'default' => self::DEFAULT_BODY,
                'help' => 'Placeholders: {text}, {voice}, {model}, {format}, {speed}, {locale}. A value that is exactly one placeholder keeps its type.'],
            ['key' => 'response_mode', 'label' => 'Response', 'type' => 'select', 'required' => true, 'secret' => false, 'default' => self::MODE_BINARY,
                'options' => [self::MODE_BINARY => 'Raw audio bytes', self::MODE_JSON => 'JSON with base64 audio']],
            ['key' => 'json_pointer', 'label' => 'JSON pointer to the audio field', 'type' => 'text', 'required' => false, 'secret' => false, 'default' => '/audio'],
            ['key' => 'max_chars', 'label' => 'Maximum characters per request', 'type' => 'number', 'required' => true, 'secret' => false, 'default' => '4000'],
            ['key' => 'formats', 'label' => 'Supported formats (comma separated: mp3, wav, ogg)', 'type' => 'text', 'required' => true, 'secret' => false, 'default' => 'mp3'],
            ['key' => 'billed_header', 'label' => 'Response header with billed characters (optional)', 'type' => 'text', 'required' => false, 'secret' => false],
            ['key' => 'duration_pointer', 'label' => 'JSON pointer to the duration in seconds (optional, JSON mode)', 'type' => 'text', 'required' => false, 'secret' => false],
        ];
    }

    public function validateSettings(array $settings): array
    {
        $errors = [];
        $endpoint = isset($settings['endpoint']) && is_string($settings['endpoint']) ? trim($settings['endpoint']) : '';
        if ($endpoint === '') {
            $errors[] = 'The endpoint URL is required.';
        } elseif (strtolower((string) parse_url($endpoint, PHP_URL_SCHEME)) !== 'https') {
            $errors[] = 'The endpoint must use HTTPS.';
        } elseif (parse_url($endpoint, PHP_URL_HOST) === null || parse_url($endpoint, PHP_URL_HOST) === false) {
            $errors[] = 'The endpoint URL has no host.';
        }
        try {
            new JsonTemplate(isset($settings['body_template']) && is_string($settings['body_template']) ? $settings['body_template'] : '');
        } catch (ProviderConfigException $e) {
            $errors[] = $e->getMessage();
        }
        $mode = $settings['response_mode'] ?? self::MODE_BINARY;
        if ($mode !== self::MODE_BINARY && $mode !== self::MODE_JSON) {
            $errors[] = 'Unknown response mode.';
        }
        if ($mode === self::MODE_JSON) {
            $pointer = isset($settings['json_pointer']) && is_string($settings['json_pointer']) ? $settings['json_pointer'] : '';
            if ($pointer === '' || $pointer[0] !== '/') {
                $errors[] = 'A JSON pointer such as /audio is required in JSON mode.';
            }
        }
        $max = $settings['max_chars'] ?? 0;
        if (!is_numeric($max) || (int) $max < 100 || (int) $max > 1000000) {
            $errors[] = 'Maximum characters per request must be between 100 and 1,000,000.';
        }
        $formats = array_map('trim', explode(',', strtolower(isset($settings['formats']) && is_string($settings['formats']) ? $settings['formats'] : '')));
        if (array_filter($formats, [AudioFormat::class, 'isKnown']) === []) {
            $errors[] = 'Name at least one supported format: mp3, wav or ogg.';
        }
        $header = isset($settings['auth_header']) && is_string($settings['auth_header']) ? $settings['auth_header'] : '';
        if ($header !== '' && preg_match('/^[A-Za-z0-9\-]+$/', $header) !== 1) {
            $errors[] = 'The key header name may hold letters, digits and hyphens only.';
        }

        return $errors;
    }

    public function synthesize(SpeechRequest $request): SpeechAudio
    {
        $errors = $this->validateSettings($this->settings);
        if ($errors !== []) {
            throw new ProviderConfigException(implode(' ', $errors));
        }
        if (!in_array($request->format, $this->formats(), true)) {
            throw new ProviderConfigException('The provider is not configured for ' . $request->format . '.');
        }
        $endpoint = trim((string) $this->settings['endpoint']);
        $allowLocal = (bool) apply_filters('read_aloud_allow_local_endpoint', false, $endpoint);
        if (!$allowLocal && wp_http_validate_url($endpoint) === false) {
            throw new ProviderConfigException('The endpoint address is refused (private, loopback or invalid).');
        }
        // Every address of the host must be public, and the connection is
        // pinned to the address that was checked. Redirects are off, so this
        // one hop is the only hop.
        $pin = null;
        if (!$allowLocal) {
            $address = EndpointGuard::check($endpoint);
            $host = strtolower(trim((string) parse_url($endpoint, PHP_URL_HOST), '[]'));
            $port = (int) (parse_url($endpoint, PHP_URL_PORT) ?? 443);
            $entry = $host . ':' . $port . ':' . (str_contains($address, ':') ? '[' . $address . ']' : $address);
            $pin = static function ($handle) use ($entry): void {
                if ($handle instanceof \CurlHandle && defined('CURLOPT_RESOLVE')) {
                    curl_setopt($handle, CURLOPT_RESOLVE, [$entry]);
                }
            };
        }
        $template = new JsonTemplate((string) $this->settings['body_template']);
        $body = $template->fill([
            'text' => $request->text,
            'voice' => $request->voice,
            'model' => $request->model,
            'format' => $request->format,
            'speed' => $request->speed,
            'locale' => $request->locale,
        ]);
        $headers = [
            'Content-Type' => 'application/json',
            'Accept' => $this->settings['response_mode'] === self::MODE_JSON ? 'application/json' : 'audio/*, application/octet-stream',
            'X-Request-Id' => $request->requestId,
        ];
        $key = (string) $this->settings['api_key'];
        $headerName = trim((string) $this->settings['auth_header']);
        if ($key !== '' && $headerName !== '') {
            // No line breaks in a header value, whatever was pasted.
            $headers[$headerName] = str_replace(["\r", "\n"], '', str_replace('{key}', $key, (string) $this->settings['auth_template']));
        }
        $tmp = TempFiles::create($request->format);
        $args = [
            'method' => 'POST',
            'timeout' => $request->timeoutSeconds,
            'redirection' => 0,
            'headers' => $headers,
            'body' => $body,
            'stream' => true,
            'filename' => $tmp,
            // A hostile or broken endpoint cannot fill the disk or memory.
            'limit_response_size' => self::MAX_RESPONSE_BYTES,
            'reject_unsafe_urls' => !$allowLocal,
        ];
        if ($pin !== null) {
            add_action('http_api_curl', $pin, 10, 1);
        }
        try {
            $response = $allowLocal ? wp_remote_post($endpoint, $args) : wp_safe_remote_post($endpoint, $args);
        } finally {
            if ($pin !== null) {
                remove_action('http_api_curl', $pin, 10);
            }
        }
        try {
            return $this->interpret($response, $tmp, $request, $key);
        } catch (\Throwable $e) {
            TempFiles::remove($tmp);
            throw $e;
        }
    }

    /**
     * @param array<string, mixed>|\WP_Error $response
     */
    private function interpret(array|\WP_Error $response, string $tmp, SpeechRequest $request, string $key): SpeechAudio
    {
        if ($response instanceof \WP_Error) {
            throw new TransientProviderException(Redactor::clean('Connection failed: ' . $response->get_error_message(), [$key]));
        }
        $status = (int) wp_remote_retrieve_response_code($response);
        $retryAfter = self::retryAfter(self::header($response, 'retry-after'));
        if ($status < 200 || $status > 299) {
            $message = Redactor::clean(sprintf('HTTP %d: %s', $status, $this->head($tmp, 2048)), [$key]);
            if ($status === 401 || $status === 403 || $status === 402) {
                throw new AccountException($message, $status);
            }
            if ($status === 429) {
                throw new RateLimitedException($message, $retryAfter, $status);
            }
            if ($status === 408 || $status >= 500) {
                throw new TransientProviderException($message, $retryAfter, $status);
            }
            if ($status === 400 || $status === 422) {
                throw new RejectedTextException($message);
            }
            throw new ProviderConfigException($message);
        }
        $size = is_file($tmp) ? filesize($tmp) : false;
        if ($size !== false && $size >= self::MAX_RESPONSE_BYTES) {
            throw new InvalidAudioException('The response is larger than the 64 MiB limit and was cut off.');
        }
        $contentType = strtolower(self::header($response, 'content-type'));
        $billed = null;
        $billedHeader = trim((string) $this->settings['billed_header']);
        if ($billedHeader !== '') {
            $value = self::header($response, strtolower($billedHeader));
            $billed = ctype_digit($value) ? (int) $value : null;
        }
        $duration = null;
        if ($this->settings['response_mode'] === self::MODE_JSON) {
            if (!str_contains($contentType, 'json')) {
                throw new InvalidAudioException('Expected a JSON response, got ' . ($contentType === '' ? 'no content type' : $contentType) . '.');
            }
            $raw = (string) file_get_contents($tmp);
            try {
                $document = json_decode($raw, false, 64, JSON_THROW_ON_ERROR);
            } catch (\JsonException $e) {
                throw new InvalidAudioException('The JSON response cannot be parsed.');
            }
            $field = JsonTemplate::pointer($document, (string) $this->settings['json_pointer']);
            if (!is_string($field) || $field === '') {
                throw new InvalidAudioException('The audio field is missing from the JSON response.');
            }
            if (str_starts_with($field, 'data:')) {
                $field = substr($field, (int) strpos($field, ',') + 1);
            }
            $audio = base64_decode($field, true);
            if ($audio === false) {
                throw new InvalidAudioException('The audio field is not valid base64.');
            }
            if (file_put_contents($tmp, $audio) !== strlen($audio)) {
                throw new StorageException('Cannot write the decoded audio.');
            }
            $durationPointer = trim((string) $this->settings['duration_pointer']);
            if ($durationPointer !== '') {
                $value = JsonTemplate::pointer($document, $durationPointer);
                $duration = is_numeric($value) ? (float) $value : null;
            }
        } elseif (!str_starts_with($contentType, 'audio/') && !str_starts_with($contentType, 'application/octet-stream')) {
            throw new InvalidAudioException('Expected audio, got ' . ($contentType === '' ? 'no content type' : $contentType) . '.');
        }
        $requestId = self::header($response, 'x-request-id');

        return new SpeechAudio(
            $tmp,
            $request->format,
            AudioFormat::mime($request->format),
            $billed,
            $duration,
            $requestId !== '' ? $requestId : null
        );
    }

    private function head(string $path, int $bytes): string
    {
        if (!is_file($path)) {
            return '';
        }
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            return '';
        }
        $head = (string) fread($handle, max(1, $bytes));
        fclose($handle);

        return $head;
    }

    /**
     * One response header as a string (several values are joined).
     *
     * @param array<string, mixed> $response
     */
    private static function header(array $response, string $name): string
    {
        $value = wp_remote_retrieve_header($response, $name);

        return is_array($value) ? implode(', ', array_map('strval', $value)) : $value;
    }

    /** Parse Retry-After as seconds or an HTTP date. */
    public static function retryAfter(string $value): ?int
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        if (ctype_digit($value)) {
            return (int) $value;
        }
        $time = strtotime($value);

        return $time === false ? null : max(0, $time - time());
    }
}

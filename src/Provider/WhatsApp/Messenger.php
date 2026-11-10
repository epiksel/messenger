<?php
declare(strict_types=1);

namespace MyMessenger\Provider\WhatsApp;

use MyMessenger\Contracts\MessengerInterface;
use MyMessenger\Contracts\ValidatesCredentialsInterface;
use MyMessenger\Core\AbstractMessenger;
use MyMessenger\Credentials\CredentialBag;
use MyMessenger\Enums\Capability;
use MyMessenger\Exceptions\MessengerApiException;
use MyMessenger\Exceptions\MessengerException;
use MyMessenger\Logging\LoggerInterface;
use MyMessenger\ValueObject\MessageResult;
use MyMessenger\ValueObject\ValidationResult;

final class Messenger extends AbstractMessenger implements MessengerInterface, ValidatesCredentialsInterface {
    private const string API_BASE = 'https://graph.facebook.com/v18.0';

    public function __construct(
        CredentialBag $credentials,
        array $config = [],
        ?LoggerInterface $logger = null,
        ?\GuzzleHttp\ClientInterface $httpClient = null,
    ) {
        if ($httpClient !== null) {
            $config['http_client'] = $httpClient;
        }

        parent::__construct($credentials, $config, $logger);

        if ($this->httpClient === null) {
            throw new MessengerException(
                'The guzzlehttp/guzzle package was not found. Guzzle 8 must be installed for WhatsApp requests.'
            );
        }
    }

    public function platform(): string {
        return 'whatsapp';
    }

    /**
     * @param array<string, mixed> $options
     */
    public function sendMessage(string $recipientId, string $text, array $options = []): MessageResult {
        $this->assertCapability(Capability::SEND_MESSAGE);

        $payload = [
            'messaging_product' => 'whatsapp',
            'to' => $recipientId,
            'text' => ['body' => $text],
            ...$options,
        ];

        return $this->execute('send', fn () => $this->invokeApi('messages', $payload));
    }

    /**
     * @param resource|string $photo
     * @param array<string, mixed> $options
     */
    public function sendPhoto(string $recipientId, mixed $photo, array $options = []): MessageResult {
        $this->assertCapability(Capability::SEND_PHOTO);

        $payload = [
            'messaging_product' => 'whatsapp',
            'to' => $recipientId,
            'type' => 'image',
            'image' => ['link' => $this->normalizeMedia($photo, 'image')],
            ...$options,
        ];

        return $this->execute('send', fn () => $this->invokeApi('messages', $payload));
    }

    /**
     * @param resource|string $document
     * @param array<string, mixed> $options
     */
    public function sendDocument(string $recipientId, mixed $document, array $options = []): MessageResult {
        $this->assertCapability(Capability::SEND_DOCUMENT);

        $payload = [
            'messaging_product' => 'whatsapp',
            'to' => $recipientId,
            'type' => 'document',
            'document' => ['link' => $this->normalizeMedia($document, 'document')],
            ...$options,
        ];

        return $this->execute('send', fn () => $this->invokeApi('messages', $payload));
    }

    /**
     * @param resource|string $video
     * @param array<string, mixed> $options
     */
    public function sendVideo(string $recipientId, mixed $video, array $options = []): MessageResult {
        $this->assertCapability(Capability::SEND_VIDEO);

        $payload = [
            'messaging_product' => 'whatsapp',
            'to' => $recipientId,
            'type' => 'video',
            'video' => ['link' => $this->normalizeMedia($video, 'video')],
            ...$options,
        ];

        return $this->execute('send', fn () => $this->invokeApi('messages', $payload));
    }

    /**
     * @param array<string, mixed> $options
     */
    public function sendLocation(string $recipientId, float $latitude, float $longitude, array $options = []): MessageResult {
        $this->assertCapability(Capability::SEND_LOCATION);

        $payload = [
            'messaging_product' => 'whatsapp',
            'to' => $recipientId,
            'type' => 'location',
            'location' => [
                'latitude' => $latitude,
                'longitude' => $longitude,
            ],
            ...$options,
        ];

        return $this->execute('send', fn () => $this->invokeApi('messages', $payload));
    }

    /**
     * @param array<string, mixed> $options
     */
    public function sendContact(string $recipientId, string $phoneNumber, string $firstName, array $options = []): MessageResult {
        $this->assertCapability(Capability::SEND_CONTACT);

        $payload = [
            'messaging_product' => 'whatsapp',
            'to' => $recipientId,
            'type' => 'contacts',
            'contacts' => [[
                'phones' => [['phone' => $phoneNumber]],
                'name' => ['first_name' => $firstName],
            ]],
            ...$options,
        ];

        return $this->execute('send', fn () => $this->invokeApi('messages', $payload));
    }

    /**
     * @param array<string, mixed> $options
     */
    private function invokeApi(string $method, array $payload = []): array {
        if ($this->httpClient === null) {
            throw new MessengerException('HTTP client is unavailable (guzzlehttp/guzzle is not installed).');
        }

        $options = $this->requestOptions();
        
        // Add authentication headers
        $options['headers'] = [
            'Content-Type' => 'application/json',
            'Authorization' => 'Bearer ' . $this->token,
        ];

        // For WhatsApp, we need to use POST with JSON body
        if ($payload !== []) {
            $options['json'] = $payload;
        }

        try {
            $response = $this->httpClient->request('POST', $this->endpoint($method), $options);
        } catch (\GuzzleHttp\Exception\GuzzleException $exception) {
            // Connection/timeout errors are bubbled up for the retry mechanism.
            if ($exception instanceof \GuzzleHttp\Exception\ConnectException) {
                throw $exception;
            }

            $decoded = $this->decodeErrorBody($exception);
            if ($decoded !== null) {
                throw new MessengerApiException(
                    apiCode: $decoded['error_code'] ?? null,
                    message: (string) ($decoded['description'] ?? $exception->getMessage()),
                    code: (int) ($decoded['error_code'] ?? 0),
                    previous: $exception,
                );
            }

            throw $exception;
        }

        $body = (string) $response->getBody();
        $decoded = json_decode($body, true);

        if (!is_array($decoded)) {
            throw new MessengerApiException(
                apiCode: null,
                message: 'Could not decode WhatsApp response (invalid JSON).',
            );
        }

        // WhatsApp API returns success with a specific format
        if (($decoded['error'] ?? null) !== null) {
            throw new MessengerApiException(
                apiCode: $decoded['error']['code'] ?? null,
                message: (string) ($decoded['error']['message'] ?? 'WhatsApp request failed.'),
                code: (int) ($decoded['error']['code'] ?? 0),
            );
        }

        return $decoded;
    }

    private function endpoint(string $method): string {
        return self::API_BASE . '/' . $method;
    }

    public function validateCredentials(): ValidationResult {
        if (trim($this->token) === '') {
            return ValidationResult::invalid('whatsapp', ValidationResult::EMPTY_VALUE, 'Access token cannot be empty.');
        }

        // Test API connection with a simple GET request
        try {
            $result = $this->getAccountInfo();
            if ($result->success) {
                return ValidationResult::valid(
                    'whatsapp',
                    'Access token is valid.',
                    []
                );
            } else {
                return ValidationResult::invalid(
                    'whatsapp',
                    ValidationResult::UNAUTHORIZED,
                    'Invalid access token.'
                );
            }
        } catch (\Exception $e) {
            return ValidationResult::invalid(
                'whatsapp',
                ValidationResult::UNAUTHORIZED,
                'Invalid access token or connection error: ' . $e->getMessage()
            );
        }
    }

    /**
     * @param array<string, mixed> $options
     */
    public function validateRecipient(string $recipientId, array $options = []): ValidationResult {
        // For WhatsApp, we can only validate the phone number format
        if (trim($recipientId) === '') {
            return ValidationResult::invalid('whatsapp', ValidationResult::EMPTY_VALUE, 'Phone number cannot be empty.');
        }

        // Simple phone number validation (international format)
        if (!preg_match('/^\d{10,15}$/', trim($recipientId))) {
            return ValidationResult::invalid(
                'whatsapp',
                ValidationResult::INVALID_FORMAT,
                'Invalid phone number format. Expected: 10-15 digits'
            );
        }

        return ValidationResult::valid(
            'whatsapp',
            'Phone number is valid.',
            ['phone' => $recipientId]
        );
    }
    
    /**
     * @param array<string, mixed> $options
     */
    public function getAccountInfo(array $options = []): MessageResult {
        $this->assertCapability(Capability::GET_ACCOUNT_INFO);

        return $this->execute('get', fn () => $this->invokeApi('', $options));
    }
}
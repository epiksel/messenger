<?php
declare(strict_types=1);

namespace MyMessenger\Provider\Facebook;

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
                'The guzzlehttp/guzzle package was not found. Guzzle 8 must be installed for Facebook Messenger requests.'
            );
        }
    }

    public function platform(): string {
        return 'facebook';
    }

    /**
     * @param array<string, mixed> $options
     */
    public function sendMessage(string $recipientId, string $text, array $options = []): MessageResult {
        $this->assertCapability(Capability::SEND_MESSAGE);

        $payload = [
            'messaging_type' => 'UPDATE',
            'recipient' => ['id' => $recipientId],
            'message' => ['text' => $text],
            ...$options,
        ];

        return $this->execute('send', fn () => $this->invokeApi('me/messages', $payload));
    }

    /**
     * @param resource|string $photo
     * @param array<string, mixed> $options
     */
    public function sendPhoto(string $recipientId, mixed $photo, array $options = []): MessageResult {
        $this->assertCapability(Capability::SEND_PHOTO);

        $payload = [
            'messaging_type' => 'UPDATE',
            'recipient' => ['id' => $recipientId],
            'message' => [
                'attachment' => [
                    'type' => 'image',
                    'payload' => [
                        'url' => $this->normalizeMedia($photo, 'image'),
                        'is_reusable' => true,
                    ],
                ],
            ],
            ...$options,
        ];

        return $this->execute('send', fn () => $this->invokeApi('me/messages', $payload));
    }

    /**
     * @param resource|string $document
     * @param array<string, mixed> $options
     */
    public function sendDocument(string $recipientId, mixed $document, array $options = []): MessageResult {
        $this->assertCapability(Capability::SEND_DOCUMENT);

        $payload = [
            'messaging_type' => 'UPDATE',
            'recipient' => ['id' => $recipientId],
            'message' => [
                'attachment' => [
                    'type' => 'file',
                    'payload' => [
                        'url' => $this->normalizeMedia($document, 'document'),
                        'is_reusable' => true,
                    ],
                ],
            ],
            ...$options,
        ];

        return $this->execute('send', fn () => $this->invokeApi('me/messages', $payload));
    }

    /**
     * @param array<string, mixed> $options
     */
    public function sendLocation(string $recipientId, float $latitude, float $longitude, array $options = []): MessageResult {
        $this->assertCapability(Capability::SEND_LOCATION);

        $payload = [
            'messaging_type' => 'UPDATE',
            'recipient' => ['id' => $recipientId],
            'message' => [
                'attachment' => [
                    'type' => 'location',
                    'payload' => [
                        'coordinates' => [
                            'lat' => $latitude,
                            'long' => $longitude,
                        ],
                    ],
                ],
            ],
            ...$options,
        ];

        return $this->execute('send', fn () => $this->invokeApi('me/messages', $payload));
    }

    /**
     * @param array<string, mixed> $options
     */
    public function sendQuickReplies(string $recipientId, string $text, array $quickReplies, array $options = []): MessageResult {
        $this->assertCapability(Capability::SEND_MESSAGE);

        $payload = [
            'messaging_type' => 'UPDATE',
            'recipient' => ['id' => $recipientId],
            'message' => [
                'text' => $text,
                'quick_replies' => $quickReplies,
            ],
            ...$options,
        ];

        return $this->execute('send', fn () => $this->invokeApi('me/messages', $payload));
    }

    /**
     * @param array<string, mixed> $options
     */
    public function sendButtonTemplate(string $recipientId, string $text, array $buttons, array $options = []): MessageResult {
        $this->assertCapability(Capability::SEND_MESSAGE);

        $payload = [
            'messaging_type' => 'UPDATE',
            'recipient' => ['id' => $recipientId],
            'message' => [
                'attachment' => [
                    'type' => 'template',
                    'payload' => [
                        'template_type' => 'button',
                        'text' => $text,
                        'buttons' => $buttons,
                    ],
                ],
            ],
            ...$options,
        ];

        return $this->execute('send', fn () => $this->invokeApi('me/messages', $payload));
    }

    /**
     * @param array<string, mixed> $options
     */
    public function sendGenericTemplate(string $recipientId, array $elements, array $options = []): MessageResult {
        $this->assertCapability(Capability::SEND_MESSAGE);

        $payload = [
            'messaging_type' => 'UPDATE',
            'recipient' => ['id' => $recipientId],
            'message' => [
                'attachment' => [
                    'type' => 'template',
                    'payload' => [
                        'template_type' => 'generic',
                        'elements' => $elements,
                    ],
                ],
            ],
            ...$options,
        ];

        return $this->execute('send', fn () => $this->invokeApi('me/messages', $payload));
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

        // For Facebook Messenger, we need to use POST with JSON body
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
                message: 'Could not decode Facebook Messenger response (invalid JSON).',
            );
        }

        // Facebook Messenger API returns success with a specific format
        if (($decoded['error'] ?? null) !== null) {
            throw new MessengerApiException(
                apiCode: $decoded['error']['code'] ?? null,
                message: (string) ($decoded['error']['message'] ?? 'Facebook Messenger request failed.'),
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
            return ValidationResult::invalid('facebook', ValidationResult::EMPTY_VALUE, 'Access token cannot be empty.');
        }

        // Test API connection with a simple GET request
        try {
            $result = $this->getAccountInfo();
            if ($result->success) {
                return ValidationResult::valid(
                    'facebook',
                    'Access token is valid.',
                    []
                );
            } else {
                return ValidationResult::invalid(
                    'facebook',
                    ValidationResult::UNAUTHORIZED,
                    'Invalid access token.'
                );
            }
        } catch (\Exception $e) {
            return ValidationResult::invalid(
                'facebook',
                ValidationResult::UNAUTHORIZED,
                'Invalid access token or connection error: ' . $e->getMessage()
            );
        }
    }

    /**
     * @param array<string, mixed> $options
     */
    public function validateRecipient(string $recipientId, array $options = []): ValidationResult {
        // For Facebook Messenger, we can only validate the user ID format
        if (trim($recipientId) === '') {
            return ValidationResult::invalid('facebook', ValidationResult::EMPTY_VALUE, 'User ID cannot be empty.');
        }

        // Simple user ID validation (numeric ID)
        if (!preg_match('/^\d+$/', trim($recipientId))) {
            return ValidationResult::invalid(
                'facebook',
                ValidationResult::INVALID_FORMAT,
                'Invalid user ID format. Expected: numeric ID'
            );
        }

        return ValidationResult::valid(
            'facebook',
            'User ID is valid.',
            ['user_id' => $recipientId]
        );
    }
    
    /**
     * @param array<string, mixed> $options
     */
    public function getAccountInfo(array $options = []): MessageResult {
        $this->assertCapability(Capability::GET_ACCOUNT_INFO);

        return $this->execute('get', fn () => $this->invokeApi('me', $options));
    }

    /**
     * @param array<string, mixed> $options
     */
    public function setWebhook(string $url, array $options = []): MessageResult {
        $this->assertCapability(Capability::SET_WEBHOOK);

        $payload = [
            'object' => 'page',
            'callback_url' => $url,
            'verify_token' => $options['verify_token'] ?? '',
            'fields' => $options['fields'] ?? ['messages', 'messaging_postbacks', 'message_reads'],
        ];

        return $this->execute('set_webhook', fn () => $this->invokeApi('me/subscriptions', $payload));
    }

    /**
     * @param array<string, mixed> $options
     */
    public function getWebhookInfo(array $options = []): MessageResult {
        $this->assertCapability(Capability::GET_WEBHOOK_INFO);

        return $this->execute('get_webhook_info', fn () => $this->invokeApi('me/subscriptions', $options));
    }

    /**
     * @param array<string, mixed> $options
     */
    public function deleteWebhook(array $options = []): MessageResult {
        $this->assertCapability(Capability::DELETE_WEBHOOK);

        return $this->execute('delete_webhook', fn () => $this->invokeApi('me/subscriptions', $options));
    }
}
<?php
declare(strict_types=1);

namespace MyMessenger\Provider\Netgsm;

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
    private const string API_BASE = 'https://api.netgsm.com.tr';

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
                'The guzzlehttp/guzzle package was not found. Guzzle 8 must be installed for NetGSM requests.'
            );
        }
    }

    public function platform(): string {
        return 'netgsm';
    }

    /**
     * @param array<string, mixed> $options
     */
    public function sendMessage(string $recipientId, string $text, array $options = []): MessageResult {
        $this->assertCapability(Capability::SEND_MESSAGE);

        $payload = [
            'phone' => $recipientId,
            'message' => $text,
            ...$options,
        ];

        return $this->execute('sms/send', fn () => $this->invokeApi('sms/send', $payload));
    }

    /**
     * @param array<string, mixed> $options
     */
    public function smsGonder(string $recipientId, string $text, array $options = []): MessageResult {
        $this->assertCapability(Capability::SEND_MESSAGE);

        $payload = [
            'phone' => $recipientId,
            'message' => $text,
            ...$options,
        ];

        return $this->execute('sms/send', fn () => $this->invokeApi('sms/send', $payload));
    }

    /**
     * @param array<string, mixed> $options
     */
    public function bakiyeSorgula(array $options = []): MessageResult {
        $this->assertCapability(Capability::GET_ACCOUNT_INFO);

        return $this->execute('balance/inquiry', fn () => $this->invokeApi('balance/inquiry', $options));
    }

    /**
     * @param array<string, mixed> $options
     */
    public function krediGonder(string $recipientId, string $amount, array $options = []): MessageResult {
        $this->assertCapability(Capability::SEND_MESSAGE);

        $payload = [
            'phone' => $recipientId,
            'amount' => $amount,
            ...$options,
        ];

        return $this->execute('credit/send', fn () => $this->invokeApi('credit/send', $payload));
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

        // For NetGSM, we need to use POST with JSON body
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
                message: 'Could not decode NetGSM response (invalid JSON).',
            );
        }

        // NetGSM API returns success with a specific format
        if (($decoded['status'] ?? '') !== 'success') {
            throw new MessengerApiException(
                apiCode: $decoded['error_code'] ?? null,
                message: (string) ($decoded['message'] ?? 'NetGSM request failed.'),
                code: (int) ($decoded['error_code'] ?? 0),
            );
        }

        return $decoded;
    }

    private function endpoint(string $method): string {
        return self::API_BASE . '/' . $method;
    }

    public function validateCredentials(): ValidationResult {
        if (trim($this->token) === '') {
            return ValidationResult::invalid('netgsm', ValidationResult::EMPTY_VALUE, 'API token cannot be empty.');
        }

        // Test API connection with a simple balance inquiry
        try {
            $result = $this->bakiyeSorgula();
            if ($result->success) {
                return ValidationResult::valid(
                    'netgsm',
                    'API token is valid.',
                    []
                );
            } else {
                return ValidationResult::invalid(
                    'netgsm',
                    ValidationResult::UNAUTHORIZED,
                    'Invalid API token.'
                );
            }
        } catch (\Exception $e) {
            return ValidationResult::invalid(
                'netgsm',
                ValidationResult::UNAUTHORIZED,
                'Invalid API token or connection error: ' . $e->getMessage()
            );
        }
    }

    /**
     * @param array<string, mixed> $options
     */
    public function validateRecipient(string $recipientId, array $options = []): ValidationResult {
        // For NetGSM, we can only validate the phone number format
        if (trim($recipientId) === '') {
            return ValidationResult::invalid('netgsm', ValidationResult::EMPTY_VALUE, 'Phone number cannot be empty.');
        }

        // Simple phone number validation (Turkish format)
        if (!preg_match('/^90[0-9]{10}$/', trim($recipientId))) {
            return ValidationResult::invalid(
                'netgsm',
                ValidationResult::INVALID_FORMAT,
                'Invalid phone number format. Expected: 90XXXXXXXXXX'
            );
        }

        return ValidationResult::valid(
            'netgsm',
            'Phone number is valid.',
            ['phone' => $recipientId]
        );
    }
}
<?php
declare(strict_types=1);
namespace MyMessenger\Provider\NetGSM;

use MyMessenger\Contracts\SmsMessengerInterface;
use MyMessenger\Contracts\ValidatesCredentialsInterface;
use MyMessenger\Core\AbstractMessenger;
use MyMessenger\Credentials\CredentialBag;
use MyMessenger\Enums\Capability;
use MyMessenger\Exceptions\MessengerApiException;
use MyMessenger\Exceptions\MessengerException;
use MyMessenger\Logging\LoggerInterface;
use MyMessenger\ValueObject\MessageResult;
use MyMessenger\ValueObject\ValidationResult;
use Throwable;

/**
 * NetGSM SMS API v3 provider (https://api-docs.netgsm.com.tr).
 *
 * Endpoints used:
 * - `POST /auth/v3/token`  – username/password → bearer token (scope `sms`)
 * - `POST /sms/v3/send`    – bulk SMS job (202 Accepted)
 * - `POST /sms/v3/send/otp`– single OTP SMS
 * - `POST /sms/v3/send/template` – template based SMS
 * - `GET  /sms/v3/balance` – packages, campaigns and credit
 * - `GET  /sms/v3/report`  – delivery reports (max 100 per page)
 * - `GET  /sms/v3/stats`   – per-job delivery statistics
 * - `GET  /sms/v3/inbox`   – received SMS
 * - `GET  /sms/v3/senders` – registered sender headers
 * - `GET  /sms/v3/templates` – available templates
 * - `POST /sms/v3/cancel`  – cancel a SCHEDULED job
 * - `POST /sms/v3/length`  – measure SMS parts
 *
 * Credentials:
 * - `token`    : a ready bearer token, **or**
 * - `username` + `password` : exchanged for a token automatically (scope configurable)
 *
 * Config:
 * - `sender`            : default sender header used when `$options['sender']` is absent
 * - `scope`             : token scope, default `sms`
 * - `encoding`          : default `DEFAULT` (`TURKISH`, `UNICODE`)
 * - `iys_filter`        : default `NONE` (`BRAND`, `RETAILER`, `BRAND_AND_RETAILER`)
 * - `base_uri`          : default `https://ext-api.netgsm.com.tr`
 * - `sandbox`           : when true, `https://ext-sandbox.netgsm.com.tr` is used
 * - `otp_enterprise`    : sends `x-client: enterprise` on OTP requests
 * - `refresh_token`     : refresh token for `POST /auth/v3/refresh`
 */
final class Messenger extends AbstractMessenger implements SmsMessengerInterface, ValidatesCredentialsInterface {
	private const string LIVE_BASE = 'https://ext-api.netgsm.com.tr';

	private const string SANDBOX_BASE = 'https://ext-sandbox.netgsm.com.tr';

	private const string SMS_PATH = '/sms/v3';

	private const string AUTH_PATH = '/auth/v3';

	private const string DEFAULT_SENDER = 'NETGSM';

	private ?string $refreshToken = null;

	private ?int $tokenExpiresAt = null;

	/**
	 * @param array<string, mixed> $config
	 */
	public function __construct(
		CredentialBag $credentials,
		array $config = [],
		?LoggerInterface $logger = null,
		?\GuzzleHttp\ClientInterface $httpClient = null,
	) {
		if ($httpClient !== null) {
			$config['http_client'] = $httpClient;
		}

		$refreshToken = $credentials->get('refresh_token') ?? $config['refresh_token'] ?? null;
		if (is_scalar($refreshToken) && trim((string) $refreshToken) !== '') {
			$this->refreshToken = trim((string) $refreshToken);
		}

		parent::__construct($credentials, $config, $logger);

		if ($this->httpClient === null) {
			throw new MessengerException(
				'The guzzlehttp/guzzle package was not found. Guzzle 8 must be installed for NetGSM requests.',
			);
		}
	}

	#[\Override]
	public function platform(): string {
		return 'netgsm';
	}

	#[\Override]
	protected function buildCapabilityMap(): array {
		return $this->capabilityMapFrom(
			Capability::SEND_MESSAGE,
			Capability::SEND_BULK,
			Capability::SEND_OTP,
			Capability::SEND_TEMPLATE_MESSAGES,
			Capability::GET_BALANCE,
			Capability::GET_REPORT,
			Capability::GET_STATS,
			Capability::READ_INBOX,
			Capability::LIST_SENDERS,
			Capability::LIST_TEMPLATES,
			Capability::CANCEL_JOB,
			Capability::MEASURE_LENGTH,
			Capability::GET_ACCOUNT_INFO,
		);
	}

	/**
	 * A ready token wins; otherwise username + password are exchanged for one.
	 */
	#[\Override]
	protected function resolveToken(): string {
		$token = $this->credentials->get('token');

		if (is_scalar($token) && trim((string) $token) !== '') {
			return trim((string) $token);
		}

		if ($this->hasPasswordCredentials()) {
			return $this->requestAccessToken();
		}

		throw new MessengerException(
			'NetGSM requires either the "token" credential or the "username" + "password" pair.',
		);
	}

	private function hasPasswordCredentials(): bool {
		$username = $this->credentials->get('username');
		$password = $this->credentials->get('password');

		return is_scalar($username) && trim((string) $username) !== ''
			&& is_scalar($password) && (string) $password !== '';
	}

	/* -------------------------------------------------------------- Sending */

	/**
	 * @param array<string, mixed> $options
	 */
	#[\Override]
	public function sendMessage(string $recipientId, string $text, array $options = []): MessageResult {
		$this->assertCapability(Capability::SEND_MESSAGE);

		return $this->sendBulk([$recipientId], $text, $options);
	}

	/**
	 * Sends one job containing one record per recipient.
	 *
	 * `$options` accepts any field of `POST /send`: `sender`, `encoding`, `iysFilter`,
	 * `startAt`, `stopAt`, `partnerCode`, `referenceId`, `appName`.
	 *
	 * @param list<string> $recipientIds
	 * @param array<string, mixed> $options
	 */
	#[\Override]
	public function sendBulk(array $recipientIds, string $text, array $options = []): MessageResult {
		$this->assertCapability(Capability::SEND_BULK);

		if ($recipientIds === []) {
			throw new MessengerApiException(apiCode: null, message: 'At least one recipient is required.');
		}

		if (trim($text) === '') {
			throw new MessengerApiException(apiCode: null, message: 'Message text cannot be empty.');
		}

		$messages = [];

		foreach ($recipientIds as $recipientId) {
			$messages[] = [
				'text' => $text,
				'recipient' => $this->normalizeRecipient($recipientId),
			];
		}

		$payload = [
			'sender' => $this->resolveSender($options),
			'messages' => $messages,
			'encoding' => (string) ($options['encoding'] ?? $this->config['encoding'] ?? 'DEFAULT'),
			...$this->without($options, ['sender', 'messages', 'encoding']),
		];

		if (!isset($payload['iysFilter']) && isset($this->config['iys_filter'])) {
			$payload['iysFilter'] = (string) $this->config['iys_filter'];
		}

		return $this->execute('sendBulk', fn (): array => $this->authorized('POST', '/send', $payload));
	}

	/**
	 * OTP SMS: one message, one recipient, dedicated channel.
	 *
	 * OTP job ids are **not** queryable through `GET /report`.
	 *
	 * @param array<string, mixed> $options
	 */
	#[\Override]
	public function sendOtp(string $recipientId, string $text, array $options = []): MessageResult {
		$this->assertCapability(Capability::SEND_OTP);

		$payload = [
			'sender' => $this->resolveSender($options),
			'text' => $text,
			'recipient' => $this->normalizeRecipient($recipientId),
			...$this->without($options, ['sender']),
		];

		$headers = ['Content-Type' => 'application/json', 'Authorization' => 'Bearer ' . $this->token];

		if (($this->config['otp_enterprise'] ?? false) === true) {
			$headers['x-client'] = 'enterprise';
		}

		return $this->execute('sendOtp', fn (): array => $this->authorized(
			'POST',
			'/send/otp',
			$payload,
			['headers' => $headers],
		));
	}

	/**
	 * Sends messages built from pre-approved templates (max 20 records per request).
	 *
	 * Each record: `['templateId' => 3, 'recipient' => '905551112233', 'params' => ['otpCode' => '123456']]`.
	 *
	 * @param list<array<string, mixed>> $templates
	 * @param array<string, mixed> $options
	 */
	#[\Override]
	public function sendTemplateMessages(array $templates, array $options = []): MessageResult {
		$this->assertCapability(Capability::SEND_TEMPLATE_MESSAGES);

		if ($templates === [] || count($templates) > 20) {
			throw new MessengerApiException(
				apiCode: null,
				message: 'A template request must contain between 1 and 20 records.',
			);
		}

		$records = [];

		foreach ($templates as $template) {
			if (!is_array($template) || !isset($template['templateId'], $template['recipient'])) {
				throw new MessengerApiException(
					apiCode: null,
					message: 'Each template record requires "templateId" and "recipient".',
				);
			}

			$record = [
				'templateId' => (int) $template['templateId'],
				'recipient' => $this->normalizeRecipient((string) $template['recipient']),
			];

			if (isset($template['params'])) {
				$record['params'] = $template['params'];
			}

			$records[] = $record;
		}

		$payload = [
			'sender' => $this->resolveSender($options),
			'templates' => $records,
			...$this->without($options, ['sender', 'templates']),
		];

		return $this->execute('sendTemplateMessages', fn (): array => $this->authorized('POST', '/send/template', $payload));
	}

	/* ------------------------------------------------------------- Reporting */

	/**
	 * Balance / packages.
	 *
	 * @param array<string, mixed> $options `stip` (1|2|3) and `service` (sms, otp, ses, …)
	 */
	#[\Override]
	public function getBalance(array $options = []): MessageResult {
		$this->assertCapability(Capability::GET_BALANCE);

		return $this->execute('getBalance', fn (): array => $this->authorized('GET', '/balance', null, [
			'query' => $options,
		]));
	}

	/**
	 * Delivery reports, one page of at most 100 rows.
	 *
	 * @param array<string, mixed> $options `jobIds` (array|string), `startAt`, `stopAt`, `page`
	 */
	#[\Override]
	public function getReport(array $options = []): MessageResult {
		$this->assertCapability(Capability::GET_REPORT);

		$query = $options;

		if (isset($query['jobIds']) && is_array($query['jobIds'])) {
			$query['jobIds'] = implode(',', array_map(strval(...), $query['jobIds']));
		}

		return $this->execute('getReport', fn (): array => $this->authorized('GET', '/report', null, [
			'query' => $query,
		]));
	}

	/**
	 * Per-job delivery statistics.
	 *
	 * @param array<string, mixed> $options `jobId` (required), `month` (YYYY-MM)
	 */
	#[\Override]
	public function getStats(array $options = []): MessageResult {
		$this->assertCapability(Capability::GET_STATS);

		if (!isset($options['jobId']) || trim((string) $options['jobId']) === '') {
			throw new MessengerApiException(apiCode: null, message: 'getStats() requires $options["jobId"].');
		}

		return $this->execute('getStats', fn (): array => $this->authorized('GET', '/stats', null, [
			'query' => $options,
		]));
	}

	/**
	 * Received SMS.
	 *
	 * @param array<string, mixed> $options `startAt`, `stopAt`
	 */
	#[\Override]
	public function readInbox(array $options = []): MessageResult {
		$this->assertCapability(Capability::READ_INBOX);

		return $this->execute('readInbox', fn (): array => $this->authorized('GET', '/inbox', null, [
			'query' => $options,
		]));
	}

	/**
	 * Registered sender headers and whether they are usable.
	 *
	 * @param array<string, mixed> $options
	 */
	#[\Override]
	public function listSenders(array $options = []): MessageResult {
		$this->assertCapability(Capability::LIST_SENDERS);

		return $this->execute('listSenders', fn (): array => $this->authorized('GET', '/senders', null, [
			'query' => $options,
		]));
	}

	/**
	 * Available templates (own + public).
	 *
	 * @param array<string, mixed> $options `includePublic`, `type`, `search`, `content`, `page`, `size`
	 */
	#[\Override]
	public function listTemplates(array $options = []): MessageResult {
		$this->assertCapability(Capability::LIST_TEMPLATES);

		return $this->execute('listTemplates', fn (): array => $this->authorized('GET', '/templates', null, [
			'query' => $options,
		]));
	}

	/**
	 * Cancels a job that is still in `SCHEDULED` state.
	 *
	 * @param array<string, mixed> $options
	 */
	#[\Override]
	public function cancelJob(string $jobId, array $options = []): MessageResult {
		$this->assertCapability(Capability::CANCEL_JOB);

		return $this->execute('cancelJob', fn (): array => $this->authorized('POST', '/cancel', [
			'jobId' => $jobId,
			...$options,
		]));
	}

	/**
	 * Measures the SMS parts a text occupies.
	 *
	 * @param array<string, mixed> $options `encoding`, `alphanumericSender`
	 */
	#[\Override]
	public function measureLength(string $text, array $options = []): MessageResult {
		$this->assertCapability(Capability::MEASURE_LENGTH);

		$payload = [
			'text' => $text,
			'encoding' => (string) ($options['encoding'] ?? $this->config['encoding'] ?? 'DEFAULT'),
			...$this->without($options, ['encoding']),
		];

		return $this->execute('measureLength', fn (): array => $this->authorized('POST', '/length', $payload));
	}

	/**
	 * Account summary: sender headers plus balance.
	 *
	 * @param array<string, mixed> $options
	 */
	#[\Override]
	public function getAccountInfo(array $options = []): MessageResult {
		$this->assertCapability(Capability::GET_ACCOUNT_INFO);

		return $this->execute('getAccountInfo', fn (): array => [
			'senders' => $this->authorized('GET', '/senders', null, ['query' => $options]),
			'balance' => $this->authorized('GET', '/balance', null, ['query' => ['stip' => 2]]),
		]);
	}

	/* ------------------------------------------------------------ Validation */

	/**
	 * NetGSM tokens are JWTs (`eyJ…`); usernames are numeric subscriber numbers.
	 */
	#[\Override]
	public static function isCredentialFormatValid(string $token): bool {
		$token = trim($token);

		if ($token === '') {
			return false;
		}

		return str_starts_with($token, 'eyJ') && substr_count($token, '.') === 2;
	}

	/**
	 * `905061234567` (TR) or `-4915112345678` (international).
	 */
	#[\Override]
	public static function isRecipientFormatValid(string $recipientId): bool {
		return (bool) preg_match('/^(90\d{10}|-\d{9,17})$/', trim($recipientId));
	}

	#[\Override]
	public function validateCredentials(): ValidationResult {
		if (trim($this->token) === '' && !$this->hasPasswordCredentials()) {
			return ValidationResult::invalid(
				'netgsm',
				ValidationResult::EMPTY_VALUE,
				'A bearer token or the username + password pair is required.',
			);
		}

		$result = $this->getBalance(['stip' => 2]);

		if (!$result->success) {
			return $this->classifyFailure($result, 'token');
		}

		return ValidationResult::valid('netgsm', 'NetGSM credentials are valid.', [
			'balance' => $result->raw['balance'] ?? null,
			'type' => $result->raw['type'] ?? null,
		]);
	}

	/**
	 * Format check plus an optional real test SMS.
	 *
	 * @param array<string, mixed> $options
	 */
	#[\Override]
	public function validateRecipient(string $recipientId, array $options = []): ValidationResult {
		$normalized = $this->normalizeRecipient($recipientId);

		if (trim($recipientId) === '') {
			return ValidationResult::invalid('netgsm', ValidationResult::EMPTY_VALUE, 'Phone number cannot be empty.');
		}

		if (!self::isRecipientFormatValid($normalized)) {
			return ValidationResult::invalid(
				'netgsm',
				ValidationResult::INVALID_FORMAT,
				'Invalid phone number. Expected 905061234567 (TR) or -4915112345678 (international).',
			);
		}

		$data = ['phone' => $normalized];

		if (($options['send_test_message'] ?? false) === true) {
			$text = (string) ($options['test_message'] ?? '✅ NetGSM connection successfully validated.');
			$result = $this->sendMessage($normalized, $text, $options);

			if (!$result->success) {
				return $this->classifyFailure($result, 'recipient', $data);
			}

			$data['job_id'] = $result->messageId;
			$data['accepted'] = $result->raw['accepted'] ?? null;
			$data['rejected'] = $result->raw['rejected'] ?? null;
		}

		return ValidationResult::valid('netgsm', 'Phone number is valid.', $data);
	}

	/**
	 * @param 'token'|'recipient' $context
	 * @param array<string, mixed> $data
	 */
	private function classifyFailure(MessageResult $result, string $context, array $data = []): ValidationResult {
		$code = (string) ($result->error?->code ?? '');
		$description = $result->error?->message ?? '';
		$data['api_code'] = $code;
		$data['api_message'] = $description;

		[$reason, $message] = match (true) {
			$code === 'UNAUTHENTICATED', $code === 'invalid_credentials' => [
				ValidationResult::UNAUTHORIZED,
				'NetGSM credentials are invalid or the token expired.',
			],
			$code === 'FORBIDDEN', $code === 'api_access_denied', $code === 'service_not_activated' => [
				ValidationResult::NO_PERMISSION,
				'The account is not allowed to use the SMS API: ' . $description,
			],
			$code === 'REJECTED_HEADER' => [
				ValidationResult::NO_PERMISSION,
				'The sender header is not registered or not usable: ' . $description,
			],
			$code === 'REJECTED_IYS' => [
				ValidationResult::NO_PERMISSION,
				'İYS filtering could not be applied: ' . $description,
			],
			$code === 'INSUFFICIENT_CREDIT' => [
				ValidationResult::UNKNOWN_ERROR,
				'Insufficient credit, package or campaign for this send.',
			],
			$code === 'NO_DELIVERABLE_MESSAGES' => [
				ValidationResult::INVALID_FORMAT,
				'All records were rejected: ' . $description,
			],
			$code === 'RATE_LIMITED' => [
				ValidationResult::UNKNOWN_ERROR,
				'NetGSM rate limit reached. Retry later.',
			],
			$code === 'NOT_FOUND' => [
				ValidationResult::NOT_FOUND,
				'The requested job or resource was not found for this account.',
			],
			$code === 'SERVICE_UNAVAILABLE', $code === 'INTERNAL_ERROR' => [
				ValidationResult::NETWORK_ERROR,
				'NetGSM is temporarily unavailable: ' . $description,
			],
			default => [ValidationResult::UNKNOWN_ERROR, 'Unexpected NetGSM error: ' . $description],
		};

		return ValidationResult::invalid('netgsm', $reason, $message, $data);
	}

	/* ------------------------------------------------------------------ HTTP */

	/**
	 * @param array<string, mixed>|null $payload
	 * @param array<string, mixed> $options
	 * @return array<string, mixed>
	 */
	private function authorized(string $method, string $path, ?array $payload = null, array $options = []): array {
		$options['headers'] = [
			'Content-Type' => 'application/json',
			'Authorization' => 'Bearer ' . $this->token,
			...($options['headers'] ?? []),
		];

		if ($payload !== null) {
			$options['json'] = $payload;
		}

		try {
			return $this->send($method, $this->smsBase() . $path, $options);
		} catch (MessengerApiException $exception) {
			// Expired token: refresh once and retry the same request.
			if (!$this->isUnauthenticated($exception) || !$this->canRefreshToken()) {
				throw $exception;
			}

			$this->refreshAccessToken();

			$options['headers']['Authorization'] = 'Bearer ' . $this->token;

			return $this->send($method, $this->smsBase() . $path, $options);
		}
	}

	private function isUnauthenticated(MessengerApiException $exception): bool {
		$code = (string) $exception->getApiCode();

		return $code === 'UNAUTHENTICATED' || $code === '401';
	}

	private function canRefreshToken(): bool {
		return $this->refreshToken !== null || $this->hasPasswordCredentials();
	}

	private function refreshAccessToken(): void {
		if ($this->refreshToken !== null) {
			try {
				$response = $this->send('POST', $this->authBase() . '/refresh', [
					'headers' => ['Content-Type' => 'application/json'],
					'json' => ['refresh_token' => $this->refreshToken],
				]);

				$this->applyTokenResponse($response);

				return;
			} catch (Throwable) {
				// Fall back to a full username/password exchange below.
			}
		}

		if ($this->hasPasswordCredentials()) {
			$this->token = $this->requestAccessToken();
		}
	}

	/**
	 * @return array<string, mixed>
	 */
	private function requestAccessToken(): string {
		$response = $this->send('POST', $this->authBase() . '/token', [
			'headers' => ['Content-Type' => 'application/json'],
			'json' => [
				'username' => (string) $this->credentials->require('username'),
				'password' => (string) $this->credentials->require('password'),
				'scope' => (string) ($this->config['scope'] ?? 'sms'),
			],
		]);

		$this->applyTokenResponse($response);

		return $this->token;
	}

	/**
	 * @param array<string, mixed> $response
	 */
	private function applyTokenResponse(array $response): void {
		$token = $response['access_token'] ?? null;

		if (!is_scalar($token) || trim((string) $token) === '') {
			throw new MessengerApiException(
				apiCode: null,
				message: 'NetGSM did not return an access token.',
			);
		}

		$this->token = trim((string) $token);

		$refreshToken = $response['refresh_token'] ?? null;
		if (is_scalar($refreshToken) && trim((string) $refreshToken) !== '') {
			$this->refreshToken = trim((string) $refreshToken);
		}

		$expiresIn = $response['expires_in'] ?? null;
		if (is_numeric($expiresIn)) {
			$this->tokenExpiresAt = time() + (int) $expiresIn;
		}
	}

	/**
	 * Unix timestamp of the current token expiry, when known.
	 */
	public function tokenExpiresAt(): ?int {
		return $this->tokenExpiresAt;
	}

	/**
	 * NetGSM error envelope: `{code, message, requestId, timestamp, violations}`.
	 *
	 * @param array<string, mixed> $decoded
	 */
	#[\Override]
	protected function buildApiException(array $decoded, ?Throwable $previous = null): MessengerApiException {
		$code = $decoded['code'] ?? $decoded['error'] ?? null;
		$message = (string) ($decoded['message'] ?? $decoded['detail'] ?? 'NetGSM request failed.');

		if (isset($decoded['violations']) && is_array($decoded['violations']) && $decoded['violations'] !== []) {
			$fields = array_map(
				static fn (mixed $violation): string => is_array($violation)
					? (string) ($violation['field'] ?? '') . ': ' . (string) ($violation['message'] ?? '')
					: (string) $violation,
				$decoded['violations'],
			);
			$message .= ' [' . implode('; ', $fields) . ']';
		}

		if (isset($decoded['requestId'])) {
			$message .= sprintf(' (requestId: %s)', (string) $decoded['requestId']);
		}

		return new MessengerApiException(
			apiCode: is_scalar($code) ? $code : null,
			message: $message,
			code: is_numeric($code) ? (int) $code : 0,
			previous: $previous,
		);
	}

	/**
	 * @param array<string, mixed> $options
	 * @param list<string> $keys
	 * @return array<string, mixed>
	 */
	private function without(array $options, array $keys): array {
		foreach ($keys as $key) {
			unset($options[$key]);
		}

		return $options;
	}

	/**
	 * @param array<string, mixed> $options
	 */
	private function resolveSender(array $options): string {
		$sender = $options['sender'] ?? $this->credentials->get('sender') ?? $this->config['sender'] ?? self::DEFAULT_SENDER;

		return trim((string) $sender);
	}

	/**
	 * Normalises a Turkish number to `90XXXXXXXXXX` and keeps international numbers as `-…`.
	 */
	private function normalizeRecipient(string $recipientId): string {
		$value = preg_replace('/[\s()]+/', '', trim($recipientId)) ?? trim($recipientId);

		if (str_starts_with($value, '-')) {
			return '-' . preg_replace('/\D+/', '', substr($value, 1));
		}

		$digits = preg_replace('/\D+/', '', $value) ?? '';

		if (str_starts_with($digits, '90') && strlen($digits) === 12) {
			return $digits;
		}

		if (str_starts_with($digits, '0') && strlen($digits) === 11) {
			return '9' . $digits;
		}

		if (strlen($digits) === 10 && str_starts_with($digits, '5')) {
			return '90' . $digits;
		}

		return $digits;
	}

	private function baseUri(): string {
		$configured = $this->config['base_uri'] ?? null;

		if (is_string($configured) && trim($configured) !== '') {
			return rtrim(trim($configured), '/');
		}

		return ($this->config['sandbox'] ?? false) === true ? self::SANDBOX_BASE : self::LIVE_BASE;
	}

	private function smsBase(): string {
		return $this->baseUri() . self::SMS_PATH;
	}

	private function authBase(): string {
		return $this->baseUri() . self::AUTH_PATH;
	}
}

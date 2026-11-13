<?php
declare(strict_types=1);
namespace MyMessenger\Core;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\GuzzleException;
use MyMessenger\Contracts\MessengerInterface;
use MyMessenger\Credentials\CredentialBag;
use MyMessenger\Enums\Capability;
use MyMessenger\Exceptions\MessengerApiException;
use MyMessenger\Exceptions\MessengerTransportException;
use MyMessenger\Exceptions\UnsupportedCapabilityException;
use MyMessenger\Logging\LoggerInterface;
use MyMessenger\Logging\NullLogger;
use MyMessenger\ValueObject\MessageResult;
use Throwable;

/**
 * Shared plumbing for every provider.
 *
 * Responsibilities:
 * - credential handling and secret masking
 * - HTTP client resolution, timeouts, transport retries
 * - capability map and guards
 * - JSON / multipart request helpers
 * - media normalisation (remote URL vs. local upload)
 * - response normalisation and message-id extraction
 *
 * Every method of {@see MessengerInterface} has a default implementation that throws
 * {@see UnsupportedCapabilityException}. Providers override only what they really support and
 * declare the same set in {@see buildCapabilityMap()}.
 */
abstract class AbstractMessenger implements MessengerInterface {
	/**
	 * Access token. Not readonly: providers that exchange username/password for a short-lived
	 * token (NetGSM) refresh it in place.
	 */
	protected string $token;

	protected readonly LoggerInterface $logger;

	protected readonly ?ClientInterface $httpClient;

	/**
	 * @var array<string, bool>
	 */
	protected array $capabilityMap = [];

	/**
	 * @param array<string, mixed> $config
	 */
	public function __construct(
		protected readonly CredentialBag $credentials,
		protected readonly array $config = [],
		?LoggerInterface $logger = null,
	) {
		$this->logger = $logger ?? new NullLogger();
		$this->httpClient = $this->resolveHttpClient();
		$this->token = $this->resolveToken();
		$this->capabilityMap = $this->buildCapabilityMap();
	}

	/**
	 * Resolves the access token. Providers that support a username/password token exchange
	 * override this method; everything else requires the `token` credential.
	 */
	protected function resolveToken(): string {
		return (string) $this->credentials->require('token');
	}

	public function supports(string $capability): bool {
		return $this->capabilityMap[$capability] ?? false;
	}

	/**
	 * @return list<string>
	 */
	public function capabilities(): array {
		return array_keys(array_filter($this->capabilityMap));
	}

	protected function assertCapability(string|Capability $capability): void {
		$key = $capability instanceof Capability ? $capability->value : $capability;

		if (!$this->supports($key)) {
			throw new UnsupportedCapabilityException(sprintf(
				'The "%s" platform does not support the "%s" capability.',
				$this->platform(),
				$key,
			));
		}
	}

	/**
	 * Runs an operation and converts the outcome into a {@see MessageResult}.
	 *
	 * Transport failures (connect/timeout) are retried `retry_attempts` times with
	 * `retry_delay_ms` between attempts. API failures are returned immediately unless their
	 * code is listed in `retryable_api_codes`.
	 *
	 * @param callable():mixed $operation
	 * @param (callable(mixed):(?string))|null $messageIdResolver
	 */
	protected function execute(string $operationName, callable $operation, ?callable $messageIdResolver = null): MessageResult {
		$attempt = 0;
		$retry = max(0, (int) ($this->config['retry_attempts'] ?? 0));
		$retryDelayMs = max(0, (int) ($this->config['retry_delay_ms'] ?? 100));

		/** @var list<int|string> $retryableApiCodes */
		$retryableApiCodes = is_array($this->config['retryable_api_codes'] ?? null)
			? $this->config['retryable_api_codes']
			: [];

		while (true) {
			++$attempt;

			try {
				$raw = $operation();
				$normalized = $this->normalizeRaw($raw);
				$messageId = $messageIdResolver !== null
					? $messageIdResolver($normalized)
					: $this->extractMessageId($normalized);

				$this->logger->info('Messenger operation successful.', $this->sanitizeContext([
					'platform' => $this->platform(),
					'operation' => $operationName,
					'attempt' => $attempt,
					'message_id' => $messageId,
				]));

				return MessageResult::ok(
					platform: $this->platform(),
					messageId: $messageId,
					raw: $normalized,
				);
			} catch (MessengerApiException $exception) {
				$apiCode = $exception->getApiCode();

				if ($retryableApiCodes !== [] && in_array($apiCode, $retryableApiCodes, true) && $attempt <= $retry) {
					$this->logger->warning('Messenger API error, retrying.', $this->sanitizeContext([
						'platform' => $this->platform(),
						'operation' => $operationName,
						'attempt' => $attempt,
						'api_code' => $apiCode,
						'message' => $exception->getMessage(),
					]));

					$this->sleepBeforeRetry($retryDelayMs);
					continue;
				}

				$this->logger->error('Messenger API error.', $this->sanitizeContext([
					'platform' => $this->platform(),
					'operation' => $operationName,
					'attempt' => $attempt,
					'api_code' => $apiCode,
					'message' => $exception->getMessage(),
				]));

				return MessageResult::fail(
					platform: $this->platform(),
					code: $apiCode,
					message: $this->maskSecrets($exception->getMessage()),
				);
			} catch (Throwable $exception) {
				if ($this->isTransportException($exception) && $attempt <= $retry) {
					$this->logger->warning('Messenger transport error, retrying.', $this->sanitizeContext([
						'platform' => $this->platform(),
						'operation' => $operationName,
						'attempt' => $attempt,
						'message' => $exception->getMessage(),
					]));

					$this->sleepBeforeRetry($retryDelayMs);
					continue;
				}

				$wrapped = $this->wrapThrowable($exception);

				$this->logger->error('Messenger operation failed.', $this->sanitizeContext([
					'platform' => $this->platform(),
					'operation' => $operationName,
					'attempt' => $attempt,
					'message' => $wrapped->getMessage(),
				]));

				return MessageResult::fail(
					platform: $this->platform(),
					code: $wrapped->getCode(),
					message: $this->maskSecrets($wrapped->getMessage()),
				);
			}
		}
	}

	protected function sleepBeforeRetry(int $delayMs): void {
		if ($delayMs > 0) {
			usleep($delayMs * 1000);
		}
	}

	/**
	 * Capability map for the provider. Override and return the supported capabilities only.
	 *
	 * @return array<string, bool>
	 */
	protected function buildCapabilityMap(): array {
		return [];
	}

	/**
	 * @return array<string, bool>
	 */
	protected function capabilityMapFrom(Capability ...$capabilities): array {
		$map = [];

		foreach ($capabilities as $capability) {
			$map[$capability->value] = true;
		}

		return $map;
	}

	/**
	 * Message id extraction covering the response shapes of the supported platforms.
	 *
	 * @param array<string, mixed> $raw
	 */
	protected function extractMessageId(array $raw): ?string {
		foreach (['message_id', 'messageId', 'jobId', 'jobid', 'id'] as $key) {
			if (isset($raw[$key]) && is_scalar($raw[$key])) {
				return (string) $raw[$key];
			}
		}

		if (isset($raw['result']) && is_array($raw['result'])) {
			foreach (['message_id', 'messageId', 'id', 'file_id'] as $key) {
				if (isset($raw['result'][$key]) && is_scalar($raw['result'][$key])) {
					return (string) $raw['result'][$key];
				}
			}
		}

		if (isset($raw['messages'][0]['id']) && is_scalar($raw['messages'][0]['id'])) {
			return (string) $raw['messages'][0]['id'];
		}

		if (isset($raw['message_id']) && is_scalar($raw['message_id'])) {
			return (string) $raw['message_id'];
		}

		return null;
	}

	/**
	 * @return array<string, mixed>
	 */
	protected function normalizeRaw(mixed $raw): array {
		if (is_array($raw)) {
			return $raw;
		}

		if ($raw instanceof \JsonSerializable) {
			$json = $raw->jsonSerialize();

			return is_array($json) ? $json : ['value' => $json];
		}

		if (is_object($raw)) {
			if (method_exists($raw, 'toArray')) {
				$value = $raw->toArray();

				return is_array($value) ? $value : ['value' => $value];
			}

			return (array) $raw;
		}

		return ['value' => $raw];
	}

	protected function wrapThrowable(Throwable $throwable): MessengerTransportException|MessengerApiException {
		if ($this->isTransportException($throwable)) {
			return new MessengerTransportException($throwable->getMessage(), (int) $throwable->getCode(), $throwable);
		}

		return new MessengerApiException(
			apiCode: $throwable->getCode(),
			message: $throwable->getMessage(),
			code: (int) $throwable->getCode(),
			previous: $throwable,
		);
	}

	protected function isTransportException(Throwable $throwable): bool {
		return interface_exists(GuzzleException::class) && $throwable instanceof GuzzleException;
	}

	/**
	 * @param array<string, mixed> $context
	 * @return array<string, mixed>
	 */
	protected function sanitizeContext(array $context): array {
		$sanitized = array_map(
			fn (mixed $value): mixed => is_string($value) ? $this->maskSecrets($value) : $value,
			$context,
		);

		$secretKeys = ['token', 'secret', 'password', 'authorization', 'api_key', 'access_token'];

		foreach ($sanitized as $key => $value) {
			$normalizedKey = strtolower((string) $key);

			foreach ($secretKeys as $secretKey) {
				if (str_contains($normalizedKey, $secretKey)) {
					$sanitized[$key] = '***';
					continue 2;
				}
			}
		}

		return $sanitized;
	}

	/**
	 * Keeps the token out of error messages (e.g. "https://api.telegram.org/bot<TOKEN>/getMe").
	 */
	protected function maskSecrets(string $text): string {
		if ($this->token === '' || !str_contains($text, $this->token)) {
			return $text;
		}

		return str_replace($this->token, '***', $text);
	}

	protected function resolveHttpClient(): ?ClientInterface {
		$configured = $this->config['http_client'] ?? null;

		if ($configured instanceof ClientInterface) {
			return $configured;
		}

		if (!class_exists(Client::class)) {
			return null;
		}

		/** @var array<string, mixed> $httpClientConfig */
		$httpClientConfig = is_array($this->config['http_client_config'] ?? null)
			? $this->config['http_client_config']
			: [];

		return new Client($httpClientConfig);
	}

	/**
	 * Timeout options shared by every provider.
	 *
	 * @return array<string, mixed>
	 */
	protected function requestOptions(): array {
		$options = [];

		if (isset($this->config['timeout'])) {
			$options['timeout'] = (int) $this->config['timeout'];
		}

		if (isset($this->config['connect_timeout'])) {
			$options['connect_timeout'] = (int) $this->config['connect_timeout'];
		}

		return $options;
	}

	/**
	 * @return array<string, string>
	 */
	protected function authHeaders(string $scheme = 'Bearer'): array {
		return [
			'Content-Type' => 'application/json',
			'Authorization' => $scheme . ' ' . $this->token,
		];
	}

	/**
	 * Performs an HTTP request and returns the decoded JSON body.
	 *
	 * Transport errors are re-thrown so that {@see execute()} can retry them. HTTP error
	 * responses are converted into {@see MessengerApiException} using the provider envelope
	 * from {@see buildApiException()}.
	 *
	 * @param array<string, mixed> $options Guzzle options (headers, query, json, multipart, …)
	 * @return array<string, mixed>
	 */
	protected function send(string $method, string $uri, array $options = []): array {
		if ($this->httpClient === null) {
			throw new MessengerApiException(
				apiCode: null,
				message: 'HTTP client is unavailable (guzzlehttp/guzzle is not installed).',
			);
		}

		$options = [...$this->requestOptions(), ...$options];

		try {
			$response = $this->httpClient->request($method, $uri, $options);
		} catch (ConnectException $exception) {
			throw $exception;
		} catch (GuzzleException $exception) {
			$decoded = $this->decodeErrorBody($exception);

			if ($decoded !== null) {
				throw $this->buildApiException($decoded, $exception);
			}

			throw $exception;
		}

		$body = (string) $response->getBody();

		if (trim($body) === '') {
			return ['status' => $response->getStatusCode()];
		}

		$decoded = json_decode($body, true);

		if (!is_array($decoded)) {
			throw new MessengerApiException(
				apiCode: null,
				message: sprintf('Could not decode the %s response (invalid JSON).', $this->platform()),
			);
		}

		return $decoded;
	}

	/**
	 * Builds a provider specific API exception from a decoded error body.
	 *
	 * @param array<string, mixed> $decoded
	 */
	protected function buildApiException(array $decoded, ?Throwable $previous = null): MessengerApiException {
		$error = is_array($decoded['error'] ?? null) ? $decoded['error'] : null;
		$code = $decoded['error_code'] ?? $decoded['code'] ?? $error['code'] ?? null;
		$message = (string) (
			$decoded['description']
			?? $decoded['message']
			?? $error['message']
			?? 'Request failed.'
		);

		return new MessengerApiException(
			apiCode: is_scalar($code) ? $code : null,
			message: $message,
			code: is_numeric($code) ? (int) $code : 0,
			previous: $previous,
		);
	}

	/**
	 * @return array<string, mixed>|null
	 */
	protected function decodeErrorBody(GuzzleException $exception): ?array {
		if (!method_exists($exception, 'getResponse') || $exception->getResponse() === null) {
			return null;
		}

		$body = (string) $exception->getResponse()->getBody();
		$decoded = json_decode($body, true);

		return is_array($decoded) ? $decoded : null;
	}

	/**
	 * @param array<string, mixed> $payload
	 */
	protected function hasFileField(array $payload): bool {
		foreach ($payload as $value) {
			if (is_array($value) && isset($value['__file'])) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Converts a payload containing `['__file' => path]` markers into a Guzzle multipart array.
	 *
	 * @param array<string, mixed> $payload
	 * @return list<array<string, mixed>>
	 */
	protected function buildMultipart(array $payload): array {
		$multipart = [];

		foreach ($payload as $name => $value) {
			if (is_array($value) && isset($value['__file'])) {
				$path = (string) $value['__file'];
				$handle = @fopen($path, 'rb');

				if ($handle === false) {
					throw new MessengerApiException(
						apiCode: null,
						message: sprintf('Could not open the file for the "%s" field.', $name),
					);
				}

				$multipart[] = [
					'name' => (string) $name,
					'contents' => $handle,
					'filename' => (string) ($value['filename'] ?? basename($path)),
				];

				continue;
			}

			$multipart[] = [
				'name' => (string) $name,
				'contents' => $this->stringifyPayloadValue($value),
			];
		}

		return $multipart;
	}

	/**
	 * Normalises a media argument.
	 *
	 * - Remote URLs and non-existing paths are returned as plain strings (the platform downloads them).
	 * - Local files are returned as `['__file' => path, 'filename' => name]` for multipart uploads.
	 *
	 * @param resource|string $media
	 * @return string|array{__file:string, filename:string}
	 */
	protected function normalizeMedia(mixed $media, string $field): string|array {
		if (is_resource($media)) {
			$meta = stream_get_meta_data($media);
			$path = $meta['uri'] ?? null;

			if (is_string($path) && $path !== '' && is_file($path)) {
				return $this->createInputFile($path);
			}

			throw new MessengerApiException(
				apiCode: null,
				message: sprintf('The resource value for the "%s" field has no valid file path.', $field),
			);
		}

		if (!is_string($media) || $media === '') {
			throw new MessengerApiException(
				apiCode: null,
				message: sprintf('The value for the "%s" field must be a non-empty string or a resource.', $field),
			);
		}

		if ($this->isRemoteUrl($media) || !is_file($media)) {
			return $media;
		}

		return $this->createInputFile($media);
	}

	/**
	 * @return array{__file:string, filename:string}
	 */
	protected function createInputFile(string $path): array {
		return [
			'__file' => $path,
			'filename' => basename($path),
		];
	}

	protected function isRemoteUrl(string $value): bool {
		return (bool) preg_match('/^https?:\/\//i', $value);
	}

	protected function stringifyPayloadValue(mixed $value): string {
		if (is_bool($value)) {
			return $value ? '1' : '0';
		}

		if (is_array($value)) {
			return (string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
		}

		if ($value === null) {
			return '';
		}

		return (string) $value;
	}

	protected function unsupported(string $method): MessageResult {
		throw new UnsupportedCapabilityException(sprintf(
			'The "%s" platform does not implement %s().',
			$this->platform(),
			$method,
		));
	}

	abstract public function platform(): string;

	/* ------------------------------------------------------------------
	 |  Default implementations — providers override what they support.
	 * ------------------------------------------------------------------ */

	public function sendMessage(string $recipientId, string $text, array $options = []): MessageResult {
		return $this->unsupported('sendMessage');
	}

	public function sendPhoto(string $recipientId, mixed $photo, array $options = []): MessageResult {
		return $this->unsupported('sendPhoto');
	}

	public function sendDocument(string $recipientId, mixed $document, array $options = []): MessageResult {
		return $this->unsupported('sendDocument');
	}

	public function sendVideo(string $recipientId, mixed $video, array $options = []): MessageResult {
		return $this->unsupported('sendVideo');
	}

	public function sendAudio(string $recipientId, mixed $audio, array $options = []): MessageResult {
		return $this->unsupported('sendAudio');
	}

	public function sendLocation(string $recipientId, float $latitude, float $longitude, array $options = []): MessageResult {
		return $this->unsupported('sendLocation');
	}

	public function sendContact(string $recipientId, string $phoneNumber, string $firstName, array $options = []): MessageResult {
		return $this->unsupported('sendContact');
	}

	public function sendVoice(string $recipientId, mixed $voice, array $options = []): MessageResult {
		return $this->unsupported('sendVoice');
	}

	public function sendVideoNote(string $recipientId, mixed $videoNote, array $options = []): MessageResult {
		return $this->unsupported('sendVideoNote');
	}

	public function sendSticker(string $recipientId, mixed $sticker, array $options = []): MessageResult {
		return $this->unsupported('sendSticker');
	}

	public function sendMediaGroup(string $recipientId, array $media, array $options = []): MessageResult {
		return $this->unsupported('sendMediaGroup');
	}

	public function sendPoll(string $recipientId, string $question, array $answers, array $options = []): MessageResult {
		return $this->unsupported('sendPoll');
	}

	public function stopPoll(string $recipientId, string $messageId, array $options = []): MessageResult {
		return $this->unsupported('stopPoll');
	}

	public function sendDice(string $recipientId, array $options = []): MessageResult {
		return $this->unsupported('sendDice');
	}

	public function sendReaction(string $recipientId, string $messageId, string $emoji, array $options = []): MessageResult {
		return $this->unsupported('sendReaction');
	}

	public function sendTemplate(string $recipientId, mixed $template, array $options = []): MessageResult {
		return $this->unsupported('sendTemplate');
	}

	public function sendInteractive(string $recipientId, array $interactive, array $options = []): MessageResult {
		return $this->unsupported('sendInteractive');
	}

	public function sendQuickReplies(string $recipientId, string $text, array $quickReplies, array $options = []): MessageResult {
		return $this->unsupported('sendQuickReplies');
	}

	public function sendButtonTemplate(string $recipientId, string $text, array $buttons, array $options = []): MessageResult {
		return $this->unsupported('sendButtonTemplate');
	}

	public function sendGenericTemplate(string $recipientId, array $elements, array $options = []): MessageResult {
		return $this->unsupported('sendGenericTemplate');
	}

	public function editMessage(string $recipientId, string $messageId, string $text, array $options = []): MessageResult {
		return $this->unsupported('editMessage');
	}

	public function deleteMessage(string $recipientId, string $messageId, array $options = []): MessageResult {
		return $this->unsupported('deleteMessage');
	}

	public function sendChatAction(string $recipientId, string $action, array $options = []): MessageResult {
		return $this->unsupported('sendChatAction');
	}

	public function markAsRead(string $messageId, array $options = []): MessageResult {
		return $this->unsupported('markAsRead');
	}

	public function sendSenderAction(string $recipientId, string $action, array $options = []): MessageResult {
		return $this->unsupported('sendSenderAction');
	}

	public function getMe(): MessageResult {
		return $this->unsupported('getMe');
	}

	public function getAccountInfo(): MessageResult {
		return $this->unsupported('getAccountInfo');
	}

	public function getPhoneNumbers(array $options = []): MessageResult {
		return $this->unsupported('getPhoneNumbers');
	}

	public function getUserProfile(string $userId, array $options = []): MessageResult {
		return $this->unsupported('getUserProfile');
	}

	public function getTemplates(array $options = []): MessageResult {
		return $this->unsupported('getTemplates');
	}

	public function setMyCommands(array $commands, array $options = []): MessageResult {
		return $this->unsupported('setMyCommands');
	}

	public function getMyCommands(array $options = []): MessageResult {
		return $this->unsupported('getMyCommands');
	}

	public function deleteMyCommands(array $options = []): MessageResult {
		return $this->unsupported('deleteMyCommands');
	}

	public function setWebhook(string $url, array $options = []): MessageResult {
		return $this->unsupported('setWebhook');
	}

	public function deleteWebhook(): MessageResult {
		return $this->unsupported('deleteWebhook');
	}

	public function getWebhookInfo(): MessageResult {
		return $this->unsupported('getWebhookInfo');
	}

	public function getUpdates(array $options = []): MessageResult {
		return $this->unsupported('getUpdates');
	}

	public function answerCallbackQuery(string $callbackQueryId, array $options = []): MessageResult {
		return $this->unsupported('answerCallbackQuery');
	}

	public function getChat(string $recipientId, array $options = []): MessageResult {
		return $this->unsupported('getChat');
	}

	public function getChatMember(string $recipientId, string $userId, array $options = []): MessageResult {
		return $this->unsupported('getChatMember');
	}

	public function getChatAdministrators(string $recipientId, array $options = []): MessageResult {
		return $this->unsupported('getChatAdministrators');
	}

	public function getChatMemberCount(string $recipientId, array $options = []): MessageResult {
		return $this->unsupported('getChatMemberCount');
	}

	public function setChatTitle(string $recipientId, string $title, array $options = []): MessageResult {
		return $this->unsupported('setChatTitle');
	}

	public function banChatMember(string $recipientId, string $userId, array $options = []): MessageResult {
		return $this->unsupported('banChatMember');
	}

	public function unbanChatMember(string $recipientId, string $userId, array $options = []): MessageResult {
		return $this->unsupported('unbanChatMember');
	}

	public function leaveChat(string $recipientId, array $options = []): MessageResult {
		return $this->unsupported('leaveChat');
	}

	public function pinMessage(string $recipientId, string $messageId, array $options = []): MessageResult {
		return $this->unsupported('pinMessage');
	}

	public function unpinMessage(string $recipientId, array $options = []): MessageResult {
		return $this->unsupported('unpinMessage');
	}

	public function forwardMessage(string $recipientId, string $fromRecipientId, string $messageId, array $options = []): MessageResult {
		return $this->unsupported('forwardMessage');
	}

	public function copyMessage(string $recipientId, string $fromRecipientId, string $messageId, array $options = []): MessageResult {
		return $this->unsupported('copyMessage');
	}

	public function getFile(string $fileId, array $options = []): MessageResult {
		return $this->unsupported('getFile');
	}

	public function uploadMedia(string $filePath, string $mediaType, array $options = []): MessageResult {
		return $this->unsupported('uploadMedia');
	}

	public function getMedia(string $mediaId, array $options = []): MessageResult {
		return $this->unsupported('getMedia');
	}

	public function deleteMedia(string $mediaId, array $options = []): MessageResult {
		return $this->unsupported('deleteMedia');
	}
}

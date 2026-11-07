<?php
declare(strict_types=1);
namespace MyMessenger\Core;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use MyMessenger\Credentials\CredentialBag;
use MyMessenger\Enums\Capability;
use MyMessenger\Exceptions\MessengerApiException;
use MyMessenger\Exceptions\MessengerTransportException;
use MyMessenger\Exceptions\UnsupportedCapabilityException;
use MyMessenger\Logging\LoggerInterface;
use MyMessenger\Logging\NullLogger;
use MyMessenger\ValueObject\MessageResult;
use Throwable;

abstract class AbstractMessenger {
	protected readonly string $token;

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

		$token = (string) $this->credentials->require('token');
		$this->token = $token;

		$this->httpClient = $this->resolveHttpClient();
		$this->capabilityMap = $this->buildCapabilityMap();
	}

	public function supports(string $capability): bool {
		return $this->capabilityMap[$capability] ?? false;
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
	 * @param callable():mixed $operation
	 * @param callable(mixed):(?string)|null $messageIdResolver
	 */
	protected function execute(string $operationName, callable $operation, ?callable $messageIdResolver = null): MessageResult {
		$attempt = 0;
		$retry = (int) ($this->config['retry_attempts'] ?? 0);
		$retryDelayMs = (int) ($this->config['retry_delay_ms'] ?? 100);

		while (true) {
			++$attempt;

			try {
				$raw = $operation();
				$messageId = $messageIdResolver ? $messageIdResolver($raw) : $this->extractMessageId($raw);

				$this->logger->info('Messenger operation successful.', $this->sanitizeContext([
					'platform' => $this->platform(),
					'operation' => $operationName,
					'attempt' => $attempt,
					'message_id' => $messageId,
				]));

				return MessageResult::ok(
					platform: $this->platform(),
					messageId: $messageId,
					raw: $this->normalizeRaw($raw),
				);
			} catch (MessengerApiException $exception) {
				$this->logger->error('Messenger API error.', $this->sanitizeContext([
					'platform' => $this->platform(),
					'operation' => $operationName,
					'attempt' => $attempt,
					'api_code' => $exception->getApiCode(),
					'message' => $exception->getMessage(),
				]));

				return MessageResult::fail(
					platform: $this->platform(),
					code: $exception->getApiCode(),
					message: $this->maskSecrets($exception->getMessage()),
				);
			} catch (Throwable $exception) {
				if ($this->isTransportException($exception) && $attempt <= $retry + 1) {
					$this->logger->warning('Messenger transport error, applying retry.', $this->sanitizeContext([
						'platform' => $this->platform(),
						'operation' => $operationName,
						'attempt' => $attempt,
						'message' => $exception->getMessage(),
					]));

					if ($attempt <= $retry) {
						usleep($retryDelayMs * 1000);
						continue;
					}
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

	/**
	 * @return array<string, bool>
	 */
	protected function buildCapabilityMap(): array {
		$map = [];

		foreach (Capability::cases() as $capability) {
			$map[$capability->value] = true;
		}

		return $map;
	}

	protected function extractMessageId(mixed $raw): ?string {
		$rawArray = $this->normalizeRaw($raw);

		if (isset($rawArray['message_id'])) {
			return (string) $rawArray['message_id'];
		}

		if (isset($rawArray['result']) && is_array($rawArray['result']) && isset($rawArray['result']['message_id'])) {
			return (string) $rawArray['result']['message_id'];
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
		return interface_exists(\GuzzleHttp\Exception\GuzzleException::class)
			&& $throwable instanceof \GuzzleHttp\Exception\GuzzleException;
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

		$tokenKeys = ['token', 'secret', 'password', 'authorization', 'api_key', 'access_token'];

		foreach ($sanitized as $key => $value) {
			$normalizedKey = strtolower((string) $key);
			foreach ($tokenKeys as $tokenKey) {
				if (str_contains($normalizedKey, $tokenKey)) {
					$sanitized[$key] = '***';
					continue 2;
				}
			}
		}

		return $sanitized;
	}

	/**
	 * Prevents tokens from being explicitly visible in error messages
	 * (e.g., Guzzle: "https://api.telegram.org/bot<TOKEN>/getMe").
	 * Messages displayed in the admin panel pass through this filter.
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

	abstract public function platform(): string;
}

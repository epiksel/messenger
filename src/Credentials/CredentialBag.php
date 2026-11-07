<?php
declare(strict_types=1);
namespace MyMessenger\Credentials;

use MyMessenger\Exceptions\MessengerAuthException;

final class CredentialBag {
	/**
	 * @param array<string, mixed> $values
	 */
	public function __construct(
		private readonly array $values,
	) {
	}

	/**
	 * @return array<string, mixed>
	 */
	public function all(): array {
		return $this->values;
	}

	public function has(string $key): bool {
		return array_key_exists($key, $this->values);
	}

	public function get(string $key, mixed $default = null): mixed {
		return $this->values[$key] ?? $default;
	}

	public function require(string $key): mixed {
		if (!$this->has($key) || $this->get($key) === null || $this->get($key) === '') {
			throw new MessengerAuthException(sprintf('Required credential is missing: "%s".', $key));
		}

		return $this->values[$key];
	}

	public function __toString(): string {
		return json_encode($this->masked(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '[masked]';
	}

	/**
	 * @return array<string, mixed>
	 */
	public function __debugInfo(): array {
		return $this->masked();
	}

	/**
	 * @return array<string, mixed>
	 */
	private function masked(): array {
		$mask = static fn (mixed $value): string => match (true) {
			!is_string($value) => '***',
			strlen($value) <= 6 => '***',
			default => substr($value, 0, 3) . str_repeat('*', max(3, strlen($value) - 6)) . substr($value, -3),
		};

		$sensitiveKeys = ['token', 'secret', 'password', 'authorization', 'api_key', 'access_token'];

		$masked = $this->values;

		foreach ($masked as $key => $value) {
			$normalized = strtolower((string) $key);
			foreach ($sensitiveKeys as $sensitiveKey) {
				if (str_contains($normalized, $sensitiveKey)) {
					$masked[$key] = $mask($value);
					continue 2;
				}
			}
		}

		return $masked;
	}
}

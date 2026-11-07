<?php
declare(strict_types=1);
namespace MyMessenger\ValueObject;

/**
 * Immutable value object carrying the result of credential / recipient validation.
 *
 * - `reason`  : Machine-readable reason code (can be used as a language file key)
 * - `message` : Human-readable description (in English)
 * - `data`    : Useful information returned from the platform (bot username, chat title, etc.)
 */
final class ValidationResult {
	// Common reason codes
	public const string OK = 'ok';
	public const string EMPTY_VALUE = 'empty';
	public const string INVALID_FORMAT = 'invalid_format';
	public const string UNAUTHORIZED = 'unauthorized';
	public const string NOT_FOUND = 'not_found';
	public const string FORBIDDEN = 'forbidden';
	public const string NO_PERMISSION = 'no_permission';
	public const string NETWORK_ERROR = 'network_error';
	public const string UNKNOWN_ERROR = 'unknown_error';

	/**
	 * @param array<string, mixed> $data
	 */
	public function __construct(
		public readonly bool $valid,
		public readonly string $reason,
		public readonly string $message,
		public readonly string $platform,
		public readonly array $data = [],
	) {
	}

	/**
	 * @param array<string, mixed> $data
	 */
	public static function valid(string $platform, string $message = '', array $data = []): self {
		return new self(true, self::OK, $message, $platform, $data);
	}

	/**
	 * @param array<string, mixed> $data
	 */
	public static function invalid(string $platform, string $reason, string $message, array $data = []): self {
		return new self(false, $reason, $message, $platform, $data);
	}

	/**
	 * @return array{valid:bool, reason:string, message:string, platform:string, data:array<string, mixed>}
	 */
	public function toArray(): array {
		return [
			'valid' => $this->valid,
			'reason' => $this->reason,
			'message' => $this->message,
			'platform' => $this->platform,
			'data' => $this->data,
		];
	}
}
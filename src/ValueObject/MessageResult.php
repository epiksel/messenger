<?php
declare(strict_types=1);
namespace MyMessenger\ValueObject;

final class MessageResult {
	/**
	 * @param array<string, mixed> $raw
	 */
	public function __construct(
		public readonly bool $success,
		public readonly ?string $messageId,
		public readonly string $platform,
		public readonly array $raw = [],
		public readonly ?MessageError $error = null,
	) {
	}

	/**
	 * @param array<string, mixed> $raw
	 */
	public static function ok(string $platform, ?string $messageId = null, array $raw = []): self {
		return new self(
			success: true,
			messageId: $messageId,
			platform: $platform,
			raw: $raw,
			error: null,
		);
	}

	/**
	 * @param array<string, mixed> $raw
	 */
	public static function fail(string $platform, int|string|null $code, string $message, array $raw = []): self {
		return new self(
			success: false,
			messageId: null,
			platform: $platform,
			raw: $raw,
			error: new MessageError(code: $code, message: $message),
		);
	}

	/**
	 * @return array{
	 *     success:bool,
	 *     messageId:?string,
	 *     platform:string,
	 *     raw:array<string,mixed>,
	 *     error:?array{code:int|string|null, message:string}
	 * }
	 */
	public function toArray(): array {
		return [
			'success' => $this->success,
			'messageId' => $this->messageId,
			'platform' => $this->platform,
			'raw' => $this->raw,
			'error' => $this->error?->toArray(),
		];
	}
}

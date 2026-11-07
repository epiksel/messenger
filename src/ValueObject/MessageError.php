<?php
declare(strict_types=1);
namespace MyMessenger\ValueObject;

final class MessageError {
	public function __construct(
		public readonly int|string|null $code,
		public readonly string $message,
	) {
	}

	/**
	 * @return array{code:int|string|null, message:string}
	 */
	public function toArray(): array {
		return [
			'code' => $this->code,
			'message' => $this->message,
		];
	}
}

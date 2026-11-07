<?php
declare(strict_types=1);
namespace MyMessenger\Exceptions;

class MessengerApiException extends MessengerException {
	public function __construct(
		private readonly int|string|null $apiCode,
		string $message,
		int $code = 0,
		?\Throwable $previous = null,
	) {
		parent::__construct($message, $code, $previous);
	}

	public function getApiCode(): int|string|null {
		return $this->apiCode;
	}
}

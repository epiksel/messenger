<?php
declare(strict_types=1);
namespace MyMessenger\Credentials;

use MyMessenger\Exceptions\MessengerAuthException;

final class CallableCredentialProvider implements CredentialProviderInterface {
	/**
	 * @param callable(string):array<string, mixed>|CredentialBag $resolver
	 */
	public function __construct(
		private readonly mixed $resolver,
	) {
		if (!is_callable($this->resolver)) {
			throw new MessengerAuthException('A valid callable must be provided for CallableCredentialProvider.');
		}
	}

	#[\Override]
	public function getCredentials(string $platform): CredentialBag {
		$result = ($this->resolver)($platform);

		if ($result instanceof CredentialBag) {
			return $result;
		}

		if (!is_array($result)) {
			throw new MessengerAuthException('The callable credential provider must return an array or a CredentialBag.');
		}

		return new CredentialBag($result);
	}
}

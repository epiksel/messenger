<?php
declare(strict_types=1);
namespace MyMessenger\Credentials;

final class ArrayCredentialProvider implements CredentialProviderInterface {
	/**
	 * @param array<string, mixed> $credentials
	 */
	public function __construct(
		private readonly array $credentials,
	) {
	}

	#[\Override]
	public function getCredentials(string $platform): CredentialBag {
		$platformCredentials = $this->credentials[$platform] ?? $this->credentials;

		if (!is_array($platformCredentials)) {
			$platformCredentials = ['token' => $platformCredentials];
		}

		/** @var array<string, mixed> $platformCredentials */
		return new CredentialBag($platformCredentials);
	}
}

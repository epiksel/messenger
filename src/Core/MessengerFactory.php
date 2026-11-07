<?php
declare(strict_types=1);
namespace MyMessenger\Core;

use MyMessenger\Contracts\MessengerInterface;
use MyMessenger\Credentials\ArrayCredentialProvider;
use MyMessenger\Credentials\CredentialBag;
use MyMessenger\Credentials\CredentialProviderInterface;
use MyMessenger\Exceptions\MessengerException;
use MyMessenger\Logging\LoggerInterface;
use MyMessenger\Provider\Telegram;

final class MessengerFactory {
	/**
	 * @param array<string, mixed>|CredentialBag|CredentialProviderInterface $credentials
	 * @param array<string, mixed> $config
	 */
	public static function make(
		string $platform,
		array|CredentialBag|CredentialProviderInterface $credentials,
		array $config = [],
		?LoggerInterface $logger = null,
	): MessengerInterface {
		$normalizedPlatform = strtolower(trim($platform));
		$credentialBag = self::resolveCredentialBag($normalizedPlatform, $credentials);

		return match ($normalizedPlatform) {
			'telegram' => new Telegram\Messenger($credentialBag, $config, $logger),
			default => throw new MessengerException(sprintf(
				'Unknown messaging platform: "%s".',
				$platform,
			)),
		};
	}

	/**
	 * @param array<string, mixed>|CredentialBag|CredentialProviderInterface $credentials
	 */
	private static function resolveCredentialBag(
		string $platform,
		array|CredentialBag|CredentialProviderInterface $credentials,
	): CredentialBag {
		if ($credentials instanceof CredentialBag) {
			return $credentials;
		}

		if ($credentials instanceof CredentialProviderInterface) {
			return $credentials->getCredentials($platform);
		}

		$provider = new ArrayCredentialProvider($credentials);

		return $provider->getCredentials($platform);
	}
}

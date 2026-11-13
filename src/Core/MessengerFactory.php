<?php
declare(strict_types=1);
namespace MyMessenger\Core;

use MyMessenger\Contracts\MessengerInterface;
use MyMessenger\Credentials\ArrayCredentialProvider;
use MyMessenger\Credentials\CredentialBag;
use MyMessenger\Credentials\CredentialProviderInterface;
use MyMessenger\Exceptions\MessengerException;
use MyMessenger\Logging\LoggerInterface;
use MyMessenger\Provider\Facebook;
use MyMessenger\Provider\NetGSM;
use MyMessenger\Provider\Telegram;
use MyMessenger\Provider\WhatsApp;

/**
 * Resolves a provider instance from a platform name.
 *
 * ```php
 * $telegram = MessengerFactory::make('telegram', ['token' => '123:ABC']);
 * $whatsapp = MessengerFactory::make('whatsapp', [
 *     'token' => 'EAAG…',
 *     'phone_number_id' => '1234567890',
 * ]);
 * $facebook = MessengerFactory::make('facebook', ['token' => 'EAAG…', 'page_id' => '123']);
 * $netgsm   = MessengerFactory::make('netgsm', ['username' => '312XXXXXXX', 'password' => '…']);
 * ```
 */
final class MessengerFactory {
	/**
	 * Canonical platform names and their aliases.
	 *
	 * @var array<string, string>
	 */
	private const array ALIASES = [
		'telegram' => 'telegram',
		'tg' => 'telegram',
		'whatsapp' => 'whatsapp',
		'wa' => 'whatsapp',
		'whatsapp_cloud' => 'whatsapp',
		'facebook' => 'facebook',
		'facebook_messenger' => 'facebook',
		'messenger' => 'facebook',
		'fb' => 'facebook',
		'netgsm' => 'netgsm',
		'net_gsm' => 'netgsm',
		'sms' => 'netgsm',
	];

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
		$normalizedPlatform = self::normalizePlatform($platform);
		$credentialBag = self::resolveCredentialBag($normalizedPlatform, $credentials);

		return match ($normalizedPlatform) {
			'telegram' => new Telegram\Messenger($credentialBag, $config, $logger),
			'whatsapp' => new WhatsApp\Messenger($credentialBag, $config, $logger),
			'facebook' => new Facebook\Messenger($credentialBag, $config, $logger),
			'netgsm' => new NetGSM\Messenger($credentialBag, $config, $logger),
			default => throw new MessengerException(sprintf('Unknown messaging platform: "%s".', $platform)),
		};
	}

	/**
	 * @return list<string>
	 */
	public static function platforms(): array {
		return ['telegram', 'whatsapp', 'facebook', 'netgsm'];
	}

	public static function supports(string $platform): bool {
		return isset(self::ALIASES[self::normalizePlatform($platform)]);
	}

	/**
	 * Normalises a platform name or alias to its canonical form.
	 */
	public static function normalizePlatform(string $platform): string {
		$key = strtolower(trim($platform));
		$key = str_replace(['-', ' '], '_', $key);

		return self::ALIASES[$key] ?? $key;
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

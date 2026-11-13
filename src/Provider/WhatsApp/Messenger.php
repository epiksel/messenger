<?php
declare(strict_types=1);
namespace MyMessenger\Provider\WhatsApp;

use MyMessenger\Contracts\MessengerInterface;
use MyMessenger\Contracts\ValidatesCredentialsInterface;
use MyMessenger\Core\AbstractMessenger;
use MyMessenger\Credentials\CredentialBag;
use MyMessenger\Enums\Capability;
use MyMessenger\Exceptions\MessengerApiException;
use MyMessenger\Exceptions\MessengerException;
use MyMessenger\Logging\LoggerInterface;
use MyMessenger\ValueObject\MessageResult;
use MyMessenger\ValueObject\ValidationResult;

/**
 * WhatsApp Cloud API provider (https://developers.facebook.com/docs/whatsapp/cloud-api).
 *
 * Required credentials:
 * - `token`            : permanent system-user / app access token
 * - `phone_number_id`  : the Cloud API phone number id (not the phone number itself)
 *
 * Optional credentials:
 * - `business_account_id` : WhatsApp Business Account id, needed for template and phone-number listing
 *
 * Optional config:
 * - `api_version` : Graph API version, default `v27.0`
 * - `graph_base`  : default `https://graph.facebook.com`
 */
final class Messenger extends AbstractMessenger implements MessengerInterface, ValidatesCredentialsInterface {
	private const string DEFAULT_API_VERSION = 'v27.0';

	private const string DEFAULT_GRAPH_BASE = 'https://graph.facebook.com';

	private readonly string $phoneNumberId;

	private ?string $businessAccountId = null;

	/**
	 * @param array<string, mixed> $config
	 */
	public function __construct(
		CredentialBag $credentials,
		array $config = [],
		?LoggerInterface $logger = null,
		?\GuzzleHttp\ClientInterface $httpClient = null,
	) {
		if ($httpClient !== null) {
			$config['http_client'] = $httpClient;
		}

		parent::__construct($credentials, $config, $logger);

		if ($this->httpClient === null) {
			throw new MessengerException(
				'The guzzlehttp/guzzle package was not found. Guzzle 8 must be installed for WhatsApp requests.',
			);
		}

		$phoneNumberId = $credentials->get('phone_number_id') ?? $config['phone_number_id'] ?? null;

		if (!is_scalar($phoneNumberId) || trim((string) $phoneNumberId) === '') {
			throw new MessengerException(
				'WhatsApp requires the "phone_number_id" credential (Cloud API phone number id).',
			);
		}

		$this->phoneNumberId = trim((string) $phoneNumberId);

		$businessAccountId = $credentials->get('business_account_id') ?? $config['business_account_id'] ?? null;

		if (is_scalar($businessAccountId) && trim((string) $businessAccountId) !== '') {
			$this->businessAccountId = trim((string) $businessAccountId);
		}
	}

	#[\Override]
	public function platform(): string {
		return 'whatsapp';
	}

	#[\Override]
	protected function buildCapabilityMap(): array {
		return $this->capabilityMapFrom(
			Capability::SEND_MESSAGE,
			Capability::SEND_PHOTO,
			Capability::SEND_DOCUMENT,
			Capability::SEND_VIDEO,
			Capability::SEND_AUDIO,
			Capability::SEND_STICKER,
			Capability::SEND_LOCATION,
			Capability::SEND_CONTACT,
			Capability::SEND_TEMPLATE,
			Capability::SEND_INTERACTIVE,
			Capability::SEND_REACTION,
			Capability::MARK_AS_READ,
			Capability::GET_ACCOUNT_INFO,
			Capability::GET_PHONE_NUMBERS,
			Capability::GET_TEMPLATES,
			Capability::UPLOAD_MEDIA,
			Capability::GET_MEDIA,
			Capability::DELETE_MEDIA,
		);
	}

	/* ------------------------------------------------------------------ Text */

	#[\Override]
	public function sendMessage(string $recipientId, string $text, array $options = []): MessageResult {
		$this->assertCapability(Capability::SEND_MESSAGE);

		$previewUrl = (bool) ($options['preview_url'] ?? false);
		unset($options['preview_url']);

		$textPayload = [
			'body' => $text,
			'preview_url' => $previewUrl,
		];

		if (isset($options['text']) && is_array($options['text'])) {
			$textPayload = [...$textPayload, ...$options['text']];
			unset($options['text']);
		}

		$payload = [
			'messaging_product' => 'whatsapp',
			'recipient_type' => 'individual',
			'to' => $this->normalizeRecipient($recipientId),
			'type' => 'text',
			'text' => $textPayload,
			...$options,
		];

		return $this->execute('sendMessage', fn (): array => $this->sendPayload($payload));
	}

	/**
	 * WhatsApp Cloud API does not support editing sent messages.
	 */
	#[\Override]
	public function editMessage(string $recipientId, string $messageId, string $text, array $options = []): MessageResult {
		return $this->unsupported('editMessage');
	}

	/* ----------------------------------------------------------------- Media */

	#[\Override]
	public function sendPhoto(string $recipientId, mixed $photo, array $options = []): MessageResult {
		$this->assertCapability(Capability::SEND_PHOTO);

		return $this->execute('sendPhoto', fn (): array => $this->sendPayload(
			$this->mediaPayload($recipientId, 'image', $photo, $options, 'image'),
		));
	}

	#[\Override]
	public function sendDocument(string $recipientId, mixed $document, array $options = []): MessageResult {
		$this->assertCapability(Capability::SEND_DOCUMENT);

		return $this->execute('sendDocument', fn (): array => $this->sendPayload(
			$this->mediaPayload($recipientId, 'document', $document, $options, 'document'),
		));
	}

	#[\Override]
	public function sendVideo(string $recipientId, mixed $video, array $options = []): MessageResult {
		$this->assertCapability(Capability::SEND_VIDEO);

		return $this->execute('sendVideo', fn (): array => $this->sendPayload(
			$this->mediaPayload($recipientId, 'video', $video, $options, 'video'),
		));
	}

	#[\Override]
	public function sendAudio(string $recipientId, mixed $audio, array $options = []): MessageResult {
		$this->assertCapability(Capability::SEND_AUDIO);

		return $this->execute('sendAudio', fn (): array => $this->sendPayload(
			$this->mediaPayload($recipientId, 'audio', $audio, $options, 'audio'),
		));
	}

	#[\Override]
	public function sendSticker(string $recipientId, mixed $sticker, array $options = []): MessageResult {
		$this->assertCapability(Capability::SEND_STICKER);

		return $this->execute('sendSticker', fn (): array => $this->sendPayload(
			$this->mediaPayload($recipientId, 'sticker', $sticker, $options, 'sticker'),
		));
	}

	/* ------------------------------------------------------------ Structured */

	#[\Override]
	public function sendLocation(string $recipientId, float $latitude, float $longitude, array $options = []): MessageResult {
		$this->assertCapability(Capability::SEND_LOCATION);

		$payload = [
			'messaging_product' => 'whatsapp',
			'recipient_type' => 'individual',
			'to' => $this->normalizeRecipient($recipientId),
			'type' => 'location',
			'location' => [
				'latitude' => $latitude,
				'longitude' => $longitude,
				...($options['location'] ?? []),
			],
			...$this->without($options, ['location']),
		];

		return $this->execute('sendLocation', fn (): array => $this->sendPayload($payload));
	}

	#[\Override]
	public function sendContact(string $recipientId, string $phoneNumber, string $firstName, array $options = []): MessageResult {
		$this->assertCapability(Capability::SEND_CONTACT);

		$contact = [
			'name' => [
				'formatted_name' => $firstName,
				...($options['name'] ?? []),
			],
			'phones' => $options['phones'] ?? [[
				'phone' => $phoneNumber,
				'type' => 'CELL',
			]],
		];

		if (isset($options['emails'])) {
			$contact['emails'] = $options['emails'];
		}

		if (isset($options['addresses'])) {
			$contact['addresses'] = $options['addresses'];
		}

		$payload = [
			'messaging_product' => 'whatsapp',
			'recipient_type' => 'individual',
			'to' => $this->normalizeRecipient($recipientId),
			'type' => 'contacts',
			'contacts' => [$contact],
			...$this->without($options, ['name', 'phones', 'emails', 'addresses']),
		];

		return $this->execute('sendContact', fn (): array => $this->sendPayload($payload));
	}

	/**
	 * Sends a pre-approved template message.
	 *
	 * ```php
	 * $messenger->sendTemplate('905551112233', 'order_update', [
	 *     'language' => ['code' => 'tr'],
	 *     'components' => [[
	 *         'type' => 'body',
	 *         'parameters' => [['type' => 'text', 'text' => '#4711']],
	 *     ]],
	 * ]);
	 * ```
	 *
	 * @param array<string, mixed> $options
	 */
	public function sendTemplate(string $recipientId, mixed $template, array $options = []): MessageResult {
		$this->assertCapability(Capability::SEND_TEMPLATE);

		if (!is_string($template) || trim($template) === '') {
			throw new MessengerApiException(
				apiCode: null,
				message: 'WhatsApp sendTemplate() expects the approved template name as a string.',
			);
		}

		$templatePayload = [
			'name' => trim($template),
			'language' => ['code' => (string) ($options['language']['code'] ?? $options['language_code'] ?? 'en_US')],
		];

		if (isset($options['components'])) {
			$templatePayload['components'] = $options['components'];
		}

		$payload = [
			'messaging_product' => 'whatsapp',
			'recipient_type' => 'individual',
			'to' => $this->normalizeRecipient($recipientId),
			'type' => 'template',
			'template' => $templatePayload,
			...$this->without($options, ['language', 'language_code', 'components']),
		];

		return $this->execute('sendTemplate', fn (): array => $this->sendPayload($payload));
	}

	/**
	 * Sends an interactive message (buttons, list or flow).
	 *
	 * @param array<string, mixed> $interactive e.g. ['type' => 'button', 'body' => ['text' => '…'], 'action' => [...]]
	 * @param array<string, mixed> $options
	 */
	public function sendInteractive(string $recipientId, array $interactive, array $options = []): MessageResult {
		$this->assertCapability(Capability::SEND_INTERACTIVE);

		$payload = [
			'messaging_product' => 'whatsapp',
			'recipient_type' => 'individual',
			'to' => $this->normalizeRecipient($recipientId),
			'type' => 'interactive',
			'interactive' => $interactive,
			...$options,
		];

		return $this->execute('sendInteractive', fn (): array => $this->sendPayload($payload));
	}

	/**
	 * Reacts to an incoming message. Pass an empty emoji to remove an existing reaction.
	 *
	 * @param array<string, mixed> $options
	 */
	#[\Override]
	public function sendReaction(string $recipientId, string $messageId, string $emoji, array $options = []): MessageResult {
		$this->assertCapability(Capability::SEND_REACTION);

		$payload = [
			'messaging_product' => 'whatsapp',
			'recipient_type' => 'individual',
			'to' => $this->normalizeRecipient($recipientId),
			'type' => 'reaction',
			'reaction' => [
				'message_id' => $messageId,
				'emoji' => $emoji,
			],
			...$options,
		];

		return $this->execute('sendReaction', fn (): array => $this->sendPayload($payload));
	}

	/**
	 * Marks an incoming message as read (blue ticks). Optionally shows the typing indicator.
	 *
	 * @param array<string, mixed> $options
	 */
	#[\Override]
	public function markAsRead(string $messageId, array $options = []): MessageResult {
		$this->assertCapability(Capability::MARK_AS_READ);

		$payload = [
			'messaging_product' => 'whatsapp',
			'status' => 'read',
			'message_id' => $messageId,
			...$options,
		];

		return $this->execute('markAsRead', fn (): array => $this->sendPayload($payload));
	}

	/* --------------------------------------------------------------- Account */

	/**
	 * Returns the Cloud API phone number profile (display number, verified name, quality rating…).
	 *
	 * @param array<string, mixed> $options
	 */
	#[\Override]
	public function getAccountInfo(array $options = []): MessageResult {
		$this->assertCapability(Capability::GET_ACCOUNT_INFO);

		$fields = (string) ($options['fields'] ?? 'id,display_phone_number,verified_name,quality_rating,platform_type,code_verification_status');

		return $this->execute('getAccountInfo', fn (): array => $this->send('GET', $this->phoneEndpoint(), [
			'headers' => $this->authHeaders(),
			'query' => ['fields' => $fields],
		]));
	}

	/**
	 * Lists the phone numbers of the WhatsApp Business Account.
	 *
	 * @param array<string, mixed> $options
	 */
	#[\Override]
	public function getPhoneNumbers(array $options = []): MessageResult {
		$this->assertCapability(Capability::GET_PHONE_NUMBERS);

		$businessAccountId = $this->requireBusinessAccountId();

		return $this->execute('getPhoneNumbers', fn (): array => $this->send(
			'GET',
			sprintf('%s/%s/phone_numbers', $this->graphBase(), $businessAccountId),
			[
				'headers' => $this->authHeaders(),
				'query' => $options,
			],
		));
	}

	/**
	 * Lists message templates of the WhatsApp Business Account.
	 *
	 * @param array<string, mixed> $options e.g. ['limit' => 100, 'status' => 'APPROVED']
	 */
	#[\Override]
	public function getTemplates(array $options = []): MessageResult {
		$this->assertCapability(Capability::GET_TEMPLATES);

		$businessAccountId = $this->requireBusinessAccountId();

		return $this->execute('getTemplates', fn (): array => $this->send(
			'GET',
			sprintf('%s/%s/message_templates', $this->graphBase(), $businessAccountId),
			[
				'headers' => $this->authHeaders(),
				'query' => $options,
			],
		));
	}

	/* ------------------------------------------------------------------ Media */

	/**
	 * Uploads a local file to the Cloud API media store and returns its media id.
	 *
	 * @param string $mediaType MIME type of the file, e.g. `image/jpeg` or `application/pdf`
	 * @param array<string, mixed> $options
	 */
	#[\Override]
	public function uploadMedia(string $filePath, string $mediaType, array $options = []): MessageResult {
		$this->assertCapability(Capability::UPLOAD_MEDIA);

		if (!is_file($filePath)) {
			throw new MessengerApiException(
				apiCode: null,
				message: sprintf('Media file not found: %s', $filePath),
			);
		}

		$handle = @fopen($filePath, 'rb');

		if ($handle === false) {
			throw new MessengerApiException(apiCode: null, message: sprintf('Could not open media file: %s', $filePath));
		}

		$multipart = [
			[
				'name' => 'messaging_product',
				'contents' => 'whatsapp',
			],
			[
				'name' => 'type',
				'contents' => $mimeType,
			],
			[
				'name' => 'file',
				'contents' => $handle,
				'filename' => basename($filePath),
			],
		];

		return $this->execute('uploadMedia', fn (): array => $this->send(
			'POST',
			sprintf('%s/media', $this->phoneEndpoint()),
			[
				'headers' => ['Authorization' => 'Bearer ' . $this->token],
				'multipart' => $multipart,
				...$options,
			],
		), static fn (array $raw): ?string => isset($raw['id']) ? (string) $raw['id'] : null);
	}

	/**
	 * Resolves a media id to its download URL and metadata.
	 *
	 * @param array<string, mixed> $options
	 */
	#[\Override]
	public function getMedia(string $mediaId, array $options = []): MessageResult {
		$this->assertCapability(Capability::GET_MEDIA);

		return $this->execute('getMedia', fn (): array => $this->send(
			'GET',
			sprintf('%s/%s', $this->graphBase(), $mediaId),
			[
				'headers' => $this->authHeaders(),
				'query' => $options,
			],
		));
	}

	/**
	 * @param array<string, mixed> $options
	 */
	#[\Override]
	public function deleteMedia(string $mediaId, array $options = []): MessageResult {
		$this->assertCapability(Capability::DELETE_MEDIA);

		return $this->execute('deleteMedia', fn (): array => $this->send(
			'DELETE',
			sprintf('%s/%s', $this->graphBase(), $mediaId),
			[
				'headers' => $this->authHeaders(),
				'query' => $options,
			],
		));
	}

	/* ------------------------------------------------------------ Validation */

	/**
	 * Access tokens are long opaque strings; only emptiness is checked locally.
	 */
	#[\Override]
	public static function isCredentialFormatValid(string $token): bool {
		return trim($token) !== '' && strlen(trim($token)) >= 20;
	}

	/**
	 * WhatsApp recipients are E.164 numbers without "+", spaces or dashes.
	 */
	#[\Override]
	public static function isRecipientFormatValid(string $recipientId): bool {
		return (bool) preg_match('/^\d{8,15}$/', trim($recipientId));
	}

	#[\Override]
	public function validateCredentials(): ValidationResult {
		if (trim($this->token) === '') {
			return ValidationResult::invalid('whatsapp', ValidationResult::EMPTY_VALUE, 'Access token cannot be empty.');
		}

		$result = $this->getAccountInfo();

		if (!$result->success) {
			return $this->classifyFailure($result, 'token');
		}

		$account = $result->raw;

		return ValidationResult::valid(
			'whatsapp',
			sprintf(
				'Access token is valid. Number: %s (%s)',
				(string) ($account['display_phone_number'] ?? $this->phoneNumberId),
				(string) ($account['verified_name'] ?? 'unnamed'),
			),
			[
				'phone_number_id' => $account['id'] ?? $this->phoneNumberId,
				'display_phone_number' => $account['display_phone_number'] ?? null,
				'verified_name' => $account['verified_name'] ?? null,
				'quality_rating' => $account['quality_rating'] ?? null,
				'platform_type' => $account['platform_type'] ?? null,
			],
		);
	}

	/**
	 * Format check plus an optional real test message.
	 *
	 * The Cloud API has no "does this number exist" endpoint, so no lookup is attempted.
	 *
	 * @param array<string, mixed> $options
	 */
	#[\Override]
	public function validateRecipient(string $recipientId, array $options = []): ValidationResult {
		$recipientId = trim($recipientId);

		if ($recipientId === '') {
			return ValidationResult::invalid('whatsapp', ValidationResult::EMPTY_VALUE, 'Phone number cannot be empty.');
		}

		if (!self::isRecipientFormatValid($recipientId)) {
			return ValidationResult::invalid(
				'whatsapp',
				ValidationResult::INVALID_FORMAT,
				'Invalid phone number. Expected E.164 digits without "+", e.g. 905551112233.',
			);
		}

		if (($options['send_test_message'] ?? false) === true) {
			$text = (string) ($options['test_message'] ?? '✅ WhatsApp connection successfully validated.');
			$result = $this->sendMessage($recipientId, $text);

			if (!$result->success) {
				return $this->classifyFailure($result, 'recipient', ['phone' => $recipientId]);
			}

			return ValidationResult::valid('whatsapp', 'Test message accepted by the Cloud API.', [
				'phone' => $recipientId,
				'test_message_id' => $result->messageId,
			]);
		}

		return ValidationResult::valid('whatsapp', 'Phone number format is valid.', ['phone' => $recipientId]);
	}

	/**
	 * @param 'token'|'recipient' $context
	 * @param array<string, mixed> $data
	 */
	private function classifyFailure(MessageResult $result, string $context, array $data = []): ValidationResult {
		$code = $result->error?->code;
		$code = is_numeric($code) ? (int) $code : 0;
		$description = $result->error?->message ?? '';
		$lower = strtolower($description);
		$data['api_code'] = $code;
		$data['api_message'] = $description;

		[$reason, $message] = match (true) {
			$code === 190, $code === 401, str_contains($lower, 'access token') => [
				ValidationResult::UNAUTHORIZED,
				'Access token is invalid, expired or lacks the whatsapp_business_messaging permission.',
			],
			$code === 100 && $context === 'token' => [
				ValidationResult::NOT_FOUND,
				'Phone number id not found. Check the "phone_number_id" credential.',
			],
			$code === 131026, str_contains($lower, 'not a valid whatsapp') => [
				ValidationResult::NOT_FOUND,
				'The recipient is not a WhatsApp user or the number is not reachable.',
			],
			$code === 131047 => [
				ValidationResult::FORBIDDEN,
				'Outside the 24-hour customer service window: send an approved template instead.',
			],
			$code === 131056 => [
				ValidationResult::FORBIDDEN,
				'Pair rate limit reached: too many messages to the same recipient.',
			],
			$code === 132000, $code === 132001 => [
				ValidationResult::INVALID_FORMAT,
				'Template does not exist or the parameters do not match the approved template.',
			],
			$code === 4, $code === 80007, $code === 130429 => [
				ValidationResult::UNKNOWN_ERROR,
				'Cloud API rate limit reached. Retry later.',
			],
			$code === 0 => [ValidationResult::NETWORK_ERROR, 'Could not reach the Graph API: ' . $description],
			default => [ValidationResult::UNKNOWN_ERROR, 'Unexpected WhatsApp error: ' . $description],
		};

		return ValidationResult::invalid('whatsapp', $reason, $message, $data);
	}

	/* ------------------------------------------------------------------ HTTP */

	/**
	 * @param array<string, mixed> $payload
	 * @return array<string, mixed>
	 */
	private function sendPayload(array $payload): array {
		return $this->send('POST', $this->messagesEndpoint(), [
			'headers' => $this->authHeaders(),
			'json' => $payload,
		]);
	}

	/**
	 * Builds an image/document/video/audio/sticker payload, uploading local files first.
	 *
	 * @param array<string, mixed> $options
	 * @return array<string, mixed>
	 */
	private function mediaPayload(string $recipientId, string $type, mixed $media, array $options, string $field): array {
		$mediaObject = [];

		$normalized = $this->normalizeMedia($media, $field);

		if (is_array($normalized)) {
			$upload = $this->uploadMedia($normalized['__file'], (string) ($options['mime_type'] ?? 'application/octet-stream'));

			if (!$upload->success || $upload->messageId === null) {
				throw new MessengerApiException(
					apiCode: $upload->error?->code,
					message: 'Media upload failed: ' . ($upload->error?->message ?? 'unknown error'),
				);
			}

			$mediaObject['id'] = $upload->messageId;
		} else {
			$mediaObject['link'] = $normalized;
		}

		if (isset($options['caption'])) {
			$mediaObject['caption'] = (string) $options['caption'];
		}

		if (isset($options['filename']) && $type === 'document') {
			$mediaObject['filename'] = (string) $options['filename'];
		}

		$payload = [
			'messaging_product' => 'whatsapp',
			'recipient_type' => 'individual',
			'to' => $this->normalizeRecipient($recipientId),
			'type' => $type,
			$type => $mediaObject,
			...$this->without($options, ['caption', 'filename', 'mime_type']),
		];

		return $payload;
	}

	/**
	 * Strips the given keys from an options array (used to avoid duplicated payload keys).
	 *
	 * @param array<string, mixed> $options
	 * @param list<string> $keys
	 * @return array<string, mixed>
	 */
	private function without(array $options, array $keys): array {
		foreach ($keys as $key) {
			unset($options[$key]);
		}

		return $options;
	}

	private function normalizeRecipient(string $recipientId): string {
		return preg_replace('/\D+/', '', $recipientId) ?? $recipientId;
	}

	private function requireBusinessAccountId(): string {
		if ($this->businessAccountId === null) {
			throw new MessengerApiException(
				apiCode: null,
				message: 'WhatsApp requires the "business_account_id" credential for this operation.',
			);
		}

		return $this->businessAccountId;
	}

	private function graphBase(): string {
		$base = (string) ($this->config['graph_base'] ?? self::DEFAULT_GRAPH_BASE);
		$version = (string) ($this->config['api_version'] ?? self::DEFAULT_API_VERSION);

		return rtrim($base, '/') . '/' . trim($version, '/');
	}

	private function phoneEndpoint(): string {
		return sprintf('%s/%s', $this->graphBase(), $this->phoneNumberId);
	}

	private function messagesEndpoint(): string {
		return sprintf('%s/messages', $this->phoneEndpoint());
	}
}

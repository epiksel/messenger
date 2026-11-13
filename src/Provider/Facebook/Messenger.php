<?php
declare(strict_types=1);
namespace MyMessenger\Provider\Facebook;

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
 * Facebook Messenger Platform provider (https://developers.facebook.com/docs/messenger-platform).
 *
 * Required credentials:
 * - `token` : Page access token
 *
 * Optional credentials:
 * - `page_id` : when set, `/{page_id}/messages` is used instead of `/me/messages`
 *
 * Optional config:
 * - `api_version` : Graph API version, default `v27.0`
 * - `graph_base`  : default `https://graph.facebook.com`
 *
 * Note: the Send API accepts either a public `url` or an uploaded `attachment_id` for media.
 * Local files are uploaded through `POST /{page_id}/message_attachments` first.
 */
final class Messenger extends AbstractMessenger implements MessengerInterface, ValidatesCredentialsInterface {
	private const string DEFAULT_API_VERSION = 'v27.0';

	private const string DEFAULT_GRAPH_BASE = 'https://graph.facebook.com';

	private readonly ?string $pageId;

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
				'The guzzlehttp/guzzle package was not found. Guzzle 8 must be installed for Facebook Messenger requests.',
			);
		}

		$pageId = $credentials->get('page_id') ?? $config['page_id'] ?? null;
		$this->pageId = is_scalar($pageId) && trim((string) $pageId) !== '' ? trim((string) $pageId) : null;
	}

	#[\Override]
	public function platform(): string {
		return 'facebook';
	}

	#[\Override]
	protected function buildCapabilityMap(): array {
		return $this->capabilityMapFrom(
			Capability::SEND_MESSAGE,
			Capability::SEND_PHOTO,
			Capability::SEND_DOCUMENT,
			Capability::SEND_VIDEO,
			Capability::SEND_AUDIO,
			Capability::SEND_LOCATION,
			Capability::SEND_CONTACT,
			Capability::SEND_QUICK_REPLIES,
			Capability::SEND_BUTTON_TEMPLATE,
			Capability::SEND_GENERIC_TEMPLATE,
			Capability::SEND_TEMPLATE,
			Capability::SEND_INTERACTIVE,
			Capability::SEND_REACTION,
			Capability::SEND_SENDER_ACTION,
			Capability::MARK_AS_READ,
			Capability::GET_ACCOUNT_INFO,
			Capability::GET_USER_PROFILE,
			Capability::SET_WEBHOOK,
			Capability::GET_WEBHOOK_INFO,
			Capability::DELETE_WEBHOOK,
			Capability::UPLOAD_MEDIA,
		);
	}

	/* ------------------------------------------------------------------ Text */

	#[\Override]
	public function sendMessage(string $recipientId, string $text, array $options = []): MessageResult {
		$this->assertCapability(Capability::SEND_MESSAGE);

		return $this->execute('sendMessage', fn (): array => $this->sendApi([
			'messaging_type' => (string) ($options['messaging_type'] ?? 'RESPONSE'),
			'recipient' => ['id' => $recipientId],
			'message' => ['text' => $text],
			...$this->without($options, ['messaging_type']),
		]));
	}

	/* ----------------------------------------------------------------- Media */

	#[\Override]
	public function sendPhoto(string $recipientId, mixed $photo, array $options = []): MessageResult {
		$this->assertCapability(Capability::SEND_PHOTO);

		return $this->execute('sendPhoto', fn (): array => $this->sendApi(
			$this->attachmentPayload($recipientId, 'image', $photo, $options, 'image'),
		));
	}

	#[\Override]
	public function sendDocument(string $recipientId, mixed $document, array $options = []): MessageResult {
		$this->assertCapability(Capability::SEND_DOCUMENT);

		return $this->execute('sendDocument', fn (): array => $this->sendApi(
			$this->attachmentPayload($recipientId, 'file', $document, $options, 'document'),
		));
	}

	#[\Override]
	public function sendVideo(string $recipientId, mixed $video, array $options = []): MessageResult {
		$this->assertCapability(Capability::SEND_VIDEO);

		return $this->execute('sendVideo', fn (): array => $this->sendApi(
			$this->attachmentPayload($recipientId, 'video', $video, $options, 'video'),
		));
	}

	#[\Override]
	public function sendAudio(string $recipientId, mixed $audio, array $options = []): MessageResult {
		$this->assertCapability(Capability::SEND_AUDIO);

		return $this->execute('sendAudio', fn (): array => $this->sendApi(
			$this->attachmentPayload($recipientId, 'audio', $audio, $options, 'audio'),
		));
	}

	/* ------------------------------------------------------------ Structured */

	#[\Override]
	public function sendLocation(string $recipientId, float $latitude, float $longitude, array $options = []): MessageResult {
		$this->assertCapability(Capability::SEND_LOCATION);

		return $this->execute('sendLocation', fn (): array => $this->sendApi([
			'messaging_type' => 'RESPONSE',
			'recipient' => ['id' => $recipientId],
			'message' => [
				'attachment' => [
					'type' => 'location',
					'payload' => [
						'coordinates' => [
							'lat' => $latitude,
							'long' => $longitude,
						],
					],
				],
			],
			...$options,
		]));
	}

	#[\Override]
	public function sendContact(string $recipientId, string $phoneNumber, string $firstName, array $options = []): MessageResult {
		$this->assertCapability(Capability::SEND_CONTACT);

		return $this->execute('sendContact', fn (): array => $this->sendApi([
			'messaging_type' => 'RESPONSE',
			'recipient' => ['id' => $recipientId],
			'message' => [
				'attachment' => [
					'type' => 'template',
					'payload' => [
						'template_type' => 'generic',
						'elements' => [[
							'title' => $firstName,
							'subtitle' => $phoneNumber,
							'buttons' => [[
								'type' => 'phone_number',
								'title' => 'Call',
								'payload' => $phoneNumber,
							]],
						]],
					],
				],
			],
			...$options,
		]));
	}

	/**
	 * Quick replies (max 13).
	 *
	 * @param list<array<string, mixed>> $quickReplies
	 * @param array<string, mixed> $options
	 */
	public function sendQuickReplies(string $recipientId, string $text, array $quickReplies, array $options = []): MessageResult {
		$this->assertCapability(Capability::SEND_QUICK_REPLIES);

		if (count($quickReplies) > 13) {
			throw new MessengerApiException(apiCode: null, message: 'Facebook allows at most 13 quick replies.');
		}

		return $this->execute('sendQuickReplies', fn (): array => $this->sendApi([
			'messaging_type' => 'RESPONSE',
			'recipient' => ['id' => $recipientId],
			'message' => [
				'text' => $text,
				'quick_replies' => array_values($quickReplies),
			],
			...$options,
		]));
	}

	/**
	 * @param list<array<string, mixed>> $buttons max 3
	 * @param array<string, mixed> $options
	 */
	public function sendButtonTemplate(string $recipientId, string $text, array $buttons, array $options = []): MessageResult {
		$this->assertCapability(Capability::SEND_BUTTON_TEMPLATE);

		if (count($buttons) > 3) {
			throw new MessengerApiException(apiCode: null, message: 'A Facebook button template allows at most 3 buttons.');
		}

		return $this->execute('sendButtonTemplate', fn (): array => $this->sendApi([
			'messaging_type' => 'RESPONSE',
			'recipient' => ['id' => $recipientId],
			'message' => [
				'attachment' => [
					'type' => 'template',
					'payload' => [
						'template_type' => 'button',
						'text' => $text,
						'buttons' => array_values($buttons),
					],
				],
			],
			...$options,
		]));
	}

	/**
	 * @param list<array<string, mixed>> $elements max 10
	 * @param array<string, mixed> $options
	 */
	public function sendGenericTemplate(string $recipientId, array $elements, array $options = []): MessageResult {
		$this->assertCapability(Capability::SEND_GENERIC_TEMPLATE);

		if (count($elements) > 10) {
			throw new MessengerApiException(apiCode: null, message: 'A Facebook generic template allows at most 10 elements.');
		}

		return $this->execute('sendGenericTemplate', fn (): array => $this->sendApi([
			'messaging_type' => 'RESPONSE',
			'recipient' => ['id' => $recipientId],
			'message' => [
				'attachment' => [
					'type' => 'template',
					'payload' => [
						'template_type' => 'generic',
						'elements' => array_values($elements),
					],
				],
			],
			...$options,
		]));
	}

	/**
	 * Generic passthrough for any other template payload (media, receipt, coupon…).
	 *
	 * @param array<string, mixed> $templatePayload e.g. ['template_type' => 'media', 'elements' => [...]]
	 * @param array<string, mixed> $options
	 */
	public function sendTemplate(string $recipientId, mixed $templatePayload, array $options = []): MessageResult {
		$this->assertCapability(Capability::SEND_TEMPLATE);

		if (!is_array($templatePayload)) {
			throw new MessengerApiException(
				apiCode: null,
				message: 'Facebook sendTemplate() expects the template payload as an array.',
			);
		}

		return $this->execute('sendTemplate', fn (): array => $this->sendApi([
			'messaging_type' => 'RESPONSE',
			'recipient' => ['id' => $recipientId],
			'message' => [
				'attachment' => [
					'type' => 'template',
					'payload' => $templatePayload,
				],
			],
			...$options,
		]));
	}

	/**
	 * Passthrough for any raw Send API message object (e.g. Handover Protocol payloads).
	 *
	 * @param array<string, mixed> $message
	 * @param array<string, mixed> $options
	 */
	public function sendInteractive(string $recipientId, array $message, array $options = []): MessageResult {
		$this->assertCapability(Capability::SEND_INTERACTIVE);

		return $this->execute('sendInteractive', fn (): array => $this->sendApi([
			'messaging_type' => 'RESPONSE',
			'recipient' => ['id' => $recipientId],
			'message' => $message,
			...$options,
		]));
	}

	#[\Override]
	public function sendReaction(string $recipientId, string $messageId, string $emoji, array $options = []): MessageResult {
		$this->assertCapability(Capability::SEND_REACTION);

		return $this->execute('sendReaction', fn (): array => $this->sendApi([
			'recipient' => ['id' => $recipientId],
			'payload' => [
				'action' => 'react',
				'reaction' => $emoji,
				'message_id' => $messageId,
			],
			...$options,
		]));
	}

	/* -------------------------------------------------------- Chat behaviour */

	/**
	 * @param string $action one of typing_on, typing_off, mark_seen, react
	 * @param array<string, mixed> $options
	 */
	#[\Override]
	public function sendSenderAction(string $recipientId, string $action, array $options = []): MessageResult {
		$this->assertCapability(Capability::SEND_SENDER_ACTION);

		$payload = [
			'recipient' => ['id' => $recipientId],
			'sender_action' => $action,
			...$options,
		];

		return $this->execute('sendSenderAction', fn (): array => $this->sendApi($payload));
	}

	/**
	 * Marks the conversation as seen (`sender_action=mark_seen`).
	 *
	 * The Send API has no message-id based read endpoint, so the recipient id is required
	 * through `$options['recipient_id']`.
	 *
	 * @param array<string, mixed> $options
	 */
	#[\Override]
	public function markAsRead(string $messageId, array $options = []): MessageResult {
		$this->assertCapability(Capability::MARK_AS_READ);

		$recipientId = $options['recipient_id'] ?? null;

		if (!is_scalar($recipientId) || trim((string) $recipientId) === '') {
			throw new MessengerApiException(
				apiCode: null,
				message: 'Facebook markAsRead() requires $options["recipient_id"].',
			);
		}

		return $this->sendSenderAction(trim((string) $recipientId), 'mark_seen', $this->without($options, ['recipient_id']));
	}

	/* --------------------------------------------------------------- Account */

	#[\Override]
	public function getAccountInfo(array $options = []): MessageResult {
		$this->assertCapability(Capability::GET_ACCOUNT_INFO);

		$fields = (string) ($options['fields'] ?? 'id,name,username,category,link,fan_count');

		return $this->execute('getAccountInfo', fn (): array => $this->send('GET', $this->endpoint('me'), [
			'headers' => $this->authHeaders(),
			'query' => ['fields' => $fields],
		]));
	}

	/**
	 * Messenger Profile / user profile lookup.
	 *
	 * @param array<string, mixed> $options
	 */
	#[\Override]
	public function getUserProfile(string $userId, array $options = []): MessageResult {
		$this->assertCapability(Capability::GET_USER_PROFILE);

		$fields = (string) ($options['fields'] ?? 'first_name,last_name,profile_pic,locale,timezone,gender');

		return $this->execute('getUserProfile', fn (): array => $this->send('GET', $this->endpoint($userId), [
			'headers' => $this->authHeaders(),
			'query' => ['fields' => $fields],
		]));
	}

	/* --------------------------------------------------------------- Webhook */

	/**
	 * Subscribes the app to the page with the given webhook callback URL.
	 *
	 * @param array<string, mixed> $options
	 */
	#[\Override]
	public function setWebhook(string $url, array $options = []): MessageResult {
		$this->assertCapability(Capability::SET_WEBHOOK);

		$pageId = $this->requirePageId();

		$payload = [
			'object' => 'page',
			'callback_url' => $url,
			'verify_token' => (string) ($options['verify_token'] ?? ''),
			'fields' => $options['fields'] ?? ['messages', 'messaging_postbacks', 'message_reads', 'message_reactions'],
			...$this->without($options, ['verify_token', 'fields']),
		];

		return $this->execute('setWebhook', fn (): array => $this->send('POST', $this->endpoint($pageId . '/subscribed_apps'), [
			'headers' => $this->authHeaders(),
			'json' => $payload,
		]));
	}

	#[\Override]
	public function getWebhookInfo(): MessageResult {
		$this->assertCapability(Capability::GET_WEBHOOK_INFO);

		$pageId = $this->requirePageId();

		return $this->execute('getWebhookInfo', fn (): array => $this->send('GET', $this->endpoint($pageId . '/subscribed_apps'), [
			'headers' => $this->authHeaders(),
		]));
	}

	#[\Override]
	public function deleteWebhook(): MessageResult {
		$this->assertCapability(Capability::DELETE_WEBHOOK);

		$pageId = $this->requirePageId();

		return $this->execute('deleteWebhook', fn (): array => $this->send(
			'DELETE',
			$this->endpoint($pageId . '/subscribed_apps'),
			['headers' => $this->authHeaders()],
		));
	}

	/* ------------------------------------------------------------------ Media */

	/**
	 * Uploads a local file and returns its reusable `attachment_id`.
	 *
	 * @param string $mediaType Messenger attachment type: `image`, `file`, `video` or `audio`
	 * @param array<string, mixed> $options
	 */
	#[\Override]
	public function uploadMedia(string $filePath, string $mediaType, array $options = []): MessageResult {
		$this->assertCapability(Capability::UPLOAD_MEDIA);

		if (!is_file($filePath)) {
			throw new MessengerApiException(apiCode: null, message: sprintf('Media file not found: %s', $filePath));
		}

		$handle = @fopen($filePath, 'rb');

		if ($handle === false) {
			throw new MessengerApiException(apiCode: null, message: sprintf('Could not open media file: %s', $filePath));
		}

		$multipart = [
			[
				'name' => 'message',
				'contents' => json_encode([
					'attachment' => ['type' => $mediaType, 'payload' => ['is_reusable' => true]],
				], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
			],
			[
				'name' => 'filedata',
				'contents' => $handle,
				'filename' => basename($filePath),
			],
		];

		return $this->execute('uploadMedia', fn (): array => $this->send(
			'POST',
			$this->endpoint($this->pageId !== null ? $this->pageId . '/message_attachments' : 'me/message_attachments'),
			[
				'headers' => ['Authorization' => 'Bearer ' . $this->token],
				'multipart' => $multipart,
				...$options,
			],
		), static fn (array $raw): ?string => isset($raw['attachment_id']) ? (string) $raw['attachment_id'] : null);
	}

	/* ------------------------------------------------------------ Validation */

	/**
	 * Page access tokens are opaque strings; only length/emptiness is checked locally.
	 */
	#[\Override]
	public static function isCredentialFormatValid(string $token): bool {
		return trim($token) !== '' && strlen(trim($token)) >= 20;
	}

	/**
	 * Page-scoped ids (PSID) are numeric strings.
	 */
	#[\Override]
	public static function isRecipientFormatValid(string $recipientId): bool {
		return (bool) preg_match('/^\d{5,25}$/', trim($recipientId));
	}

	#[\Override]
	public function validateCredentials(): ValidationResult {
		if (trim($this->token) === '') {
			return ValidationResult::invalid('facebook', ValidationResult::EMPTY_VALUE, 'Page access token cannot be empty.');
		}

		$result = $this->getAccountInfo();

		if (!$result->success) {
			return $this->classifyFailure($result, 'token');
		}

		$page = $result->raw;

		return ValidationResult::valid(
			'facebook',
			sprintf('Page access token is valid. Page: %s', (string) ($page['name'] ?? $page['id'] ?? '')),
			[
				'page_id' => $page['id'] ?? null,
				'page_name' => $page['name'] ?? null,
				'username' => $page['username'] ?? null,
				'category' => $page['category'] ?? null,
				'fan_count' => $page['fan_count'] ?? null,
			],
		);
	}

	/**
	 * Format check, optional profile lookup, optional real test message.
	 *
	 * Facebook only returns a profile for users who have interacted with the page (or when the
	 * app has `pages_messaging` + advanced access), so a failed lookup is not treated as fatal
	 * unless `require_profile` is set.
	 *
	 * @param array<string, mixed> $options
	 */
	#[\Override]
	public function validateRecipient(string $recipientId, array $options = []): ValidationResult {
		$recipientId = trim($recipientId);

		if ($recipientId === '') {
			return ValidationResult::invalid('facebook', ValidationResult::EMPTY_VALUE, 'Recipient ID cannot be empty.');
		}

		if (!self::isRecipientFormatValid($recipientId)) {
			return ValidationResult::invalid(
				'facebook',
				ValidationResult::INVALID_FORMAT,
				'Invalid recipient ID. Expected the numeric page-scoped id (PSID) of the user.',
			);
		}

		$data = ['recipient_id' => $recipientId];

		if (($options['require_profile'] ?? false) === true) {
			$profile = $this->getUserProfile($recipientId);

			if (!$profile->success) {
				return $this->classifyFailure($profile, 'recipient', $data);
			}

			$data['first_name'] = $profile->raw['first_name'] ?? null;
			$data['last_name'] = $profile->raw['last_name'] ?? null;
			$data['profile_pic'] = $profile->raw['profile_pic'] ?? null;
		}

		if (($options['send_test_message'] ?? false) === true) {
			$text = (string) ($options['test_message'] ?? '✅ Facebook Messenger connection successfully validated.');
			$result = $this->sendMessage($recipientId, $text, ['messaging_type' => 'UPDATE']);

			if (!$result->success) {
				return $this->classifyFailure($result, 'recipient', $data);
			}

			$data['test_message_id'] = $result->messageId;
		}

		return ValidationResult::valid('facebook', 'Recipient ID is valid.', $data);
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
			$code === 190, str_contains($lower, 'access token') => [
				ValidationResult::UNAUTHORIZED,
				'Page access token is invalid, expired or revoked.',
			],
			$code === 200, $code === 10 => [
				ValidationResult::NO_PERMISSION,
				'The token lacks the required permission (pages_messaging / pages_manage_metadata).',
			],
			$code === 100 && $context === 'recipient' => [
				ValidationResult::NOT_FOUND,
				'Recipient not found. The user must message the page first.',
			],
			$code === 100 => [ValidationResult::INVALID_FORMAT, 'Facebook rejected the request: ' . $description],
			$code === 613, $code === 4, $code === 32 => [
				ValidationResult::UNKNOWN_ERROR,
				'Facebook rate limit reached. Retry later.',
			],
			$code === 551 => [ValidationResult::NOT_FOUND, 'The user is not available (deactivated or blocked the page).'],
			$code === 0 => [ValidationResult::NETWORK_ERROR, 'Could not reach the Graph API: ' . $description],
			default => [ValidationResult::UNKNOWN_ERROR, 'Unexpected Facebook error: ' . $description],
		};

		return ValidationResult::invalid('facebook', $reason, $message, $data);
	}

	/* ------------------------------------------------------------------ HTTP */

	/**
	 * @param array<string, mixed> $payload
	 * @return array<string, mixed>
	 */
	private function sendApi(array $payload): array {
		return $this->send('POST', $this->messagesEndpoint(), [
			'headers' => $this->authHeaders(),
			'json' => $payload,
		]);
	}

	/**
	 * @param array<string, mixed> $options
	 * @return array<string, mixed>
	 */
	private function attachmentPayload(string $recipientId, string $attachmentType, mixed $media, array $options, string $field): array {
		$normalized = $this->normalizeMedia($media, $field);

		$payload = is_array($normalized)
			? ['attachment_id' => $this->uploadAttachment($normalized['__file'], $attachmentType)]
			: ['url' => $normalized, 'is_reusable' => true];

		return [
			'messaging_type' => 'RESPONSE',
			'recipient' => ['id' => $recipientId],
			'message' => [
				'attachment' => [
					'type' => $attachmentType,
					'payload' => $payload,
				],
			],
			...$this->without($options, ['is_reusable']),
		];
	}

	private function uploadAttachment(string $filePath, string $type): string {
		$upload = $this->uploadMedia($filePath, $type);

		if (!$upload->success || $upload->messageId === null) {
			throw new MessengerApiException(
				apiCode: $upload->error?->code,
				message: 'Attachment upload failed: ' . ($upload->error?->message ?? 'unknown error'),
			);
		}

		return $upload->messageId;
	}

	/**
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

	private function requirePageId(): string {
		if ($this->pageId === null) {
			throw new MessengerApiException(
				apiCode: null,
				message: 'Facebook requires the "page_id" credential for this operation.',
			);
		}

		return $this->pageId;
	}

	private function graphBase(): string {
		$base = (string) ($this->config['graph_base'] ?? self::DEFAULT_GRAPH_BASE);
		$version = (string) ($this->config['api_version'] ?? self::DEFAULT_API_VERSION);

		return rtrim($base, '/') . '/' . trim($version, '/');
	}

	private function messagesEndpoint(): string {
		return $this->endpoint($this->pageId !== null ? $this->pageId . '/messages' : 'me/messages');
	}

	private function endpoint(string $path): string {
		return sprintf('%s/%s', $this->graphBase(), ltrim($path, '/'));
	}
}

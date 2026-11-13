<?php
declare(strict_types=1);
namespace MyMessenger\Provider\Telegram;

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
 * Telegram Bot API provider (https://core.telegram.org/bots/api).
 *
 * Requests are sent as JSON, except when a payload contains a local file: those are sent as
 * `multipart/form-data`, which is what the Bot API expects for uploads.
 */
final class Messenger extends AbstractMessenger implements MessengerInterface, ValidatesCredentialsInterface {
	private const string API_BASE = 'https://api.telegram.org';

	private ?string $cachedBotId = null;

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
				'The guzzlehttp/guzzle package was not found. Guzzle 8 must be installed for Telegram requests.',
			);
		}
	}

	#[\Override]
	public function platform(): string {
		return 'telegram';
	}

	#[\Override]
	protected function buildCapabilityMap(): array {
		return $this->capabilityMapFrom(
			Capability::SEND_MESSAGE,
			Capability::EDIT_MESSAGE,
			Capability::DELETE_MESSAGE,
			Capability::FORWARD_MESSAGE,
			Capability::COPY_MESSAGE,
			Capability::SEND_PHOTO,
			Capability::SEND_DOCUMENT,
			Capability::SEND_VIDEO,
			Capability::SEND_AUDIO,
			Capability::SEND_VOICE,
			Capability::SEND_VIDEO_NOTE,
			Capability::SEND_STICKER,
			Capability::SEND_MEDIA_GROUP,
			Capability::SEND_LOCATION,
			Capability::SEND_CONTACT,
			Capability::SEND_POLL,
			Capability::STOP_POLL,
			Capability::SEND_DICE,
			Capability::SEND_REACTION,
			Capability::SEND_CHAT_ACTION,
			Capability::PIN_MESSAGE,
			Capability::UNPIN_MESSAGE,
			Capability::GET_ME,
			Capability::GET_ACCOUNT_INFO,
			Capability::SET_MY_COMMANDS,
			Capability::GET_MY_COMMANDS,
			Capability::DELETE_MY_COMMANDS,
			Capability::SET_WEBHOOK,
			Capability::DELETE_WEBHOOK,
			Capability::GET_WEBHOOK_INFO,
			Capability::GET_UPDATES,
			Capability::ANSWER_CALLBACK_QUERY,
			Capability::GET_CHAT,
			Capability::GET_CHAT_MEMBER,
			Capability::GET_CHAT_ADMINISTRATORS,
			Capability::GET_CHAT_MEMBER_COUNT,
			Capability::SET_CHAT_TITLE,
			Capability::BAN_CHAT_MEMBER,
			Capability::UNBAN_CHAT_MEMBER,
			Capability::LEAVE_CHAT,
			Capability::GET_FILE,
		);
	}

	/* ------------------------------------------------------------------ Text */

	#[\Override]
	public function sendMessage(string $recipientId, string $text, array $options = []): MessageResult {
		$this->assertCapability(Capability::SEND_MESSAGE);

		return $this->execute('sendMessage', fn (): array => $this->call('sendMessage', [
			'chat_id' => $recipientId,
			'text' => $text,
			...$options,
		]));
	}

	#[\Override]
	public function editMessage(string $recipientId, string $messageId, string $text, array $options = []): MessageResult {
		$this->assertCapability(Capability::EDIT_MESSAGE);

		return $this->execute('editMessageText', fn (): array => $this->call('editMessageText', [
			'chat_id' => $recipientId,
			'message_id' => $messageId,
			'text' => $text,
			...$options,
		]));
	}

	#[\Override]
	public function deleteMessage(string $recipientId, string $messageId, array $options = []): MessageResult {
		$this->assertCapability(Capability::DELETE_MESSAGE);

		return $this->execute('deleteMessage', fn (): array => $this->call('deleteMessage', [
			'chat_id' => $recipientId,
			'message_id' => $messageId,
			...$options,
		]));
	}

	#[\Override]
	public function forwardMessage(string $recipientId, string $fromRecipientId, string $messageId, array $options = []): MessageResult {
		$this->assertCapability(Capability::FORWARD_MESSAGE);

		return $this->execute('forwardMessage', fn (): array => $this->call('forwardMessage', [
			'chat_id' => $recipientId,
			'from_chat_id' => $fromRecipientId,
			'message_id' => $messageId,
			...$options,
		]));
	}

	#[\Override]
	public function copyMessage(string $recipientId, string $fromRecipientId, string $messageId, array $options = []): MessageResult {
		$this->assertCapability(Capability::COPY_MESSAGE);

		return $this->execute('copyMessage', fn (): array => $this->call('copyMessage', [
			'chat_id' => $recipientId,
			'from_chat_id' => $fromRecipientId,
			'message_id' => $messageId,
			...$options,
		]));
	}

	/* ----------------------------------------------------------------- Media */

	#[\Override]
	public function sendPhoto(string $recipientId, mixed $photo, array $options = []): MessageResult {
		$this->assertCapability(Capability::SEND_PHOTO);

		return $this->execute('sendPhoto', fn (): array => $this->call('sendPhoto', [
			'chat_id' => $recipientId,
			'photo' => $this->normalizeMedia($photo, 'photo'),
			...$options,
		]));
	}

	#[\Override]
	public function sendDocument(string $recipientId, mixed $document, array $options = []): MessageResult {
		$this->assertCapability(Capability::SEND_DOCUMENT);

		return $this->execute('sendDocument', fn (): array => $this->call('sendDocument', [
			'chat_id' => $recipientId,
			'document' => $this->normalizeMedia($document, 'document'),
			...$options,
		]));
	}

	#[\Override]
	public function sendVideo(string $recipientId, mixed $video, array $options = []): MessageResult {
		$this->assertCapability(Capability::SEND_VIDEO);

		return $this->execute('sendVideo', fn (): array => $this->call('sendVideo', [
			'chat_id' => $recipientId,
			'video' => $this->normalizeMedia($video, 'video'),
			...$options,
		]));
	}

	#[\Override]
	public function sendAudio(string $recipientId, mixed $audio, array $options = []): MessageResult {
		$this->assertCapability(Capability::SEND_AUDIO);

		return $this->execute('sendAudio', fn (): array => $this->call('sendAudio', [
			'chat_id' => $recipientId,
			'audio' => $this->normalizeMedia($audio, 'audio'),
			...$options,
		]));
	}

	#[\Override]
	public function sendVoice(string $recipientId, mixed $voice, array $options = []): MessageResult {
		$this->assertCapability(Capability::SEND_VOICE);

		return $this->execute('sendVoice', fn (): array => $this->call('sendVoice', [
			'chat_id' => $recipientId,
			'voice' => $this->normalizeMedia($voice, 'voice'),
			...$options,
		]));
	}

	#[\Override]
	public function sendVideoNote(string $recipientId, mixed $videoNote, array $options = []): MessageResult {
		$this->assertCapability(Capability::SEND_VIDEO_NOTE);

		return $this->execute('sendVideoNote', fn (): array => $this->call('sendVideoNote', [
			'chat_id' => $recipientId,
			'video_note' => $this->normalizeMedia($videoNote, 'video_note'),
			...$options,
		]));
	}

	#[\Override]
	public function sendSticker(string $recipientId, mixed $sticker, array $options = []): MessageResult {
		$this->assertCapability(Capability::SEND_STICKER);

		return $this->execute('sendSticker', fn (): array => $this->call('sendSticker', [
			'chat_id' => $recipientId,
			'sticker' => $this->normalizeMedia($sticker, 'sticker'),
			...$options,
		]));
	}

	/**
	 * Sends 2–10 photos/videos/documents as a single album.
	 *
	 * Local files are uploaded through `attach://<name>` references, as required by the Bot API.
	 *
	 * @param list<array<string, mixed>> $media
	 * @param array<string, mixed> $options
	 */
	#[\Override]
	public function sendMediaGroup(string $recipientId, array $media, array $options = []): MessageResult {
		$this->assertCapability(Capability::SEND_MEDIA_GROUP);

		if (count($media) < 2 || count($media) > 10) {
			throw new MessengerApiException(
				apiCode: null,
				message: 'A media group must contain between 2 and 10 items.',
			);
		}

		$payload = [
			'chat_id' => $recipientId,
			...$options,
		];

		$normalized = [];

		foreach (array_values($media) as $index => $item) {
			if (!is_array($item)) {
				throw new MessengerApiException(apiCode: null, message: 'Each media group item must be an array.');
			}

			$value = $item['media'] ?? null;
			$normalizedItem = $item;

			if (is_string($value) && $value !== '' && !$this->isRemoteUrl($value) && is_file($value)) {
				$field = sprintf('file%d', $index);
				$payload[$field] = $this->createInputFile($value);
				$normalizedItem['media'] = 'attach://' . $field;
			}

			$normalized[] = $normalizedItem;
		}

		$payload['media'] = $normalized;

		return $this->execute('sendMediaGroup', fn (): array => $this->call('sendMediaGroup', $payload));
	}

	/* ------------------------------------------------------------ Structured */

	#[\Override]
	public function sendLocation(string $recipientId, float $latitude, float $longitude, array $options = []): MessageResult {
		$this->assertCapability(Capability::SEND_LOCATION);

		return $this->execute('sendLocation', fn (): array => $this->call('sendLocation', [
			'chat_id' => $recipientId,
			'latitude' => $latitude,
			'longitude' => $longitude,
			...$options,
		]));
	}

	#[\Override]
	public function sendContact(string $recipientId, string $phoneNumber, string $firstName, array $options = []): MessageResult {
		$this->assertCapability(Capability::SEND_CONTACT);

		return $this->execute('sendContact', fn (): array => $this->call('sendContact', [
			'chat_id' => $recipientId,
			'phone_number' => $phoneNumber,
			'first_name' => $firstName,
			...$options,
		]));
	}

	/**
	 * @param list<string> $answers
	 * @param array<string, mixed> $options
	 */
	#[\Override]
	public function sendPoll(string $recipientId, string $question, array $answers, array $options = []): MessageResult {
		$this->assertCapability(Capability::SEND_POLL);

		$isQuiz = ($options['type'] ?? 'regular') === 'quiz';
		$max = $isQuiz ? 10 : 12;

		if (count($answers) < 2 || count($answers) > $max) {
			throw new MessengerApiException(
				apiCode: null,
				message: sprintf('A poll must have between 2 and %d answers.', $max),
			);
		}

		return $this->execute('sendPoll', fn (): array => $this->call('sendPoll', [
			'chat_id' => $recipientId,
			'question' => $question,
			'options' => array_values($answers),
			...$options,
		]));
	}

	#[\Override]
	public function stopPoll(string $recipientId, string $messageId, array $options = []): MessageResult {
		$this->assertCapability(Capability::STOP_POLL);

		return $this->execute('stopPoll', fn (): array => $this->call('stopPoll', [
			'chat_id' => $recipientId,
			'message_id' => $messageId,
			...$options,
		]));
	}

	#[\Override]
	public function sendDice(string $recipientId, array $options = []): MessageResult {
		$this->assertCapability(Capability::SEND_DICE);

		return $this->execute('sendDice', fn (): array => $this->call('sendDice', [
			'chat_id' => $recipientId,
			...$options,
		]));
	}

	#[\Override]
	public function sendReaction(string $recipientId, string $messageId, string $emoji, array $options = []): MessageResult {
		$this->assertCapability(Capability::SEND_REACTION);

		return $this->execute('setMessageReaction', fn (): array => $this->call('setMessageReaction', [
			'chat_id' => $recipientId,
			'message_id' => $messageId,
			'reaction' => [['type' => 'emoji', 'emoji' => $emoji]],
			...$options,
		]));
	}

	/* -------------------------------------------------------- Chat behaviour */

	#[\Override]
	public function sendChatAction(string $recipientId, string $action, array $options = []): MessageResult {
		$this->assertCapability(Capability::SEND_CHAT_ACTION);

		return $this->execute('sendChatAction', fn (): array => $this->call('sendChatAction', [
			'chat_id' => $recipientId,
			'action' => $action,
			...$options,
		]));
	}

	#[\Override]
	public function pinMessage(string $recipientId, string $messageId, array $options = []): MessageResult {
		$this->assertCapability(Capability::PIN_MESSAGE);

		return $this->execute('pinChatMessage', fn (): array => $this->call('pinChatMessage', [
			'chat_id' => $recipientId,
			'message_id' => $messageId,
			...$options,
		]));
	}

	#[\Override]
	public function unpinMessage(string $recipientId, array $options = []): MessageResult {
		$this->assertCapability(Capability::UNPIN_MESSAGE);

		return $this->execute('unpinChatMessage', fn (): array => $this->call('unpinChatMessage', [
			'chat_id' => $recipientId,
			...$options,
		]));
	}

	/* --------------------------------------------------------------- Account */

	#[\Override]
	public function getMe(): MessageResult {
		$this->assertCapability(Capability::GET_ME);

		return $this->execute('getMe', fn (): array => $this->call('getMe'));
	}

	#[\Override]
	public function getAccountInfo(): MessageResult {
		$this->assertCapability(Capability::GET_ACCOUNT_INFO);

		return $this->getMe();
	}

	/**
	 * @param list<array<string, mixed>> $commands
	 * @param array<string, mixed> $options
	 */
	#[\Override]
	public function setMyCommands(array $commands, array $options = []): MessageResult {
		$this->assertCapability(Capability::SET_MY_COMMANDS);

		return $this->execute('setMyCommands', fn (): array => $this->call('setMyCommands', [
			'commands' => array_values($commands),
			...$options,
		]));
	}

	#[\Override]
	public function getMyCommands(array $options = []): MessageResult {
		$this->assertCapability(Capability::GET_MY_COMMANDS);

		return $this->execute('getMyCommands', fn (): array => $this->call('getMyCommands', $options));
	}

	#[\Override]
	public function deleteMyCommands(array $options = []): MessageResult {
		$this->assertCapability(Capability::DELETE_MY_COMMANDS);

		return $this->execute('deleteMyCommands', fn (): array => $this->call('deleteMyCommands', $options));
	}

	/* --------------------------------------------------------------- Webhook */

	#[\Override]
	public function setWebhook(string $url, array $options = []): MessageResult {
		$this->assertCapability(Capability::SET_WEBHOOK);

		return $this->execute('setWebhook', fn (): array => $this->call('setWebhook', [
			'url' => $url,
			...$options,
		]));
	}

	#[\Override]
	public function deleteWebhook(): MessageResult {
		$this->assertCapability(Capability::DELETE_WEBHOOK);

		/** @var array<string, mixed> $options */
		$options = is_array($this->config['delete_webhook_options'] ?? null)
			? $this->config['delete_webhook_options']
			: [];

		return $this->execute('deleteWebhook', fn (): array => $this->call('deleteWebhook', $options));
	}

	#[\Override]
	public function getWebhookInfo(): MessageResult {
		$this->assertCapability(Capability::GET_WEBHOOK_INFO);

		return $this->execute('getWebhookInfo', fn (): array => $this->call('getWebhookInfo'));
	}

	#[\Override]
	public function getUpdates(array $options = []): MessageResult {
		$this->assertCapability(Capability::GET_UPDATES);

		return $this->execute('getUpdates', fn (): array => $this->call('getUpdates', $options));
	}

	#[\Override]
	public function answerCallbackQuery(string $callbackQueryId, array $options = []): MessageResult {
		$this->assertCapability(Capability::ANSWER_CALLBACK_QUERY);

		return $this->execute('answerCallbackQuery', fn (): array => $this->call('answerCallbackQuery', [
			'callback_query_id' => $callbackQueryId,
			...$options,
		]));
	}

	/* ------------------------------------------------------------------ Chat */

	#[\Override]
	public function getChat(string $recipientId, array $options = []): MessageResult {
		$this->assertCapability(Capability::GET_CHAT);

		return $this->execute('getChat', fn (): array => $this->call('getChat', [
			'chat_id' => $recipientId,
			...$options,
		]));
	}

	#[\Override]
	public function getChatMember(string $recipientId, string $userId, array $options = []): MessageResult {
		$this->assertCapability(Capability::GET_CHAT_MEMBER);

		return $this->execute('getChatMember', fn (): array => $this->call('getChatMember', [
			'chat_id' => $recipientId,
			'user_id' => $userId,
			...$options,
		]));
	}

	#[\Override]
	public function getChatAdministrators(string $recipientId, array $options = []): MessageResult {
		$this->assertCapability(Capability::GET_CHAT_ADMINISTRATORS);

		return $this->execute('getChatAdministrators', fn (): array => $this->call('getChatAdministrators', [
			'chat_id' => $recipientId,
			...$options,
		]));
	}

	#[\Override]
	public function getChatMemberCount(string $recipientId, array $options = []): MessageResult {
		$this->assertCapability(Capability::GET_CHAT_MEMBER_COUNT);

		return $this->execute('getChatMemberCount', fn (): array => $this->call('getChatMemberCount', [
			'chat_id' => $recipientId,
			...$options,
		]));
	}

	#[\Override]
	public function setChatTitle(string $recipientId, string $title, array $options = []): MessageResult {
		$this->assertCapability(Capability::SET_CHAT_TITLE);

		return $this->execute('setChatTitle', fn (): array => $this->call('setChatTitle', [
			'chat_id' => $recipientId,
			'title' => $title,
			...$options,
		]));
	}

	#[\Override]
	public function banChatMember(string $recipientId, string $userId, array $options = []): MessageResult {
		$this->assertCapability(Capability::BAN_CHAT_MEMBER);

		return $this->execute('banChatMember', fn (): array => $this->call('banChatMember', [
			'chat_id' => $recipientId,
			'user_id' => $userId,
			...$options,
		]));
	}

	#[\Override]
	public function unbanChatMember(string $recipientId, string $userId, array $options = []): MessageResult {
		$this->assertCapability(Capability::UNBAN_CHAT_MEMBER);

		return $this->execute('unbanChatMember', fn (): array => $this->call('unbanChatMember', [
			'chat_id' => $recipientId,
			'user_id' => $userId,
			...$options,
		]));
	}

	#[\Override]
	public function leaveChat(string $recipientId, array $options = []): MessageResult {
		$this->assertCapability(Capability::LEAVE_CHAT);

		return $this->execute('leaveChat', fn (): array => $this->call('leaveChat', [
			'chat_id' => $recipientId,
			...$options,
		]));
	}

	/* ------------------------------------------------------------------ File */

	#[\Override]
	public function getFile(string $fileId, array $options = []): MessageResult {
		$this->assertCapability(Capability::GET_FILE);

		return $this->execute('getFile', fn (): array => $this->call('getFile', [
			'file_id' => $fileId,
			...$options,
		]));
	}

	/**
	 * Absolute download URL for a file returned by {@see getFile()}.
	 */
	public function fileUrl(string $filePath): string {
		return sprintf('%s/file/bot%s/%s', self::API_BASE, $this->token, ltrim($filePath, '/'));
	}

	/* ------------------------------------------------------------ Validation */

	/**
	 * Telegram bot token format: "<bot_id>:<35+ character secret>", e.g. 123456789:AAH4x…
	 * Local check only, no network request.
	 */
	#[\Override]
	public static function isCredentialFormatValid(string $token): bool {
		return (bool) preg_match('/^\d{5,16}:[A-Za-z0-9_-]{30,64}$/', trim($token));
	}

	/**
	 * Valid chat_id formats (local check only):
	 * - user / private chat  : 123456789
	 * - group                : -123456789
	 * - supergroup / channel : -1001234567890
	 * - public channel/group : @channel_name
	 */
	#[\Override]
	public static function isRecipientFormatValid(string $recipientId): bool {
		$recipientId = trim($recipientId);

		return (bool) preg_match('/^-?\d{1,20}$/', $recipientId)
			|| (bool) preg_match('/^@[A-Za-z][A-Za-z0-9_]{3,31}$/', $recipientId);
	}

	#[\Override]
	public function validateCredentials(): ValidationResult {
		if (trim($this->token) === '') {
			return ValidationResult::invalid('telegram', ValidationResult::EMPTY_VALUE, 'Bot token cannot be empty.');
		}

		if (!self::isCredentialFormatValid($this->token)) {
			return ValidationResult::invalid(
				'telegram',
				ValidationResult::INVALID_FORMAT,
				'Invalid bot token format. Expected: 123456789:ABCdef… (value from BotFather).',
			);
		}

		$result = $this->getMe();

		if (!$result->success) {
			return $this->classifyFailure($result, 'token');
		}

		$bot = $this->unwrapResult($result->raw);

		if (($bot['is_bot'] ?? false) !== true) {
			return ValidationResult::invalid(
				'telegram',
				ValidationResult::UNAUTHORIZED,
				'The token does not belong to a bot account.',
				$bot,
			);
		}

		return ValidationResult::valid(
			'telegram',
			sprintf('Token is valid. Bot: @%s', (string) ($bot['username'] ?? '')),
			[
				'id' => $bot['id'] ?? null,
				'username' => $bot['username'] ?? null,
				'first_name' => $bot['first_name'] ?? null,
				'can_join_groups' => $bot['can_join_groups'] ?? null,
			],
		);
	}

	/**
	 * 1) local format check, 2) getChat, 3) getChatMember (groups/channels),
	 * 4) sendChatAction (silent write test), 5) optional real test message.
	 *
	 * @param array<string, mixed> $options
	 */
	#[\Override]
	public function validateRecipient(string $recipientId, array $options = []): ValidationResult {
		$recipientId = trim($recipientId);

		if ($recipientId === '') {
			return ValidationResult::invalid('telegram', ValidationResult::EMPTY_VALUE, 'Chat ID cannot be empty.');
		}

		if (!self::isRecipientFormatValid($recipientId)) {
			return ValidationResult::invalid(
				'telegram',
				ValidationResult::INVALID_FORMAT,
				'Invalid Chat ID format. Example: 123456789, -1001234567890 or @channel_name',
			);
		}

		$chatResult = $this->getChat($recipientId);
		if (!$chatResult->success) {
			return $this->classifyFailure($chatResult, 'chat');
		}

		$chat = $this->unwrapResult($chatResult->raw);
		$type = (string) ($chat['type'] ?? 'private');
		$data = [
			'id' => $chat['id'] ?? $recipientId,
			'type' => $type,
			'title' => $chat['title'] ?? trim(((string) ($chat['first_name'] ?? '')) . ' ' . ((string) ($chat['last_name'] ?? ''))),
			'username' => $chat['username'] ?? null,
		];

		if ($type !== 'private') {
			$permission = $this->checkBotMembership($recipientId, $type, $data);
			if ($permission !== null) {
				return $permission;
			}
		}

		if ($type !== 'channel') {
			$actionResult = $this->sendChatAction($recipientId, 'typing');
			if (!$actionResult->success) {
				return $this->classifyFailure($actionResult, 'chat', $data);
			}
		}

		if (($options['send_test_message'] ?? false) === true) {
			$text = (string) ($options['test_message'] ?? '✅ Telegram connection successfully validated.');
			$sendResult = $this->sendMessage($recipientId, $text);
			if (!$sendResult->success) {
				return $this->classifyFailure($sendResult, 'chat', $data);
			}
			$data['test_message_id'] = $sendResult->messageId;
		}

		return ValidationResult::valid(
			'telegram',
			sprintf('Chat ID is valid (%s%s).', $type, $data['title'] !== '' ? ': ' . $data['title'] : ''),
			$data,
		);
	}

	/**
	 * @param array<string, mixed> $data
	 */
	private function checkBotMembership(string $recipientId, string $type, array &$data): ?ValidationResult {
		$botId = $this->botId();
		if ($botId === null) {
			return null;
		}

		$memberResult = $this->getChatMember($recipientId, $botId);
		if (!$memberResult->success) {
			return $this->classifyFailure($memberResult, 'chat', $data);
		}

		$member = $this->unwrapResult($memberResult->raw);
		$status = (string) ($member['status'] ?? '');
		$data['bot_status'] = $status;

		if (in_array($status, ['left', 'kicked'], true)) {
			return ValidationResult::invalid(
				'telegram',
				ValidationResult::FORBIDDEN,
				'The bot is not a member of this group/channel. Add the bot to the chat.',
				$data,
			);
		}

		if ($type === 'channel') {
			$canPost = $status === 'creator'
				|| ($status === 'administrator' && ($member['can_post_messages'] ?? false) === true);

			if (!$canPost) {
				return ValidationResult::invalid(
					'telegram',
					ValidationResult::NO_PERMISSION,
					'The bot is not an administrator in the channel or lacks the "Post Messages" permission.',
					$data,
				);
			}
		}

		if ($status === 'restricted' && ($member['can_send_messages'] ?? true) === false) {
			return ValidationResult::invalid(
				'telegram',
				ValidationResult::NO_PERMISSION,
				'The bot is restricted from sending messages in this group.',
				$data,
			);
		}

		return null;
	}

	private function botId(): ?string {
		if ($this->cachedBotId !== null) {
			return $this->cachedBotId;
		}

		$prefix = strstr($this->token, ':', true);
		if ($prefix !== false && ctype_digit($prefix)) {
			return $this->cachedBotId = $prefix;
		}

		$me = $this->getMe();
		$id = $this->unwrapResult($me->raw)['id'] ?? null;

		return $this->cachedBotId = ($me->success && $id !== null ? (string) $id : null);
	}

	/**
	 * @param 'token'|'chat' $context
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
			$code === 401, $code === 404 && $context === 'token' => [
				ValidationResult::UNAUTHORIZED,
				'Bot token is invalid or revoked (Telegram: Unauthorized).',
			],
			str_contains($lower, 'chat not found') => [
				ValidationResult::NOT_FOUND,
				'Chat not found. Check the Chat ID; for private chats the user must send /start to the bot first.',
			],
			str_contains($lower, 'blocked by the user') => [
				ValidationResult::FORBIDDEN,
				'The user has blocked the bot.',
			],
			str_contains($lower, 'kicked'), str_contains($lower, 'not a member') => [
				ValidationResult::FORBIDDEN,
				'The bot is not a member of this group/channel or has been removed from the chat.',
			],
			str_contains($lower, 'not enough rights'), str_contains($lower, 'have no rights') => [
				ValidationResult::NO_PERMISSION,
				'The bot does not have permission to send messages in this chat.',
			],
			$code === 403 => [ValidationResult::FORBIDDEN, 'The bot cannot access this chat.'],
			$code === 400 => [ValidationResult::INVALID_FORMAT, 'Telegram rejected the request: ' . $description],
			$code === 429 => [ValidationResult::UNKNOWN_ERROR, 'Telegram temporarily blocked due to too many requests. Try again later.'],
			$code === 0 => [ValidationResult::NETWORK_ERROR, 'Could not connect to the Telegram server: ' . $description],
			default => [ValidationResult::UNKNOWN_ERROR, 'Unexpected Telegram error: ' . $description],
		};

		return ValidationResult::invalid('telegram', $reason, $message, $data);
	}

	/**
	 * @param array<string, mixed> $raw
	 * @return array<string, mixed>
	 */
	private function unwrapResult(array $raw): array {
		if (isset($raw['result']) && is_array($raw['result'])) {
			return $raw['result'];
		}

		return $raw;
	}

	/* ------------------------------------------------------------------ HTTP */

	/**
	 * Calls a Bot API method and returns the decoded envelope (`['ok' => true, 'result' => …]`).
	 *
	 * @param array<string, mixed> $payload
	 * @return array<string, mixed>
	 */
	private function call(string $method, array $payload = []): array {
		$options = ['headers' => ['Content-Type' => 'application/json']];

		if ($this->hasFileField($payload)) {
			$options = ['multipart' => $this->buildMultipart($payload)];
		} elseif ($payload !== []) {
			$options['json'] = $payload;
		}

		$decoded = $this->send('POST', $this->endpoint($method), $options);

		if (($decoded['ok'] ?? false) !== true) {
			throw new MessengerApiException(
				apiCode: $decoded['error_code'] ?? null,
				message: (string) ($decoded['description'] ?? 'Telegram request failed.'),
				code: (int) ($decoded['error_code'] ?? 0),
			);
		}

		return $decoded;
	}

	/**
	 * The token is part of the URL, so it is masked in every error message.
	 */
	private function endpoint(string $method): string {
		return sprintf('%s/bot%s/%s', self::API_BASE, $this->token, $method);
	}
}

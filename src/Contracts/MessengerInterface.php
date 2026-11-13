<?php
declare(strict_types=1);
namespace MyMessenger\Contracts;

use MyMessenger\ValueObject\MessageResult;

/**
 * Platform-agnostic messaging contract.
 *
 * Not every platform implements every method. Providers declare what they support through
 * {@see supports()} / {@see capabilities()} and the {@see \MyMessenger\Enums\Capability} enum;
 * calling an unsupported method throws
 * {@see \MyMessenger\Exceptions\UnsupportedCapabilityException}.
 *
 * All `$options` arrays are merged into the provider payload as-is, so any platform specific
 * field documented by the provider can be passed without a library update.
 */
interface MessengerInterface {
	public function platform(): string;

	public function supports(string $capability): bool;

	/**
	 * Every capability the platform advertises, as a list of {@see \MyMessenger\Enums\Capability} values.
	 *
	 * @return list<string>
	 */
	public function capabilities(): array;

	/* ---------------------------------------------------------------- Text */

	/**
	 * @param array<string, mixed> $options
	 */
	public function sendMessage(string $recipientId, string $text, array $options = []): MessageResult;

	/**
	 * @param array<string, mixed> $options
	 */
	public function editMessage(string $recipientId, string $messageId, string $text, array $options = []): MessageResult;

	/**
	 * @param array<string, mixed> $options
	 */
	public function deleteMessage(string $recipientId, string $messageId, array $options = []): MessageResult;

	/**
	 * @param array<string, mixed> $options
	 */
	public function forwardMessage(string $recipientId, string $fromRecipientId, string $messageId, array $options = []): MessageResult;

	/**
	 * @param array<string, mixed> $options
	 */
	public function copyMessage(string $recipientId, string $fromRecipientId, string $messageId, array $options = []): MessageResult;

	/* --------------------------------------------------------------- Media */

	/**
	 * @param resource|string $photo
	 * @param array<string, mixed> $options
	 */
	public function sendPhoto(string $recipientId, mixed $photo, array $options = []): MessageResult;

	/**
	 * @param resource|string $document
	 * @param array<string, mixed> $options
	 */
	public function sendDocument(string $recipientId, mixed $document, array $options = []): MessageResult;

	/**
	 * @param resource|string $video
	 * @param array<string, mixed> $options
	 */
	public function sendVideo(string $recipientId, mixed $video, array $options = []): MessageResult;

	/**
	 * @param resource|string $audio
	 * @param array<string, mixed> $options
	 */
	public function sendAudio(string $recipientId, mixed $audio, array $options = []): MessageResult;

	/**
	 * @param resource|string $voice
	 * @param array<string, mixed> $options
	 */
	public function sendVoice(string $recipientId, mixed $voice, array $options = []): MessageResult;

	/**
	 * @param resource|string $videoNote
	 * @param array<string, mixed> $options
	 */
	public function sendVideoNote(string $recipientId, mixed $videoNote, array $options = []): MessageResult;

	/**
	 * @param resource|string $sticker
	 * @param array<string, mixed> $options
	 */
	public function sendSticker(string $recipientId, mixed $sticker, array $options = []): MessageResult;

	/**
	 * Sends 2–10 media items as an album.
	 *
	 * @param list<array<string, mixed>> $media
	 * @param array<string, mixed> $options
	 */
	public function sendMediaGroup(string $recipientId, array $media, array $options = []): MessageResult;

	/* ----------------------------------------------------------- Structured */

	/**
	 * @param array<string, mixed> $options
	 */
	public function sendLocation(string $recipientId, float $latitude, float $longitude, array $options = []): MessageResult;

	/**
	 * @param array<string, mixed> $options
	 */
	public function sendContact(string $recipientId, string $phoneNumber, string $firstName, array $options = []): MessageResult;

	/**
	 * @param list<string> $answers 2–12 answers for a regular poll, 2–10 for a quiz
	 * @param array<string, mixed> $options
	 */
	public function sendPoll(string $recipientId, string $question, array $answers, array $options = []): MessageResult;

	/**
	 * @param array<string, mixed> $options
	 */
	public function stopPoll(string $recipientId, string $messageId, array $options = []): MessageResult;

	/**
	 * @param array<string, mixed> $options
	 */
	public function sendDice(string $recipientId, array $options = []): MessageResult;

	/**
	 * @param array<string, mixed> $options
	 */
	public function sendReaction(string $recipientId, string $messageId, string $emoji, array $options = []): MessageResult;

	/**
	 * Sends a pre-approved template.
	 *
	 * `$template` is either the template name (WhatsApp) or the raw template payload (Facebook),
	 * depending on the platform.
	 *
	 * @param array<string, mixed> $options
	 */
	public function sendTemplate(string $recipientId, mixed $template, array $options = []): MessageResult;

	/**
	 * Sends an interactive message (buttons, lists, quick replies, flows…).
	 *
	 * @param array<string, mixed> $interactive
	 * @param array<string, mixed> $options
	 */
	public function sendInteractive(string $recipientId, array $interactive, array $options = []): MessageResult;

	/**
	 * @param list<array<string, mixed>> $quickReplies
	 * @param array<string, mixed> $options
	 */
	public function sendQuickReplies(string $recipientId, string $text, array $quickReplies, array $options = []): MessageResult;

	/**
	 * @param list<array<string, mixed>> $buttons
	 * @param array<string, mixed> $options
	 */
	public function sendButtonTemplate(string $recipientId, string $text, array $buttons, array $options = []): MessageResult;

	/**
	 * @param list<array<string, mixed>> $elements
	 * @param array<string, mixed> $options
	 */
	public function sendGenericTemplate(string $recipientId, array $elements, array $options = []): MessageResult;

	/* ------------------------------------------------------- Chat behaviour */

	/**
	 * @param array<string, mixed> $options
	 */
	public function sendChatAction(string $recipientId, string $action, array $options = []): MessageResult;

	/**
	 * WhatsApp / Facebook style read receipt.
	 *
	 * @param array<string, mixed> $options
	 */
	public function markAsRead(string $messageId, array $options = []): MessageResult;

	/**
	 * Facebook Messenger sender_action (typing_on, typing_off, mark_seen).
	 *
	 * @param array<string, mixed> $options
	 */
	public function sendSenderAction(string $recipientId, string $action, array $options = []): MessageResult;

	/* -------------------------------------------------------------- Account */

	public function getMe(): MessageResult;

	public function getAccountInfo(): MessageResult;

	/**
	 * @param array<string, mixed> $options
	 */
	public function getPhoneNumbers(array $options = []): MessageResult;

	/**
	 * @param array<string, mixed> $options
	 */
	public function getUserProfile(string $userId, array $options = []): MessageResult;

	/**
	 * @param array<string, mixed> $options
	 */
	public function getTemplates(array $options = []): MessageResult;

	/**
	 * @param array<string, mixed> $options
	 */
	public function setMyCommands(array $commands, array $options = []): MessageResult;

	/**
	 * @param array<string, mixed> $options
	 */
	public function getMyCommands(array $options = []): MessageResult;

	/**
	 * @param array<string, mixed> $options
	 */
	public function deleteMyCommands(array $options = []): MessageResult;

	/* -------------------------------------------------------------- Webhook */

	/**
	 * @param array<string, mixed> $options
	 */
	public function setWebhook(string $url, array $options = []): MessageResult;

	public function deleteWebhook(): MessageResult;

	public function getWebhookInfo(): MessageResult;

	/**
	 * @param array<string, mixed> $options
	 */
	public function getUpdates(array $options = []): MessageResult;

	/**
	 * @param array<string, mixed> $options
	 */
	public function answerCallbackQuery(string $callbackQueryId, array $options = []): MessageResult;

	/* ----------------------------------------------------------------- Chat */

	/**
	 * @param array<string, mixed> $options
	 */
	public function getChat(string $recipientId, array $options = []): MessageResult;

	/**
	 * @param array<string, mixed> $options
	 */
	public function getChatMember(string $recipientId, string $userId, array $options = []): MessageResult;

	/**
	 * @param array<string, mixed> $options
	 */
	public function getChatAdministrators(string $recipientId, array $options = []): MessageResult;

	/**
	 * @param array<string, mixed> $options
	 */
	public function getChatMemberCount(string $recipientId, array $options = []): MessageResult;

	/**
	 * @param array<string, mixed> $options
	 */
	public function setChatTitle(string $recipientId, string $title, array $options = []): MessageResult;

	/**
	 * @param array<string, mixed> $options
	 */
	public function banChatMember(string $recipientId, string $userId, array $options = []): MessageResult;

	/**
	 * @param array<string, mixed> $options
	 */
	public function unbanChatMember(string $recipientId, string $userId, array $options = []): MessageResult;

	/**
	 * @param array<string, mixed> $options
	 */
	public function leaveChat(string $recipientId, array $options = []): MessageResult;

	/**
	 * @param array<string, mixed> $options
	 */
	public function pinMessage(string $recipientId, string $messageId, array $options = []): MessageResult;

	/**
	 * @param array<string, mixed> $options
	 */
	public function unpinMessage(string $recipientId, array $options = []): MessageResult;

	/* ----------------------------------------------------------------- File */

	/**
	 * @param array<string, mixed> $options
	 */
	public function getFile(string $fileId, array $options = []): MessageResult;

	/**
	 * Uploads a local file and returns the platform media identifier.
	 *
	 * `$mediaType` is the platform specific classification: a MIME type for WhatsApp, and one of
	 * `image`, `file`, `video`, `audio` for Facebook.
	 *
	 * @param array<string, mixed> $options
	 */
	public function uploadMedia(string $filePath, string $mediaType, array $options = []): MessageResult;

	/**
	 * @param array<string, mixed> $options
	 */
	public function getMedia(string $mediaId, array $options = []): MessageResult;

	/**
	 * @param array<string, mixed> $options
	 */
	public function deleteMedia(string $mediaId, array $options = []): MessageResult;
}

<?php
declare(strict_types=1);
namespace MyMessenger\Contracts;

use MyMessenger\ValueObject\MessageResult;

interface MessengerInterface {
	public function platform(): string;

	public function supports(string $capability): bool;

	/**
	 * @param array<string, mixed> $options
	 */
	public function sendMessage(string $recipientId, string $text, array $options = []): MessageResult;

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
	 * @param array<string, mixed> $options
	 */
	public function sendLocation(string $recipientId, float $latitude, float $longitude, array $options = []): MessageResult;

	/**
	 * @param array<string, mixed> $options
	 */
	public function sendContact(string $recipientId, string $phoneNumber, string $firstName, array $options = []): MessageResult;

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
	public function sendChatAction(string $recipientId, string $action, array $options = []): MessageResult;

	public function getMe(): MessageResult;

	public function getAccountInfo(): MessageResult;

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
	public function pinMessage(string $recipientId, string $messageId, array $options = []): MessageResult;

	/**
	 * @param array<string, mixed> $options
	 */
	public function unpinMessage(string $recipientId, array $options = []): MessageResult;

	/**
	 * @param array<string, mixed> $options
	 */
	public function forwardMessage(string $recipientId, string $fromRecipientId, string $messageId, array $options = []): MessageResult;

	/**
	 * @param array<string, mixed> $options
	 */
	public function copyMessage(string $recipientId, string $fromRecipientId, string $messageId, array $options = []): MessageResult;
}

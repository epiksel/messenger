<?php
declare(strict_types=1);
namespace MyMessenger\Contracts;

use MyMessenger\ValueObject\ValidationResult;

/**
 * Platform-agnostic credential validation contract.
 *
 * Used to verify whether tokens and recipient values (chat_id, phone number, channel id, etc.)
 * actually function before executing the "Save" operation in the admin panel.
 * It is recommended to implement this interface when adding a new platform.
 */
interface ValidatesCredentialsInterface {
	/**
	 * Local format check only (does not make network requests).
	 */
	public static function isCredentialFormatValid(string $token): bool;

	/**
	 * Local format check only (does not make network requests).
	 */
	public static function isRecipientFormatValid(string $recipientId): bool;

	/**
	 * Verifies via an API call that the token is accepted by the platform.
	 */
	public function validateCredentials(): ValidationResult;

	/**
	 * Verifies that the recipient exists and that the bot can send messages to this recipient.
	 *
	 * @param array<string, mixed> $options E.g., ['send_test_message' => true, 'test_message' => '...']
	 */
	public function validateRecipient(string $recipientId, array $options = []): ValidationResult;
}

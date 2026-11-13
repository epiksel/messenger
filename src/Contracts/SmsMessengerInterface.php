<?php
declare(strict_types=1);
namespace MyMessenger\Contracts;

use MyMessenger\ValueObject\MessageResult;

/**
 * SMS specific contract (NetGSM and any future SMS gateway).
 *
 * Extends the generic {@see MessengerInterface} with the operations that only make sense for
 * SMS gateways: bulk sending, OTP, balance, delivery reports, inbox, sender headers, templates,
 * job cancellation and length measurement.
 */
interface SmsMessengerInterface extends MessengerInterface {
	/**
	 * Sends the same text to many recipients in a single job.
	 *
	 * @param list<string> $recipientIds
	 * @param array<string, mixed> $options
	 */
	public function sendBulk(array $recipientIds, string $text, array $options = []): MessageResult;

	/**
	 * Sends a one-time password over the dedicated OTP channel.
	 *
	 * @param array<string, mixed> $options
	 */
	public function sendOtp(string $recipientId, string $text, array $options = []): MessageResult;

	/**
	 * Sends messages built from pre-approved templates.
	 *
	 * @param list<array<string, mixed>> $templates
	 * @param array<string, mixed> $options
	 */
	public function sendTemplateMessages(array $templates, array $options = []): MessageResult;

	/**
	 * @param array<string, mixed> $options
	 */
	public function getBalance(array $options = []): MessageResult;

	/**
	 * @param array<string, mixed> $options
	 */
	public function getReport(array $options = []): MessageResult;

	/**
	 * @param array<string, mixed> $options
	 */
	public function getStats(array $options = []): MessageResult;

	/**
	 * @param array<string, mixed> $options
	 */
	public function readInbox(array $options = []): MessageResult;

	/**
	 * @param array<string, mixed> $options
	 */
	public function listSenders(array $options = []): MessageResult;

	/**
	 * @param array<string, mixed> $options
	 */
	public function listTemplates(array $options = []): MessageResult;

	/**
	 * Cancels a scheduled (not yet sent) job.
	 *
	 * @param array<string, mixed> $options
	 */
	public function cancelJob(string $jobId, array $options = []): MessageResult;

	/**
	 * Measures how many SMS parts a text occupies.
	 *
	 * @param array<string, mixed> $options
	 */
	public function measureLength(string $text, array $options = []): MessageResult;
}

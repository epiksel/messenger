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

final class Messenger extends AbstractMessenger implements MessengerInterface, ValidatesCredentialsInterface {
    private const string API_BASE = 'https://api.telegram.org';

    private ?string $cachedBotId = null;

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
                'The guzzlehttp/guzzle package was not found. Guzzle 8 must be installed for Telegram requests.'
            );
        }
    }

    /**
     * Telegram Bot API endpoint. Since the token is part of the URL, it is masked in error messages.
     */
    private function endpoint(string $method): string {
        return sprintf('%s/bot%s/%s', self::API_BASE, $this->token, $method);
    }

    /**
     * Limits the request timeout using config['timeout'] / config['connect_timeout'] (in seconds).
     * Prevents the page from freezing for 30 seconds if Telegram is unreachable during "Save" in the admin panel.
     *
     * @return array<string, mixed>
     */
    private function requestOptions(): array {
        $options = [];

        if (isset($this->config['timeout'])) {
            $options['timeout'] = (int) $this->config['timeout'];
        }

        if (isset($this->config['connect_timeout'])) {
            $options['connect_timeout'] = (int) $this->config['connect_timeout'];
        }

        return $options;
    }

    #[\Override]
    public function platform(): string {
        return 'telegram';
    }

    /**
     * @param array<string, mixed> $options
     */
    #[\Override]
    public function sendMessage(string $recipientId, string $text, array $options = []): MessageResult {
        $this->assertCapability(Capability::SEND_MESSAGE);

        $payload = [
            'chat_id' => $recipientId,
            'text' => $text,
            ...$options,
        ];

        return $this->execute('sendMessage', fn () => $this->invokeApi('sendMessage', $payload));
    }

    /**
     * @param resource|string $photo
     * @param array<string, mixed> $options
     */
    #[\Override]
    public function sendPhoto(string $recipientId, mixed $photo, array $options = []): MessageResult {
        $this->assertCapability(Capability::SEND_PHOTO);

        return $this->execute('sendPhoto', fn () => $this->invokeApi('sendPhoto', [
            'chat_id' => $recipientId,
            'photo' => $this->normalizeMedia($photo, 'photo'),
            ...$options,
        ]));
    }

    /**
     * @param resource|string $document
     * @param array<string, mixed> $options
     */
    #[\Override]
    public function sendDocument(string $recipientId, mixed $document, array $options = []): MessageResult {
        $this->assertCapability(Capability::SEND_DOCUMENT);

        return $this->execute('sendDocument', fn () => $this->invokeApi('sendDocument', [
            'chat_id' => $recipientId,
            'document' => $this->normalizeMedia($document, 'document'),
            ...$options,
        ]));
    }

    /**
     * @param resource|string $video
     * @param array<string, mixed> $options
     */
    #[\Override]
    public function sendVideo(string $recipientId, mixed $video, array $options = []): MessageResult {
        $this->assertCapability(Capability::SEND_VIDEO);

        return $this->execute('sendVideo', fn () => $this->invokeApi('sendVideo', [
            'chat_id' => $recipientId,
            'video' => $this->normalizeMedia($video, 'video'),
            ...$options,
        ]));
    }

    /**
     * @param resource|string $audio
     * @param array<string, mixed> $options
     */
    #[\Override]
    public function sendAudio(string $recipientId, mixed $audio, array $options = []): MessageResult {
        $this->assertCapability(Capability::SEND_AUDIO);

        return $this->execute('sendAudio', fn () => $this->invokeApi('sendAudio', [
            'chat_id' => $recipientId,
            'audio' => $this->normalizeMedia($audio, 'audio'),
            ...$options,
        ]));
    }

    /**
     * @param array<string, mixed> $options
     */
    #[\Override]
    public function sendLocation(string $recipientId, float $latitude, float $longitude, array $options = []): MessageResult {
        $this->assertCapability(Capability::SEND_LOCATION);

        return $this->execute('sendLocation', fn () => $this->invokeApi('sendLocation', [
            'chat_id' => $recipientId,
            'latitude' => $latitude,
            'longitude' => $longitude,
            ...$options,
        ]));
    }

    /**
     * @param array<string, mixed> $options
     */
    #[\Override]
    public function sendContact(string $recipientId, string $phoneNumber, string $firstName, array $options = []): MessageResult {
        $this->assertCapability(Capability::SEND_CONTACT);

        return $this->execute('sendContact', fn () => $this->invokeApi('sendContact', [
            'chat_id' => $recipientId,
            'phone_number' => $phoneNumber,
            'first_name' => $firstName,
            ...$options,
        ]));
    }

    /**
     * @param array<string, mixed> $options
     */
    #[\Override]
    public function editMessage(string $recipientId, string $messageId, string $text, array $options = []): MessageResult {
        $this->assertCapability(Capability::EDIT_MESSAGE);

        return $this->execute('editMessageText', fn () => $this->invokeApi('editMessageText', [
            'chat_id' => $recipientId,
            'message_id' => $messageId,
            'text' => $text,
            ...$options,
        ]));
    }

    /**
     * @param array<string, mixed> $options
     */
    #[\Override]
    public function deleteMessage(string $recipientId, string $messageId, array $options = []): MessageResult {
        $this->assertCapability(Capability::DELETE_MESSAGE);

        return $this->execute('deleteMessage', fn () => $this->invokeApi('deleteMessage', [
            'chat_id' => $recipientId,
            'message_id' => $messageId,
            ...$options,
        ]));
    }

    /**
     * @param array<string, mixed> $options
     */
    #[\Override]
    public function sendChatAction(string $recipientId, string $action, array $options = []): MessageResult {
        $this->assertCapability(Capability::SEND_CHAT_ACTION);

        return $this->execute('sendChatAction', fn () => $this->invokeApi('sendChatAction', [
            'chat_id' => $recipientId,
            'action' => $action,
            ...$options,
        ]));
    }

    #[\Override]
    public function getMe(): MessageResult {
        $this->assertCapability(Capability::GET_ME);

        return $this->execute('getMe', fn () => $this->invokeApi('getMe'));
    }

    #[\Override]
    public function getAccountInfo(): MessageResult {
        $this->assertCapability(Capability::GET_ACCOUNT_INFO);

        return $this->getMe();
    }

    /**
     * @param array<string, mixed> $options
     */
    #[\Override]
    public function setWebhook(string $url, array $options = []): MessageResult {
        $this->assertCapability(Capability::SET_WEBHOOK);

        return $this->execute('setWebhook', fn () => $this->invokeApi('setWebhook', [
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

        return $this->execute('deleteWebhook', fn () => $this->invokeApi('deleteWebhook', $options));
    }

    #[\Override]
    public function getWebhookInfo(): MessageResult {
        $this->assertCapability(Capability::GET_WEBHOOK_INFO);

        return $this->execute('getWebhookInfo', fn () => $this->invokeApi('getWebhookInfo'));
    }

    /**
     * @param array<string, mixed> $options
     */
    #[\Override]
    public function getUpdates(array $options = []): MessageResult {
        $this->assertCapability(Capability::GET_UPDATES);

        return $this->execute('getUpdates', fn () => $this->invokeApi('getUpdates', $options));
    }

    /**
     * @param array<string, mixed> $options
     */
    #[\Override]
    public function answerCallbackQuery(string $callbackQueryId, array $options = []): MessageResult {
        $this->assertCapability(Capability::ANSWER_CALLBACK_QUERY);

        return $this->execute('answerCallbackQuery', fn () => $this->invokeApi('answerCallbackQuery', [
            'callback_query_id' => $callbackQueryId,
            ...$options,
        ]));
    }

    /**
     * @param array<string, mixed> $options
     */
    #[\Override]
    public function getChat(string $recipientId, array $options = []): MessageResult {
        $this->assertCapability(Capability::GET_CHAT);

        return $this->execute('getChat', fn () => $this->invokeApi('getChat', [
            'chat_id' => $recipientId,
            ...$options,
        ]));
    }

    /**
     * @param array<string, mixed> $options
     */
    #[\Override]
    public function getChatMember(string $recipientId, string $userId, array $options = []): MessageResult {
        $this->assertCapability(Capability::GET_CHAT_MEMBER);

        return $this->execute('getChatMember', fn () => $this->invokeApi('getChatMember', [
            'chat_id' => $recipientId,
            'user_id' => $userId,
            ...$options,
        ]));
    }

    /**
     * @param array<string, mixed> $options
     */
    #[\Override]
    public function pinMessage(string $recipientId, string $messageId, array $options = []): MessageResult {
        $this->assertCapability(Capability::PIN_MESSAGE);

        return $this->execute('pinMessage', fn () => $this->invokeApi('pinChatMessage', [
            'chat_id' => $recipientId,
            'message_id' => $messageId,
            ...$options,
        ]));
    }

    /**
     * @param array<string, mixed> $options
     */
    #[\Override]
    public function unpinMessage(string $recipientId, array $options = []): MessageResult {
        $this->assertCapability(Capability::UNPIN_MESSAGE);

        return $this->execute('unpinMessage', fn () => $this->invokeApi('unpinChatMessage', [
            'chat_id' => $recipientId,
            ...$options,
        ]));
    }

    /**
     * @param array<string, mixed> $options
     */
    #[\Override]
    public function forwardMessage(string $recipientId, string $fromRecipientId, string $messageId, array $options = []): MessageResult {
        $this->assertCapability(Capability::FORWARD_MESSAGE);

        return $this->execute('forwardMessage', fn () => $this->invokeApi('forwardMessage', [
            'chat_id' => $recipientId,
            'from_chat_id' => $fromRecipientId,
            'message_id' => $messageId,
            ...$options,
        ]));
    }

    /**
     * @param array<string, mixed> $options
     */
    #[\Override]
    public function copyMessage(string $recipientId, string $fromRecipientId, string $messageId, array $options = []): MessageResult {
        $this->assertCapability(Capability::COPY_MESSAGE);

        return $this->execute('copyMessage', fn () => $this->invokeApi('copyMessage', [
            'chat_id' => $recipientId,
            'from_chat_id' => $fromRecipientId,
            'message_id' => $messageId,
            ...$options,
        ]));
    }

    /* ------------------------------------------------------------------
     |  Validation (prior to admin panel save operation)
     * ------------------------------------------------------------------ */

    /**
     * Telegram bot token format: "<bot_id>:<35-character secret key>"
     * E.g.: 123456789:AAH4x... (does not make a network request)
     */
    #[\Override]
    public static function isCredentialFormatValid(string $token): bool {
        return (bool) preg_match('/^\d{5,16}:[A-Za-z0-9_-]{30,64}$/', trim($token));
    }

    /**
     * Valid chat_id formats (does not make network requests):
     * - User / private chat   : 123456789
     * - Group                 : -123456789
     * - Supergroup / channel  : -1001234567890
     * - Public channel/group  : @channel_name
     */
    #[\Override]
    public static function isRecipientFormatValid(string $recipientId): bool {
        $recipientId = trim($recipientId);

        return (bool) preg_match('/^-?\d{1,20}$/', $recipientId)
            || (bool) preg_match('/^@[A-Za-z][A-Za-z0-9_]{3,31}$/', $recipientId);
    }

    /**
     * 1) Local format check  2) Queried to Telegram via getMe.
     * If successful, bot information is returned within the data: id, username, first_name, etc.
     */
    #[\Override]
    public function validateCredentials(): ValidationResult {
        if (trim($this->token) === '') {
            return ValidationResult::invalid('telegram', ValidationResult::EMPTY_VALUE, 'Bot token cannot be empty.');
        }

        if (!self::isCredentialFormatValid($this->token)) {
            return ValidationResult::invalid(
                'telegram',
                ValidationResult::INVALID_FORMAT,
                'Invalid bot token format. Expected format: 123456789:ABCdef... (value obtained from BotFather).',
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
     * Chat ID validation steps:
     * 1) Local format check
     * 2) getChat        -> does the chat exist, can the bot see this chat?
     * 3) getChatMember  -> (group/channel) is the bot a member, does it have permission to write messages?
     * 4) sendChatAction -> (private chat/group) can the bot actually write? (no message is delivered to the user)
     * 5) Optional: If 'send_test_message' => true, an actual test message is sent.
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

        // 2) Chat information
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

        // 3) If it's a group / channel, check the bot's permissions
        if ($type !== 'private') {
            $permission = $this->checkBotMembership($recipientId, $type, $data);
            if ($permission !== null) {
                return $permission;
            }
        }

        // 4) Silent write test (skipped in channels since chat action is meaningless there)
        if ($type !== 'channel') {
            $actionResult = $this->sendChatAction($recipientId, 'typing');
            if (!$actionResult->success) {
                return $this->classifyFailure($actionResult, 'chat', $data);
            }
        }

        // 5) Optional actual test message
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
            return null; // If getMe fails, this step is skipped; sendChatAction will catch it anyway
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

        // The first part of the token is already the bot ID; no network request needed.
        $prefix = strstr($this->token, ':', true);
        if ($prefix !== false && ctype_digit($prefix)) {
            return $this->cachedBotId = $prefix;
        }

        $me = $this->getMe();
        $id = $this->unwrapResult($me->raw)['id'] ?? null;

        return $this->cachedBotId = ($me->success && $id !== null ? (string) $id : null);
    }

    /**
     * Translates Telegram error codes into meaningful messages displayable in the admin panel.
     *
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
                'Chat not found. Check the Chat ID; for private chats, the user must first send /start to the bot.',
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
            $code === 429 => [ValidationResult::UNKNOWN_ERROR, 'Telegram temporarily blocked due to too many requests. Please try again later.'],
            $code === 0 => [ValidationResult::NETWORK_ERROR, 'Could not connect to the Telegram server: ' . $description],
            default => [ValidationResult::UNKNOWN_ERROR, 'Unexpected Telegram error: ' . $description],
        };

        return ValidationResult::invalid('telegram', $reason, $message, $data);
    }

    /**
     * Unwraps result objects when normalized objects sometimes return as ['result' => [...]].
     *
     * @param array<string, mixed> $raw
     * @return array<string, mixed>
     */
    private function unwrapResult(array $raw): array {
        if (isset($raw['result']) && is_array($raw['result'])) {
            return $raw['result'];
        }

        return $raw;
    }

    /**
     * Telegram Bot API call (via Guzzle 8, without SDK dependency).
     *
     * - Payloads containing files are sent as `multipart/form-data`.
     * - Other payloads are sent as JSON.
     * - If Telegram returns `{"ok":false,"error_code":401,...}`, a MessengerApiException is thrown.
     *
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function invokeApi(string $method, array $payload = []): array {
        if ($this->httpClient === null) {
            throw new MessengerException('HTTP client is unavailable (guzzlehttp/guzzle is not installed).');
        }

        $options = $this->requestOptions();
        $hasFile = false;

        foreach ($payload as $value) {
            if (is_array($value) && isset($value['__file'])) {
                $hasFile = true;
                break;
            }
        }

        if ($hasFile) {
            $multipart = [];
            foreach ($payload as $name => $value) {
                if (is_array($value) && isset($value['__file'])) {
                    $handle = @fopen((string) $value['__file'], 'rb');
                    if ($handle === false) {
                        throw new MessengerApiException(
                            apiCode: null,
                            message: sprintf('Could not open file for field "%s".', $name),
                        );
                    }

                    $multipart[] = [
                        'name' => (string) $name,
                        'contents' => $handle,
                        'filename' => (string) ($value['filename'] ?? basename((string) $value['__file'])),
                    ];
                    continue;
                }

                $multipart[] = [
                    'name' => (string) $name,
                    'contents' => $this->stringifyPayloadValue($value),
                ];
            }

            $options['multipart'] = $multipart;
        } elseif ($payload !== []) {
            $options['json'] = $payload;
        }

        try {
            $response = $this->httpClient->request('POST', $this->endpoint($method), $options);
        } catch (\GuzzleHttp\Exception\GuzzleException $exception) {
            // Connection/timeout errors are bubbled up for the retry mechanism.
            if ($exception instanceof \GuzzleHttp\Exception\ConnectException) {
                throw $exception;
            }

            $decoded = $this->decodeErrorBody($exception);
            if ($decoded !== null) {
                throw new MessengerApiException(
                    apiCode: $decoded['error_code'] ?? null,
                    message: (string) ($decoded['description'] ?? $exception->getMessage()),
                    code: (int) ($decoded['error_code'] ?? 0),
                    previous: $exception,
                );
            }

            throw $exception;
        }

        $body = (string) $response->getBody();
        $decoded = json_decode($body, true);

        if (!is_array($decoded)) {
            throw new MessengerApiException(
                apiCode: null,
                message: 'Could not decode Telegram response (invalid JSON).',
            );
        }

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
     * @return array<string, mixed>|null
     */
    private function decodeErrorBody(\GuzzleHttp\Exception\GuzzleException $exception): ?array {
        if (!method_exists($exception, 'getResponse') || $exception->getResponse() === null) {
            return null;
        }

        $body = (string) $exception->getResponse()->getBody();
        $decoded = json_decode($body, true);

        return is_array($decoded) && isset($decoded['error_code']) ? $decoded : null;
    }

    private function stringifyPayloadValue(mixed $value): string {
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        if (is_array($value)) {
            return (string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        return (string) $value;
    }

    /**
     * @param resource|string $media
     */
    private function normalizeMedia(mixed $media, string $field): mixed {
        if (is_resource($media)) {
            $meta = stream_get_meta_data($media);
            $path = $meta['uri'] ?? null;
            if (is_string($path) && $path !== '' && is_file($path)) {
                return $this->createInputFile($path);
            }

            throw new MessengerApiException(
                apiCode: null,
                message: sprintf('The resource value for the %s field does not have a valid file path.', $field),
            );
        }

        if (!is_string($media) || $media === '') {
            throw new MessengerApiException(
                apiCode: null,
                message: sprintf('The media value for the %s field must be a string or resource.', $field),
            );
        }

        if ($this->isRemoteUrl($media) || !is_file($media)) {
            return $media;
        }

        return $this->createInputFile($media);
    }

    /**
     * Local files are sent as multipart; invokeApi recognizes this array as a file field.
     *
     * @return array{__file:string, filename:string}
     */
    private function createInputFile(string $path): array {
        return [
            '__file' => $path,
            'filename' => basename($path),
        ];
    }

    private function isRemoteUrl(string $value): bool {
        return (bool) preg_match('/^https?:\/\//i', $value);
    }
}

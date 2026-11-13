# Telegram Provider

Sends messages, media and chat actions through the Telegram Bot API.

- **Class:** `MyMessenger\Provider\Telegram\Messenger`
- **Platform name:** `telegram`
- **API base:** `https://api.telegram.org` (Bot API, no SDK dependency)
- **Interfaces:** `MessengerInterface`, `ValidatesCredentialsInterface`
- **Requirement:** `guzzlehttp/guzzle` — the constructor throws `MessengerException` when it is missing

---

## 1. Creating a Messenger

### 1.1 Through the factory

```php
use MyMessenger\Core\MessengerFactory;

$messenger = MessengerFactory::make(
    'telegram',
    ['token' => 'YOUR_TELEGRAM_BOT_TOKEN'],   // 123456789:AAH4x...
    [
        'timeout'         => 10,
        'connect_timeout' => 5,
        'retry_attempts'  => 2,
        'retry_delay_ms'  => 200,
    ],
);
```

### 1.2 Directly, with your own HTTP client

```php
use GuzzleHttp\Client;
use MyMessenger\Credentials\CredentialBag;
use MyMessenger\Logging\NullLogger;
use MyMessenger\Provider\Telegram\Messenger;

$messenger = new Messenger(
    new CredentialBag(['token' => 'YOUR_TELEGRAM_BOT_TOKEN']),
    ['timeout' => 10],
    new NullLogger(),          // ?LoggerInterface
    new Client(),              // ?ClientInterface, stored as config['http_client']
);
```

`token` is required; a missing or empty value makes `CredentialBag::require()` throw
`MessengerAuthException`.

### 1.3 Configuration keys

| Key | Type | Default | Description |
|---|---|---|---|
| `timeout` | int (seconds) | Guzzle default | Total request timeout. |
| `connect_timeout` | int (seconds) | Guzzle default | Connection timeout. |
| `retry_attempts` | int | `0` | Extra attempts for transport (connect/timeout) failures only. |
| `retry_delay_ms` | int | `100` | Delay between retries in milliseconds. |
| `http_client` | `ClientInterface` | — | Pre-built Guzzle client (filled by the fourth constructor argument). |
| `http_client_config` | array | `[]` | Options used when the internal `Client` is created. |
| `delete_webhook_options` | array | `[]` | Body sent by `deleteWebhook()`, e.g. `['drop_pending_updates' => true]`. |

### 1.4 Capability checks

Every Bot API capability is enabled for this provider; the SMS capabilities are not:

```php
use MyMessenger\Enums\Capability;

$messenger->supports(Capability::PIN_MESSAGE);   // true
$messenger->supports('copy_message');            // true — plain strings are accepted
$messenger->supports(Capability::SEND_BULK);     // false — SMS only
```

---

## 2. Result Objects

Every operation returns `MyMessenger\ValueObject\MessageResult`; the validation methods return
`ValidationResult`.

```php
$result->success;    // bool
$result->messageId;  // ?string — Telegram's result.message_id
$result->platform;   // 'telegram'
$result->raw;        // array — raw Telegram JSON response
$result->error;      // ?MessageError { code, message }
$result->toArray();  // all fields as an array
```

Failures never escape as exceptions: `execute()` catches them and returns
`MessageResult::fail()`. The token is replaced with `***` inside error messages.

---

## 3. Sending Messages

Every method spreads `$options` over the payload, so all extra Bot API fields
(`parse_mode`, `disable_notification`, `reply_markup`, `reply_to_message_id`,
`protect_content`, `message_thread_id`, …) are forwarded unchanged.

### 3.1 `sendMessage()` — text

```php
$result = $messenger->sendMessage(
    'CHAT_ID',
    '<b>Hello</b> world!',
    [
        'parse_mode'           => 'HTML',   // 'HTML' | 'MarkdownV2' | 'Markdown'
        'disable_notification' => true,
        'protect_content'      => true,
        'reply_markup'         => ['inline_keyboard' => [[
            ['text' => 'Visit site', 'url' => 'https://example.com'],
            ['text' => 'OK', 'callback_data' => 'ok'],
        ]]],
    ],
);

if ($result->success) {
    echo 'Sent, message_id: ' . $result->messageId;
} else {
    echo $result->error->message . ' (code: ' . $result->error->code . ')';
}
```

Payload: `{ chat_id, text, ...$options }` → `sendMessage`.

### 3.2 `sendPhoto()` — photo

`$photo` accepts four forms:

| Value | Behaviour |
|---|---|
| Remote URL (`http://` / `https://`) | Sent as a string; Telegram downloads it. |
| Local file path | Uploaded as `multipart/form-data`. |
| `resource` (e.g. from `fopen()`) | Uploaded when the stream URI is a real file, otherwise `MessengerApiException`. |
| `file_id` string | Sent as a string when it is not an existing file. |

```php
// Local file (multipart)
$result = $messenger->sendPhoto('CHAT_ID', '/var/www/uploads/photo.jpg', [
    'caption'     => 'Photo caption',
    'parse_mode'  => 'HTML',
    'has_spoiler' => true,
]);

// Remote URL
$messenger->sendPhoto('CHAT_ID', 'https://example.com/photo.jpg');

// Stream resource
$handle = fopen('/var/www/uploads/photo.jpg', 'rb');
$messenger->sendPhoto('CHAT_ID', $handle);
fclose($handle);

// file_id of an already uploaded photo
$messenger->sendPhoto('CHAT_ID', 'AgACAgQAAxkBAAI...');
```

### 3.3 `sendDocument()` — document

```php
$messenger->sendDocument('CHAT_ID', '/var/www/uploads/invoice.pdf', [
    'caption'    => 'Your invoice',
    'parse_mode' => 'HTML',
]);

$messenger->sendDocument('CHAT_ID', 'https://example.com/report.pdf', [
    'disable_content_type_detection' => true,
]);
```

### 3.4 `sendVideo()` — video

```php
$messenger->sendVideo('CHAT_ID', '/var/www/uploads/clip.mp4', [
    'caption'            => 'Promo video',
    'duration'           => 12,
    'width'              => 1280,
    'height'             => 720,
    'supports_streaming' => true,
]);

$messenger->sendVideo('CHAT_ID', 'https://example.com/video.mp4');
```

### 3.5 `sendAudio()` — audio

```php
$messenger->sendAudio('CHAT_ID', '/var/www/uploads/song.mp3', [
    'caption'   => 'New single',
    'performer' => 'Artist',
    'title'     => 'Track title',
    'duration'  => 214,
]);

$messenger->sendAudio('CHAT_ID', 'https://example.com/song.mp3');
```

All media fields share the same normalisation rule: an unreadable local file produces a
`MessengerApiException`, which surfaces as `success = false`.

### 3.6 `sendVoice()` — voice message

```php
$messenger->sendVoice('CHAT_ID', '/var/www/uploads/note.ogg', [
    'caption'  => 'Voice note',
    'duration' => 8,
]);

$messenger->sendVoice('CHAT_ID', 'https://example.com/note.ogg');
```

### 3.7 `sendVideoNote()` — round video message

```php
$messenger->sendVideoNote('CHAT_ID', '/var/www/uploads/round.mp4', [
    'duration'    => 6,
    'length'      => 240,
    'thumbnail'   => '/var/www/uploads/thumb.jpg',
]);
```

### 3.8 `sendSticker()` — sticker

```php
$messenger->sendSticker('CHAT_ID', '/var/www/uploads/sticker.webp', [
    'emoji' => '😀',
]);

// or an existing file_id
$messenger->sendSticker('CHAT_ID', 'CAACAgIAAxkBAAI...');
```

### 3.9 `sendMediaGroup()` — album of 2–10 items

```php
$result = $messenger->sendMediaGroup('CHAT_ID', [
    ['type' => 'photo', 'media' => 'https://example.com/1.jpg', 'caption' => 'First'],
    ['type' => 'photo', 'media' => '/var/www/uploads/2.jpg'],
    ['type' => 'video', 'media' => 'https://example.com/3.mp4', 'supports_streaming' => true],
]);
```

Local files are uploaded as `attach://file0`, `attach://file1`, … which is what the Bot API
requires for albums. A group with fewer than 2 or more than 10 items raises
`MessengerApiException` before any request is made.

### 3.10 `sendLocation()` — location

```php
$messenger->sendLocation('CHAT_ID', 41.0082, 28.9784, [
    'horizontal_accuracy'    => 50.0,
    'live_period'            => 900,
    'heading'                => 90,
    'proximity_alert_radius' => 100,
]);
```

### 3.11 `sendContact()` — contact card

```php
$messenger->sendContact(
    'CHAT_ID',
    '+905551234567',
    'Ahmet',
    [
        'last_name'            => 'Yılmaz',
        'vcard'                => "BEGIN:VCARD\nVERSION:3.0\nFN:Ahmet Yılmaz\nEND:VCARD",
        'disable_notification' => true,
    ],
);
```

### 3.12 `sendPoll()` — poll or quiz

```php
$result = $messenger->sendPoll('CHAT_ID', 'Which day works for you?', [
    'Monday',
    'Tuesday',
    'Wednesday',
], [
    'is_anonymous'         => false,
    'allows_multiple_answers' => true,
]);

// Quiz — 2 to 10 answers, exactly one correct option
$messenger->sendPoll('CHAT_ID', '2 + 2 = ?', ['3', '4', '5'], [
    'type'              => 'quiz',
    'correct_option_id' => 1,
    'explanation'       => 'Basic arithmetic.',
]);
```

A regular poll accepts 2–12 answers, a quiz 2–10; anything else raises `MessengerApiException`
before any request is made.

### 3.13 `stopPoll()` — close a poll

```php
$result = $messenger->stopPoll('CHAT_ID', '123');

foreach ($result->raw['result']['options'] as $option) {
    echo $option['text'] . ': ' . $option['voter_count'];
}
```

### 3.14 `sendDice()` — animated dice

```php
$messenger->sendDice('CHAT_ID');
$messenger->sendDice('CHAT_ID', ['emoji' => '🎯']);
```

Valid emoji: 🎲 🎯 🏀 ⚽ 🎳 🎰.

### 3.15 `sendReaction()` — emoji reaction

```php
$messenger->sendReaction('CHAT_ID', '123', '👍');

// Remove every reaction from the message
$messenger->sendReaction('CHAT_ID', '123', '');
```

Calls `setMessageReaction` with `reaction: [{type: emoji, emoji: …}]`.

---

## 4. Message Management

### 4.1 `editMessage()` — edit a message

```php
$result = $messenger->editMessage(
    'CHAT_ID',
    '123',                    // message_id to edit
    'Updated text',
    ['parse_mode' => 'HTML', 'reply_markup' => ['inline_keyboard' => []]],
);
```

Calls `editMessageText` internally. The returned `messageId` is the edited message id.

### 4.2 `deleteMessage()` — delete a message

```php
$result = $messenger->deleteMessage('CHAT_ID', '123');
```

### 4.3 `forwardMessage()` — forward a message

```php
$result = $messenger->forwardMessage(
    'TARGET_CHAT_ID',
    'SOURCE_CHAT_ID',
    '123',
    ['disable_notification' => true, 'protect_content' => true],
);
```

### 4.4 `copyMessage()` — copy without the "forwarded" header

```php
$result = $messenger->copyMessage(
    'TARGET_CHAT_ID',
    'SOURCE_CHAT_ID',
    '123',
    ['caption' => 'Copy', 'parse_mode' => 'HTML'],
);
```

### 4.5 `pinMessage()` / `unpinMessage()`

```php
// pinChatMessage
$messenger->pinMessage('CHAT_ID', '123', ['disable_notification' => true]);

// unpinChatMessage — without message_id the current pinned message is unpinned
$messenger->unpinMessage('CHAT_ID');
$messenger->unpinMessage('CHAT_ID', ['message_id' => '123']);
```

### 4.6 `sendChatAction()` — "typing…" indicator

```php
$messenger->sendChatAction('CHAT_ID', 'typing');
```

Valid `action` values: `typing`, `upload_photo`, `record_video`, `upload_video`, `record_voice`,
`upload_voice`, `upload_document`, `choose_sticker`, `find_location`, `record_video_note`,
`upload_video_note`.

---

## 5. Bot and Chat Information

### 5.1 `getMe()` — bot information

```php
$me = $messenger->getMe();

if ($me->success) {
    echo $me->raw['result']['username'];   // @bot_username
}
```

### 5.2 `getAccountInfo()` — alias of `getMe()`

```php
$info = $messenger->getAccountInfo();
```

### 5.3 `getChat()` — chat information

```php
$chat = $messenger->getChat('@channel_name');
echo $chat->raw['result']['type'];     // private | group | supergroup | channel
```

### 5.4 `getChatMember()` — member information

```php
$member = $messenger->getChatMember('-1001234567890', '123456789');
echo $member->raw['result']['status']; // creator | administrator | member | restricted | left | kicked
```

### 5.5 `getChatAdministrators()` — administrator list

```php
$admins = $messenger->getChatAdministrators('-1001234567890');

foreach ($admins->raw['result'] as $admin) {
    echo $admin['user']['id'] . ' — ' . $admin['status'];
}
```

### 5.6 `getChatMemberCount()` — member count

```php
$count = $messenger->getChatMemberCount('-1001234567890');
echo $count->raw['result'];   // int
```

### 5.7 `setChatTitle()` — rename a group, supergroup or channel

```php
$messenger->setChatTitle('-1001234567890', 'Support — Tier 2');
```

### 5.8 `banChatMember()` / `unbanChatMember()` — restrict access

```php
$messenger->banChatMember('-1001234567890', '123456789', [
    'until_date'           => time() + 3600,   // temporary ban
    'revoke_messages'      => true,
]);

$messenger->unbanChatMember('-1001234567890', '123456789', [
    'only_if_banned' => true,
]);
```

### 5.9 `leaveChat()` — remove the bot from a chat

```php
$messenger->leaveChat('-1001234567890');
```

### 5.10 `setMyCommands()` / `getMyCommands()` / `deleteMyCommands()` — command menu

```php
$messenger->setMyCommands([
    ['command' => 'start', 'description' => 'Start the bot'],
    ['command' => 'help',  'description' => 'Show help'],
], ['scope' => ['type' => 'all_private_chats'], 'language_code' => 'en']);

$commands = $messenger->getMyCommands(['language_code' => 'en']);

$messenger->deleteMyCommands(['scope' => ['type' => 'all_group_chats']]);
```

### 5.11 `getFile()` / `fileUrl()` — download a file

```php
$file = $messenger->getFile('AgACAgQAAxkBAAI...');

$file->raw['result']['file_path'];   // e.g. photos/file_1.jpg
$file->raw['result']['file_size'];

$url = $messenger->fileUrl($file->raw['result']['file_path']);
// https://api.telegram.org/file/bot<TOKEN>/photos/file_1.jpg
```

`fileUrl()` builds the download URL locally and performs no request. Files are limited to 20 MB
for `getFile` and the URL stays valid for at least one hour.

---

## 6. Updates

### 6.1 `getUpdates()` — long polling

```php
$updates = $messenger->getUpdates([
    'offset'          => 123456789,   // last processed update_id + 1
    'limit'           => 100,
    'timeout'         => 30,
    'allowed_updates' => ['message', 'callback_query'],
]);

foreach ($updates->raw['result'] ?? [] as $update) {
    // $update['message']['text'] ...
}
```

### 6.2 `answerCallbackQuery()` — answer an inline button

```php
$messenger->answerCallbackQuery('CALLBACK_QUERY_ID', [
    'text'       => 'Processed ✅',
    'show_alert' => false,
    'url'        => 'https://example.com',
    'cache_time' => 0,
]);
```

---

## 7. Webhooks

### 7.1 `setWebhook()`

```php
$messenger->setWebhook('https://yourdomain.com/telegram/webhook', [
    'secret_token'         => 'MY_SECRET',
    'max_connections'      => 40,
    'allowed_updates'      => ['message', 'callback_query', 'edited_message'],
    'drop_pending_updates' => true,
    'certificate'          => '/path/to/cert.pem',
]);
```

### 7.2 `getWebhookInfo()`

```php
$info = $messenger->getWebhookInfo();
echo $info->raw['result']['url'];
echo $info->raw['result']['pending_update_count'];
```

### 7.3 `deleteWebhook()`

The body comes from `config['delete_webhook_options']`:

```php
$messenger = MessengerFactory::make('telegram', ['token' => '...'], [
    'delete_webhook_options' => ['drop_pending_updates' => true],
]);

$messenger->deleteWebhook();
```

---

## 8. Credential and Recipient Validation

### 8.1 `isCredentialFormatValid()` — local format check, no network request

```php
Messenger::isCredentialFormatValid('123456789:AAH4x...');   // true
```

Rule: `^\d{5,16}:[A-Za-z0-9_-]{30,64}$` (the BotFather token format).

### 8.2 `isRecipientFormatValid()` — local chat_id check, no network request

```php
Messenger::isRecipientFormatValid('123456789');         // user / private chat
Messenger::isRecipientFormatValid('-123456789');        // group
Messenger::isRecipientFormatValid('-1001234567890');    // supergroup / channel
Messenger::isRecipientFormatValid('@channel_name');     // public channel or group
```

### 8.3 `validateCredentials()` — token validation

Flow: empty check → local format check → `getMe()` → `is_bot` check.

```php
$validation = $messenger->validateCredentials();

if ($validation->valid) {
    echo $validation->message;                  // "Token is valid. Bot: @my_bot"
    echo $validation->data['id'];
    echo $validation->data['username'];
    echo $validation->data['first_name'];
    echo $validation->data['can_join_groups'];
} else {
    echo $validation->reason;    // empty | invalid_format | unauthorized | ...
    echo $validation->message;
}
```

### 8.4 `validateRecipient()` — chat_id validation

Flow:

1. Empty check → `ValidationResult::EMPTY_VALUE`
2. Local format check → `INVALID_FORMAT`
3. `getChat` → does the chat exist? (`NOT_FOUND`, `FORBIDDEN`, …)
4. For groups and channels, `getChatMember` → is the bot a member, may it post? (`NO_PERMISSION`)
5. For non-channels, `sendChatAction('typing')` as a silent write test
6. When `send_test_message => true`, an actual test message is sent

```php
$validation = $messenger->validateRecipient(
    '-1001234567890',
    [
        'send_test_message' => true,
        'test_message'      => '✅ Connection test succeeded',
    ],
);

if ($validation->valid) {
    echo $validation->data['type'];               // private | group | supergroup | channel
    echo $validation->data['title'];
    echo $validation->data['username'];
    echo $validation->data['bot_status'];         // set for groups and channels
    echo $validation->data['test_message_id'] ?? '';
} else {
    echo $validation->reason . ': ' . $validation->message;
    echo $validation->data['api_code'];
    echo $validation->data['api_message'];
}
```

Telegram error codes are mapped to meaningful `reason` values:

| Telegram | reason | Meaning |
|---|---|---|
| `401` / (`404` + token context) | `unauthorized` | Token invalid or revoked |
| `chat not found` | `not_found` | Chat does not exist; in private chats the user must send `/start` first |
| `blocked by the user` | `forbidden` | The user blocked the bot |
| `kicked` / `not a member` | `forbidden` | The bot was removed from the chat |
| `not enough rights` / `have no rights` | `no_permission` | Missing posting permission |
| `403` | `forbidden` | No access to the chat |
| `400` | `invalid_format` | Telegram rejected the request |
| `429` | `unknown_error` | Rate limited |
| `0` | `network_error` | Server unreachable |

---

## 9. Error Handling and Logging

- `MessengerApiException` is thrown inside the request layer when Telegram answers
  `{"ok":false,"error_code":…,"description":…}`; `execute()` converts it into
  `MessageResult::fail()`.
- `ConnectException` is retried `retry_attempts` times, waiting `retry_delay_ms` between attempts.
- Any other `GuzzleException` is wrapped as `MessengerTransportException` or
  `MessengerApiException`.
- `UnsupportedCapabilityException` is raised by the capability guard.
- When a `LoggerInterface` is supplied, `info` / `warning` / `error` events are written and all
  context values are sanitised before logging.

```php
use MyMessenger\Logging\LoggerInterface;

$logger = new class implements LoggerInterface {
    public function debug(string $message, array $context = []): void {}
    public function info(string $message, array $context = []): void { error_log($message); }
    public function warning(string $message, array $context = []): void { error_log($message); }
    public function error(string $message, array $context = []): void { error_log($message); }
};

$messenger = MessengerFactory::make('telegram', ['token' => '...'], [], $logger);
```

---

## 10. Capability Map

| Capability | Method | Telegram endpoint |
|---|---|---|
| `send_message` | `sendMessage()` | `sendMessage` |
| `edit_message` | `editMessage()` | `editMessageText` |
| `delete_message` | `deleteMessage()` | `deleteMessage` |
| `forward_message` | `forwardMessage()` | `forwardMessage` |
| `copy_message` | `copyMessage()` | `copyMessage` |
| `send_photo` | `sendPhoto()` | `sendPhoto` |
| `send_document` | `sendDocument()` | `sendDocument` |
| `send_video` | `sendVideo()` | `sendVideo` |
| `send_audio` | `sendAudio()` | `sendAudio` |
| `send_voice` | `sendVoice()` | `sendVoice` |
| `send_video_note` | `sendVideoNote()` | `sendVideoNote` |
| `send_sticker` | `sendSticker()` | `sendSticker` |
| `send_media_group` | `sendMediaGroup()` | `sendMediaGroup` |
| `send_location` | `sendLocation()` | `sendLocation` |
| `send_contact` | `sendContact()` | `sendContact` |
| `send_poll` | `sendPoll()` | `sendPoll` |
| `stop_poll` | `stopPoll()` | `stopPoll` |
| `send_dice` | `sendDice()` | `sendDice` |
| `send_reaction` | `sendReaction()` | `setMessageReaction` |
| `send_chat_action` | `sendChatAction()` | `sendChatAction` |
| `pin_message` | `pinMessage()` | `pinChatMessage` |
| `unpin_message` | `unpinMessage()` | `unpinChatMessage` |
| `get_me` | `getMe()` | `getMe` |
| `get_account_info` | `getAccountInfo()` | `getMe` |
| `set_my_commands` | `setMyCommands()` | `setMyCommands` |
| `get_my_commands` | `getMyCommands()` | `getMyCommands` |
| `delete_my_commands` | `deleteMyCommands()` | `deleteMyCommands` |
| `set_webhook` | `setWebhook()` | `setWebhook` |
| `delete_webhook` | `deleteWebhook()` | `deleteWebhook` |
| `get_webhook_info` | `getWebhookInfo()` | `getWebhookInfo` |
| `get_updates` | `getUpdates()` | `getUpdates` |
| `answer_callback_query` | `answerCallbackQuery()` | `answerCallbackQuery` |
| `get_chat` | `getChat()` | `getChat` |
| `get_chat_member` | `getChatMember()` | `getChatMember` |
| `get_chat_administrators` | `getChatAdministrators()` | `getChatAdministrators` |
| `get_chat_member_count` | `getChatMemberCount()` | `getChatMemberCount` |
| `set_chat_title` | `setChatTitle()` | `setChatTitle` |
| `ban_chat_member` | `banChatMember()` | `banChatMember` |
| `unban_chat_member` | `unbanChatMember()` | `unbanChatMember` |
| `leave_chat` | `leaveChat()` | `leaveChat` |
| `get_file` | `getFile()` | `getFile` |

---

## 11. Implementation Notes

- Endpoint shape: `https://api.telegram.org/bot<TOKEN>/<method>`. Because the token is part of the
  URL, it is masked in error messages.
- Payloads containing files are sent as `multipart/form-data`; everything else as JSON.
- In multipart requests booleans become `1`/`0` and arrays are JSON-encoded.
- The bot id is read from the part of the token before `:` and cached, so no extra request is made.
- A response that cannot be decoded raises `MessengerApiException`
  ("Could not decode Telegram response (invalid JSON).").

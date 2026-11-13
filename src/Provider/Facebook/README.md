# Facebook Messenger Provider

Sends messages, templates, attachments and webhook subscriptions through the Facebook Messenger
Platform (Graph API).

- **Class:** `MyMessenger\Provider\Facebook\Messenger`
- **Platform name:** `facebook` (aliases: `fb`, `messenger`, `facebook_messenger`)
- **Interfaces:** `MessengerInterface`, `ValidatesCredentialsInterface`
- **API base:** `https://graph.facebook.com/v27.0`
- **Docs:** <https://developers.facebook.com/docs/messenger-platform>
- **Requirement:** `guzzlehttp/guzzle` — the constructor throws `MessengerException` when it is missing

---

## 1. Creating a Messenger

### 1.1 Through the factory

```php
use MyMessenger\Core\MessengerFactory;

$messenger = MessengerFactory::make(
    'facebook',
    [
        'token'   => 'EAAG...',   // Page access token
        'page_id' => '1234567890', // optional
    ],
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
use MyMessenger\Provider\Facebook\Messenger;

$messenger = new Messenger(
    new CredentialBag(['token' => 'EAAG...', 'page_id' => '1234567890']),
    ['timeout' => 10],
    null,               // ?LoggerInterface
    new Client(),       // ?ClientInterface
);
```

### 1.3 Credentials

| Key | Required | Description |
|---|---|---|
| `token` | yes | Page access token. |
| `page_id` | no | When set, `/{page_id}/messages` is used instead of `/me/messages`. Required by the webhook methods. |

### 1.4 Configuration keys

| Key | Type | Default | Description |
|---|---|---|---|
| `timeout` | int (seconds) | Guzzle default | Total request timeout. |
| `connect_timeout` | int (seconds) | Guzzle default | Connection timeout. |
| `retry_attempts` | int | `0` | Extra attempts for transport (connect/timeout) failures only. |
| `retry_delay_ms` | int | `100` | Delay between retries in milliseconds. |
| `http_client` | `ClientInterface` | — | Pre-built Guzzle client (fourth constructor argument). |
| `http_client_config` | array | `[]` | Options used when the internal `Client` is created. |
| `api_version` | string | `v27.0` | Graph API version. |
| `graph_base` | string | `https://graph.facebook.com` | Graph API host. |
| `page_id` | string | — | Alternative to the credential. |

### 1.5 Capability checks

```php
use MyMessenger\Enums\Capability;

$messenger->supports(Capability::SEND_GENERIC_TEMPLATE);  // true
$messenger->supports('send_sender_action');               // true — plain strings are accepted
$messenger->supports(Capability::EDIT_MESSAGE);           // false — Send API cannot edit messages
```

---

## 2. Result Objects

```php
$result = $messenger->sendMessage('USER_PSID', 'Hello');

$result->success;    // bool
$result->messageId;  // ?string — message_id from the response
$result->platform;   // 'facebook'
$result->raw;        // array — raw Graph API JSON response
$result->error;      // ?MessageError { code, message }
$result->toArray();  // all fields as an array
```

Failures never escape as exceptions: `execute()` catches them and returns `MessageResult::fail()`.
The token is replaced with `***` inside error messages.

---

## 3. Sending Messages

Every method spreads `$options` over the payload, so additional Messenger Platform fields
(`tag`, `notification_type`, `persona_id`, `metadata`, `messaging_type` …) are forwarded unchanged.
Default `messaging_type` is `RESPONSE`; use `MESSAGE_TAG` together with `tag` to send outside the
24-hour window.

### 3.1 `sendMessage()` — text

```php
$result = $messenger->sendMessage(
    'USER_PSID',
    'Hello world!',
    [
        'messaging_type'    => 'RESPONSE',   // or 'MESSAGE_TAG' together with 'tag'
        'notification_type' => 'REGULAR',    // REGULAR | SILENT_PUSH | NO_PUSH
    ],
);

if ($result->success) {
    echo 'Sent: ' . $result->messageId;
} else {
    echo $result->error->message . ' (code: ' . $result->error->code . ')';
}
```

Payload:

```json
{
  "messaging_type": "RESPONSE",
  "recipient": { "id": "USER_PSID" },
  "message": { "text": "Hello world!" }
}
```

### 3.2 `sendPhoto()`, `sendVideo()`, `sendAudio()`, `sendDocument()` — attachments

```php
$messenger->sendPhoto('USER_PSID', 'https://example.com/image.jpg');
$messenger->sendVideo('USER_PSID', 'https://example.com/video.mp4');
$messenger->sendAudio('USER_PSID', 'https://example.com/audio.mp3');
$messenger->sendDocument('USER_PSID', 'https://example.com/document.pdf');
```

Attachment types: `image`, `video`, `audio`, `file`.

Payload:

```json
{
  "messaging_type": "RESPONSE",
  "recipient": { "id": "USER_PSID" },
  "message": {
    "attachment": {
      "type": "image",
      "payload": { "url": "https://example.com/image.jpg", "is_reusable": true }
    }
  }
}
```

A local path or resource is uploaded first through `POST /{page_id}/message_attachments` and the
resulting `attachment_id` is used instead of `url`:

```php
$messenger->sendPhoto('USER_PSID', '/var/media/photo.jpg');
```

To reuse an already uploaded attachment, upload once and keep the id:

```php
$upload = $messenger->uploadMedia('/var/media/photo.jpg', 'image');
$attachmentId = $upload->messageId;
```

### 3.3 `sendLocation()` — location attachment

```php
$messenger->sendLocation('USER_PSID', 41.0082, 28.9784);
```

Payload:

```json
{
  "messaging_type": "RESPONSE",
  "recipient": { "id": "USER_PSID" },
  "message": {
    "attachment": {
      "type": "location",
      "payload": { "coordinates": { "lat": 41.0082, "lon": 28.9784 } }
    }
  }
}
```

### 3.4 `sendContact()` — contact card as a generic template

The Send API has no dedicated contact attachment, so the contact is rendered as a one-element
generic template with a `phone_number` button:

```php
$messenger->sendContact('USER_PSID', '+905551112233', 'Ahmet Yılmaz');
```

### 3.5 `sendReaction()` — emoji reaction

```php
$messenger->sendReaction('USER_PSID', 'mid.$cAA...', '👍');
```

Sends `{"payload": {"action": "react", "reaction": "👍", "message_id": "mid.$cAA..."}}`.

---

## 4. Templates and Quick Replies

These are provider-specific additions on top of `MessengerInterface`, each with its own capability.

### 4.1 `sendQuickReplies()` — quick reply buttons (max 13)

```php
$quickReplies = [
    ['content_type' => 'text', 'title' => 'Yes', 'payload' => 'USER_CLICKED_YES', 'image_url' => 'https://example.com/yes.png'],
    ['content_type' => 'text', 'title' => 'No', 'payload' => 'USER_CLICKED_NO'],
    ['content_type' => 'user_phone_number'],
    ['content_type' => 'user_email'],
];

$result = $messenger->sendQuickReplies('USER_PSID', 'Did you like it?', $quickReplies, [
    'messaging_type' => 'RESPONSE',
]);
```

Payload: `message.text` plus `message.quick_replies`. More than 13 entries raises
`MessengerApiException` before any request is made.

### 4.2 `sendButtonTemplate()` — button template (max 3 buttons)

```php
$buttons = [
    ['type' => 'postback', 'title' => 'Click me', 'payload' => 'BUTTON_CLICKED'],
    ['type' => 'web_url', 'title' => 'Visit site', 'url' => 'https://example.com', 'webview_height_ratio' => 'full'],
    ['type' => 'phone_number', 'title' => 'Call', 'payload' => '+905551234567'],
];

$result = $messenger->sendButtonTemplate('USER_PSID', 'Take a look at this button!', $buttons);
```

Payload: `attachment.type` = `template`, `payload.template_type` = `button`, `payload.text`,
`payload.buttons`.

### 4.3 `sendGenericTemplate()` — card carousel (max 10 elements)

```php
$elements = [
    [
        'title'          => 'Product 1',
        'image_url'      => 'https://example.com/image1.jpg',
        'subtitle'       => 'Description 1',
        'default_action' => ['type' => 'web_url', 'url' => 'https://example.com/item1'],
        'buttons'        => [
            ['type' => 'postback', 'title' => 'Select', 'payload' => 'SELECT_ITEM_1'],
        ],
    ],
    [
        'title'     => 'Product 2',
        'image_url' => 'https://example.com/image2.jpg',
        'subtitle'  => 'Description 2',
        'buttons'   => [
            ['type' => 'web_url', 'title' => 'Details', 'url' => 'https://example.com/item2'],
        ],
    ],
];

$result = $messenger->sendGenericTemplate('USER_PSID', $elements);
```

Payload: `attachment.type` = `template`, `payload.template_type` = `generic`, `payload.elements`.

### 4.4 `sendTemplate()` — any other template payload

```php
$messenger->sendTemplate('USER_PSID', [
    'template_type' => 'media',
    'elements' => [[
        'media_type' => 'image',
        'url'        => 'https://example.com/image.jpg',
        'buttons'    => [['type' => 'web_url', 'title' => 'Open', 'url' => 'https://example.com']],
    ]],
]);
```

The array is forwarded verbatim as `attachment.payload`, so media, receipt, coupon and airline
templates work without a library update. A non-array payload raises `MessengerApiException`.

### 4.5 `sendInteractive()` — raw message passthrough

```php
$messenger->sendInteractive('USER_PSID', [
    'text' => 'Choose an option',
    'quick_replies' => [
        ['content_type' => 'text', 'title' => 'Support', 'payload' => 'SUPPORT'],
    ],
]);
```

Useful for Handover Protocol payloads and any message object that has no dedicated method.

---

## 5. Chat Behaviour

### 5.1 `sendSenderAction()`

```php
$messenger->sendSenderAction('USER_PSID', 'typing_on');
$messenger->sendSenderAction('USER_PSID', 'typing_off');
$messenger->sendSenderAction('USER_PSID', 'mark_seen');
```

Payload: `{"recipient": {"id": "USER_PSID"}, "sender_action": "typing_on"}`.

### 5.2 `markAsRead()`

The Send API has no message-id based read endpoint, so the recipient id is required through
`$options['recipient_id']`:

```php
$messenger->markAsRead('mid.$cAA...', ['recipient_id' => 'USER_PSID']);
```

Internally this calls `sendSenderAction($recipientId, 'mark_seen')`.

---

## 6. Account Information

### 6.1 `getAccountInfo()` — page information

```php
$info = $messenger->getAccountInfo(['fields' => 'id,name,category']);

if ($info->success) {
    echo $info->raw['name'];
}
```

Requests `GET /me?fields=…`; the default field list is
`id,name,username,category,link,fan_count`.

### 6.2 `getUserProfile()` — user profile

```php
$profile = $messenger->getUserProfile('USER_PSID', [
    'fields' => 'first_name,last_name,profile_pic,locale,timezone,gender',
]);

echo $profile->raw['first_name'];
```

---

## 7. Webhooks

All three methods operate on `/{page_id}/subscribed_apps` and require the `page_id` credential;
without it they raise `MessengerApiException`.

### 7.1 `setWebhook()`

```php
$result = $messenger->setWebhook('https://yourdomain.com/facebook/webhook', [
    'verify_token' => 'YOUR_VERIFY_TOKEN',
    'fields'       => ['messages', 'messaging_postbacks', 'message_reads'],
]);
```

Payload:

```json
{
  "object": "page",
  "callback_url": "https://yourdomain.com/facebook/webhook",
  "verify_token": "YOUR_VERIFY_TOKEN",
  "fields": ["messages", "messaging_postbacks", "message_reads"]
}
```

When `fields` is omitted it defaults to
`['messages', 'messaging_postbacks', 'message_reads', 'message_reactions']`; when `verify_token` is
omitted an empty string is sent.

### 7.2 `getWebhookInfo()`

```php
$info = $messenger->getWebhookInfo();

foreach ($info->raw['data'] as $app) {
    $app['id'];
    $app['subscribed_fields'];
}
```

### 7.3 `deleteWebhook()`

```php
$result = $messenger->deleteWebhook();
```

Calls `DELETE /{page_id}/subscribed_apps` and takes no arguments.

---

## 8. Media Management

### 8.1 `uploadMedia()`

```php
$result = $messenger->uploadMedia('/var/media/photo.jpg', 'image');

$result->messageId;   // attachment_id, reusable in attachment.payload
```

The second argument is the Messenger attachment type: `image`, `file`, `video` or `audio`. The
upload is sent as multipart (`message` + `filedata`) with `is_reusable: true`. A missing or
unreadable file raises `MessengerApiException`.

---

## 9. Credential and Recipient Validation

### 9.1 `validateCredentials()`

```php
$validation = $messenger->validateCredentials();

if ($validation->valid) {
    echo $validation->message;   // "Page access token is valid. Page: My Page"
    echo $validation->data['page_id'];
    echo $validation->data['page_name'];
    echo $validation->data['category'];
    echo $validation->data['fan_count'];
} else {
    echo $validation->reason;    // empty | unauthorized | not_found | network_error | …
    echo $validation->message;
}
```

Flow: empty token check → `getAccountInfo()` → `valid` on success, otherwise a reason mapped from
the Graph error code.

| Graph code / message | reason |
|---|---|
| `190`, "access token" | `unauthorized` |
| `10`, `200` | `no_permission` — missing `pages_messaging` / `pages_manage_metadata` |
| `100` (recipient context) | `not_found` — the user must message the page first |
| `100` (other) | `invalid_format` |
| `551` | `not_found` — user deactivated or blocked the page |
| `4`, `32`, `613` | `unknown_error` — rate limited |
| `0` | `network_error` |

### 9.2 `validateRecipient()` — PSID format check

```php
$validation = $messenger->validateRecipient('1234567890123456');

if ($validation->valid) {
    echo $validation->data['recipient_id'];
} else {
    echo $validation->reason . ': ' . $validation->message;
}
```

Rule: digits only, 5–25 characters (`/^\d{5,25}$/`). An empty value yields `EMPTY_VALUE`, a
non-matching value `INVALID_FORMAT`.

Facebook only returns a profile for users who have already interacted with the page, so a failed
lookup is not fatal unless `require_profile` is set:

```php
$validation = $messenger->validateRecipient('1234567890123456', [
    'require_profile'   => true,          // look the PSID up through getUserProfile()
    'send_test_message' => true,
    'test_message'      => '✅ Facebook Messenger connection successfully validated.',
]);

$validation->data['first_name'];        // set when require_profile succeeded
$validation->data['profile_pic'];
$validation->data['test_message_id'];   // set when the message was accepted
```

### 9.3 Static format helpers

```php
Messenger::isCredentialFormatValid('EAAG'.str_repeat('x', 30)); // true — non-empty, ≥ 20 chars
Messenger::isRecipientFormatValid('1234567890123456');          // true
Messenger::isRecipientFormatValid('user-1');                    // false
```

---

## 10. Error Handling and Logging

- A Graph error object `{error: {message, type, code, error_subcode, fbtrace_id}}` is converted into
  `MessengerApiException`; `execute()` turns it into
  `MessageResult::fail($error['code'], $error['message'])`.
- `ConnectException` is retried `retry_attempts` times, waiting `retry_delay_ms` between attempts.
- A response that cannot be decoded raises `MessengerApiException`
  ("Could not decode the facebook response (invalid JSON).").
- When a `LoggerInterface` is supplied, `info` / `warning` / `error` events are written and all
  context values are sanitised before logging.

```php
$messenger = MessengerFactory::make('facebook', ['token' => '...'], [], $logger);
```

---

## 11. Capability Map

| Capability | Method | Endpoint |
|---|---|---|
| `send_message` | `sendMessage()` | `POST /{page_id}/messages` |
| `send_photo` | `sendPhoto()` | `POST /{page_id}/messages` |
| `send_document` | `sendDocument()` | `POST /{page_id}/messages` |
| `send_video` | `sendVideo()` | `POST /{page_id}/messages` |
| `send_audio` | `sendAudio()` | `POST /{page_id}/messages` |
| `send_location` | `sendLocation()` | `POST /{page_id}/messages` |
| `send_contact` | `sendContact()` | `POST /{page_id}/messages` |
| `send_quick_replies` | `sendQuickReplies()` | `POST /{page_id}/messages` |
| `send_button_template` | `sendButtonTemplate()` | `POST /{page_id}/messages` |
| `send_generic_template` | `sendGenericTemplate()` | `POST /{page_id}/messages` |
| `send_template` | `sendTemplate()` | `POST /{page_id}/messages` |
| `send_interactive` | `sendInteractive()` | `POST /{page_id}/messages` |
| `send_reaction` | `sendReaction()` | `POST /{page_id}/messages` |
| `send_sender_action` | `sendSenderAction()` | `POST /{page_id}/messages` |
| `mark_as_read` | `markAsRead()` | `POST /{page_id}/messages` |
| `get_account_info` | `getAccountInfo()` | `GET /me` |
| `get_user_profile` | `getUserProfile()` | `GET /{user_id}` |
| `set_webhook` | `setWebhook()` | `POST /{page_id}/subscribed_apps` |
| `get_webhook_info` | `getWebhookInfo()` | `GET /{page_id}/subscribed_apps` |
| `delete_webhook` | `deleteWebhook()` | `DELETE /{page_id}/subscribed_apps` |
| `upload_media` | `uploadMedia()` | `POST /{page_id}/message_attachments` |

---

## 12. Implementation Notes

- Authentication uses `Authorization: Bearer <token>`; message payloads are JSON, attachment uploads
  are `multipart/form-data`.
- `messageId` is read from the `message_id` field of the response (`result.message_id` is also
  accepted).
- Without a `page_id` credential, messages go to `/me/messages`; webhook and attachment uploads fall
  back to `/me/...` where the platform allows it.
- `sendContact()` produces a generic template because the Send API has no contact attachment type.

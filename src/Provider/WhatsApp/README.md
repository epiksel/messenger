# WhatsApp Provider

Sends messages through the WhatsApp Business Cloud API (Graph API).

- **Class:** `MyMessenger\Provider\WhatsApp\Messenger`
- **Platform name:** `whatsapp` (aliases: `wa`, `whatsapp_cloud`)
- **Interfaces:** `MessengerInterface`, `ValidatesCredentialsInterface`
- **API base:** `https://graph.facebook.com/v27.0`
- **Docs:** <https://developers.facebook.com/docs/whatsapp/cloud-api>
- **Requirement:** `guzzlehttp/guzzle` — the constructor throws `MessengerException` when it is missing

---

## 1. Creating a Messenger

### 1.1 Through the factory

```php
use MyMessenger\Core\MessengerFactory;

$messenger = MessengerFactory::make(
    'whatsapp',
    [
        'token'               => 'EAAG...',   // system user / app access token
        'phone_number_id'     => '1234567890',
        'business_account_id' => '9876543210', // optional
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
use MyMessenger\Provider\WhatsApp\Messenger;

$messenger = new Messenger(
    new CredentialBag([
        'token'           => 'EAAG...',
        'phone_number_id' => '1234567890',
    ]),
    ['timeout' => 10],
    null,               // ?LoggerInterface
    new Client(),       // ?ClientInterface
);
```

### 1.3 Credentials

| Key | Required | Description |
|---|---|---|
| `token` | yes | Permanent system-user or app access token. |
| `phone_number_id` | yes | Cloud API **phone number id**, not the phone number itself. |
| `business_account_id` | no | WhatsApp Business Account id; needed by `getPhoneNumbers()` and `getTemplates()`. |

A missing `phone_number_id` throws `MessengerException` in the constructor.

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
| `phone_number_id` | string | — | Alternative to the credential. |
| `business_account_id` | string | — | Alternative to the credential. |

### 1.5 Capability checks

```php
use MyMessenger\Enums\Capability;

$messenger->supports(Capability::SEND_TEMPLATE);   // true
$messenger->supports('send_sticker');              // true — plain strings are accepted
$messenger->supports(Capability::EDIT_MESSAGE);    // false — Cloud API cannot edit messages
```

---

## 2. Result Objects

```php
$result = $messenger->sendMessage('905551112233', 'Hello');

$result->success;    // bool
$result->messageId;  // ?string — wamid… (extracted from raw.messages[0].id)
$result->platform;   // 'whatsapp'
$result->raw;        // array — raw Graph API JSON response
$result->error;      // ?MessageError { code, message }
$result->toArray();  // all fields as an array
```

Failures never escape as exceptions: `execute()` catches them and returns `MessageResult::fail()`.
The token is replaced with `***` inside error messages.

---

## 3. Sending Messages

Every method spreads `$options` over the payload, so additional Graph API fields
(`recipient_type`, `reply_to_message_id`, `context`, `biz_opaque_callback_data`, …) are forwarded
unchanged. Recipients are normalised to digits only, so `+90 555 111 22 33` becomes
`905551112233`.

### 3.1 `sendMessage()` — text

```php
$result = $messenger->sendMessage(
    '905551112233',
    'Hello world!',
    [
        'preview_url'              => true,
        'biz_opaque_callback_data' => 'order-1234',
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
  "messaging_product": "whatsapp",
  "recipient_type": "individual",
  "to": "905551112233",
  "type": "text",
  "text": { "body": "Hello world!", "preview_url": true }
}
```

Extra text fields can be nested under `text`:

```php
$messenger->sendMessage('905551112233', 'Hello', ['text' => ['preview_url' => false]]);
```

### 3.2 Media — `sendPhoto()`, `sendDocument()`, `sendVideo()`, `sendAudio()`, `sendSticker()`

All five share one implementation: pass a public URL or a local path/resource, plus optional
`caption` (not supported for stickers/audio by WhatsApp), `filename` (documents) and `mime_type`.

```php
// Public URL — WhatsApp downloads it
$messenger->sendPhoto('905551112233', 'https://example.com/image.jpg', [
    'caption' => 'Product image',
]);

// Local file — uploaded first through POST /{phone_number_id}/media
$messenger->sendDocument('905551112233', '/var/exports/invoice.pdf', [
    'caption'  => 'Your invoice',
    'filename' => 'invoice.pdf',
]);

$messenger->sendVideo('905551112233', 'https://example.com/video.mp4', ['caption' => 'Promo']);
$messenger->sendAudio('905551112233', 'https://example.com/voice.ogg');
$messenger->sendSticker('905551112233', 'https://example.com/sticker.webp');
```

Local files require `mime_type` to be meaningful (defaults to `application/octet-stream`):

```php
$messenger->sendPhoto('905551112233', '/var/media/photo.jpg', ['mime_type' => 'image/jpeg']);
```

To reuse an already uploaded asset, upload it once and pass the media id:

```php
$upload = $messenger->uploadMedia('/var/media/photo.jpg', 'image/jpeg');
$mediaId = $upload->messageId;
```

### 3.3 `sendLocation()` — location

```php
$messenger->sendLocation('905551112233', 41.0082, 28.9784, [
    'location' => [
        'name'    => 'Head Office',
        'address' => 'Istanbul, Türkiye',
    ],
]);
```

### 3.4 `sendContact()` — contact card

```php
$messenger->sendContact('905551112233', '+905551112244', 'Ahmet Yılmaz', [
    'name' => [
        'first_name' => 'Ahmet',
        'last_name'  => 'Yılmaz',
    ],
    'emails'    => [['email' => 'ahmet@example.com', 'type' => 'WORK']],
    'addresses' => [[
        'street'      => 'Example Street 1',
        'city'        => 'Istanbul',
        'country'     => 'TR',
        'country_code' => 'TR',
    ]],
]);
```

`$firstName` becomes `name.formatted_name`; `name` in `$options` is merged over it. `phones`
defaults to a single `CELL` entry built from `$phoneNumber`.

### 3.5 `sendTemplate()` — pre-approved template

```php
$result = $messenger->sendTemplate('905551112233', 'order_update', [
    'language'   => ['code' => 'tr'],
    'components' => [[
        'type'       => 'body',
        'parameters' => [['type' => 'text', 'text' => '#4711']],
    ]],
]);
```

`$templateName` must be the approved template name as a string. `language_code` is accepted as a
shortcut for `language.code`; the default language is `en_US`.

Templates are mandatory outside the 24-hour customer service window.

### 3.6 `sendInteractive()` — buttons, lists, flows

```php
$messenger->sendInteractive('905551112233', [
    'type' => 'button',
    'body' => ['text' => 'Confirm your order?'],
    'action' => [
        'buttons' => [
            ['type' => 'reply', 'reply' => ['id' => 'yes', 'title' => 'Yes']],
            ['type' => 'reply', 'reply' => ['id' => 'no', 'title' => 'No']],
        ],
    ],
]);
```

The array is forwarded verbatim as the `interactive` object, so lists, products and flows work the
same way.

### 3.7 `sendReaction()` — emoji reaction

```php
$messenger->sendReaction('905551112233', 'wamid.HBgM...', '👍');

// Remove an existing reaction
$messenger->sendReaction('905551112233', 'wamid.HBgM...', '');
```

### 3.8 `markAsRead()` — read receipt

```php
$messenger->markAsRead('wamid.HBgM...');

// Show the typing indicator at the same time
$messenger->markAsRead('wamid.HBgM...', ['typing_indicator' => ['type' => 'text']]);
```

---

## 4. Account Information

### 4.1 `getAccountInfo()` — phone number profile

```php
$info = $messenger->getAccountInfo();

$info->raw['id'];                      // phone number id
$info->raw['display_phone_number'];
$info->raw['verified_name'];
$info->raw['quality_rating'];
$info->raw['platform_type'];
$info->raw['code_verification_status'];
```

Requests `GET /{phone_number_id}?fields=…`; override the field list through `fields`:

```php
$messenger->getAccountInfo(['fields' => 'id,display_phone_number,verified_name']);
```

### 4.2 `getPhoneNumbers()` — numbers of the business account

```php
$result = $messenger->getPhoneNumbers(['limit' => 100]);

foreach ($result->raw['data'] as $number) {
    $number['id'];
    $number['display_phone_number'];
    $number['verified_name'];
}
```

Requires `business_account_id`.

### 4.3 `getTemplates()` — message templates

```php
$result = $messenger->getTemplates(['limit' => 100, 'status' => 'APPROVED']);

foreach ($result->raw['data'] as $template) {
    $template['name'];
    $template['status'];      // APPROVED | PENDING | REJECTED
    $template['language'];
    $template['components'];
}
```

Requires `business_account_id`; passes the `get_templates` capability check.

---

## 5. Media Management

### 5.1 `uploadMedia()`

```php
$result = $messenger->uploadMedia('/var/media/photo.jpg', 'image/jpeg');

$result->messageId;   // media id, reusable in image/document/video/audio/sticker objects
```

Uploads with `POST /{phone_number_id}/media` as multipart (`messaging_product`, `type`, `file`).
A missing or unreadable file raises `MessengerApiException`.

### 5.2 `getMedia()` — resolve a media id

```php
$result = $messenger->getMedia('1234567890');

$result->raw['url'];           // temporary download URL
$result->raw['mime_type'];
$result->raw['file_size'];
$result->raw['id'];
```

### 5.3 `deleteMedia()`

```php
$result = $messenger->deleteMedia('1234567890');
```

---

## 6. Credential and Recipient Validation

### 6.1 `validateCredentials()`

```php
$validation = $messenger->validateCredentials();

if ($validation->valid) {
    echo $validation->message;   // "Access token is valid. Number: 90555… (My Business)"
    echo $validation->data['display_phone_number'];
    echo $validation->data['quality_rating'];
} else {
    echo $validation->reason;    // empty | unauthorized | not_found | network_error | …
    echo $validation->message;
}
```

Flow: empty token check → `getAccountInfo()` → `valid` on success, otherwise a reason mapped from
the Graph error code.

| Graph code / message | reason |
|---|---|
| `190`, `401`, "access token" | `unauthorized` |
| `100` (token context) | `not_found` — wrong `phone_number_id` |
| `131026`, "not a valid whatsapp" | `not_found` |
| `131047` | `forbidden` — outside the 24-hour window |
| `131056` | `forbidden` — pair rate limit |
| `132000`, `132001` | `invalid_format` — template mismatch |
| `4`, `80007`, `130429` | `unknown_error` — rate limited |
| `0` | `network_error` |

### 6.2 `validateRecipient()`

```php
$validation = $messenger->validateRecipient('905551112233');

$validation->valid;             // true
$validation->data['phone'];     // 905551112233
```

Rule: digits only, 8–15 characters (`/^\d{8,15}$/`). The Cloud API has no "does this number exist"
endpoint, so no lookup is attempted. An empty value yields `EMPTY_VALUE`, a non-matching value
`INVALID_FORMAT`.

Send a real message to confirm reachability:

```php
$validation = $messenger->validateRecipient('905551112233', [
    'send_test_message' => true,
    'test_message'      => '✅ WhatsApp connection successfully validated.',
]);

$validation->data['test_message_id'];   // set when the message was accepted
```

### 6.3 Static format helpers

```php
Messenger::isCredentialFormatValid('EAAG'.str_repeat('x', 30)); // true — non-empty, ≥ 20 chars
Messenger::isRecipientFormatValid('905551112233');              // true
Messenger::isRecipientFormatValid('+90 555 111 22 33');         // false
```

---

## 7. Error Handling and Logging

- A Graph error object `{error: {message, type, code, error_subcode, fbtrace_id}}` is converted into
  `MessengerApiException`; `execute()` turns it into
  `MessageResult::fail($error['code'], $error['message'])`.
- `ConnectException` is retried `retry_attempts` times, waiting `retry_delay_ms` between attempts.
- A response that cannot be decoded raises `MessengerApiException`
  ("Could not decode the whatsapp response (invalid JSON).").
- When a `LoggerInterface` is supplied, `info` / `warning` / `error` events are written and all
  context values are sanitised before logging.

```php
$messenger = MessengerFactory::make('whatsapp', $credentials, [], $logger);
```

---

## 8. Capability Map

| Capability | Method | Endpoint |
|---|---|---|
| `send_message` | `sendMessage()` | `POST /{phone_number_id}/messages` |
| `send_photo` | `sendPhoto()` | `POST /{phone_number_id}/messages` |
| `send_document` | `sendDocument()` | `POST /{phone_number_id}/messages` |
| `send_video` | `sendVideo()` | `POST /{phone_number_id}/messages` |
| `send_audio` | `sendAudio()` | `POST /{phone_number_id}/messages` |
| `send_sticker` | `sendSticker()` | `POST /{phone_number_id}/messages` |
| `send_location` | `sendLocation()` | `POST /{phone_number_id}/messages` |
| `send_contact` | `sendContact()` | `POST /{phone_number_id}/messages` |
| `send_template` | `sendTemplate()` | `POST /{phone_number_id}/messages` |
| `send_interactive` | `sendInteractive()` | `POST /{phone_number_id}/messages` |
| `send_reaction` | `sendReaction()` | `POST /{phone_number_id}/messages` |
| `mark_as_read` | `markAsRead()` | `POST /{phone_number_id}/messages` |
| `get_account_info` | `getAccountInfo()` | `GET /{phone_number_id}` |
| `get_phone_numbers` | `getPhoneNumbers()` | `GET /{business_account_id}/phone_numbers` |
| `get_templates` | `getTemplates()` | `GET /{business_account_id}/message_templates` |
| `upload_media` | `uploadMedia()` | `POST /{phone_number_id}/media` |
| `get_media` | `getMedia()` | `GET /{media_id}` |
| `delete_media` | `deleteMedia()` | `DELETE /{media_id}` |

`editMessage()`, `deleteMessage()`, `forwardMessage()` and `copyMessage()` are not supported by the
Cloud API and throw `UnsupportedCapabilityException`.

---

## 9. Implementation Notes

- Authentication uses `Authorization: Bearer <token>`; message payloads are JSON, media uploads are
  `multipart/form-data`.
- `messaging_product: "whatsapp"` and `recipient_type: "individual"` are added to every message
  payload.
- Free-form text is only allowed inside the 24-hour customer service window; outside it, use
  `sendTemplate()`.
- Media ids are valid for 30 days; media URLs returned by `getMedia()` are short-lived.

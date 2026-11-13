# NetGSM Provider

Sends SMS through the NetGSM SMS API v3 and reads the account side of it: balance, delivery
reports, statistics, inbox, sender headers, templates, job cancellation and length measurement.

- **Class:** `MyMessenger\Provider\NetGSM\Messenger`
- **Platform name:** `netgsm` (aliases: `net_gsm`, `sms`)
- **Interfaces:** `SmsMessengerInterface`, `ValidatesCredentialsInterface`
- **Live base:** `https://ext-api.netgsm.com.tr`
- **Sandbox base:** `https://ext-sandbox.netgsm.com.tr`
- **Docs:** <https://api-docs.netgsm.com.tr>
- **Requirement:** `guzzlehttp/guzzle` — the constructor throws `MessengerException` when it is missing

---

## 1. Creating a Messenger

### 1.1 Through the factory

```php
use MyMessenger\Core\MessengerFactory;

$messenger = MessengerFactory::make(
    'netgsm',
    [
        'username' => '312XXXXXXX',   // NetGSM subscriber number
        'password' => 'YOUR_PASSWORD',
        'sender'   => 'EPIKSEL',      // default sender header
    ],
    [
        'timeout'         => 10,
        'connect_timeout' => 5,
        'retry_attempts'  => 2,
        'retry_delay_ms'  => 200,
    ],
);
```

### 1.2 With a ready bearer token

```php
$messenger = MessengerFactory::make('netgsm', [
    'token'        => 'eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9...',
    'refresh_token' => 'REFRESH_TOKEN',   // optional
    'sender'       => 'EPIKSEL',
]);
```

When `token` is present it is used as-is and no `/auth/v3/token` request is made.

### 1.3 Directly, with your own HTTP client

```php
use GuzzleHttp\Client;
use MyMessenger\Credentials\CredentialBag;
use MyMessenger\Provider\NetGSM\Messenger;

$messenger = new Messenger(
    new CredentialBag(['username' => '312XXXXXXX', 'password' => 'secret', 'sender' => 'EPIKSEL']),
    ['timeout' => 10],
    null,               // ?LoggerInterface
    new Client(),       // ?ClientInterface
);
```

### 1.4 Credentials

| Key | Required | Description |
|---|---|---|
| `token` | one of | Ready bearer token (JWT). Takes precedence over username/password. |
| `username` | one of | NetGSM subscriber number used for the token exchange. |
| `password` | one of | Password belonging to `username`. |
| `refresh_token` | no | Used by `POST /auth/v3/refresh` when the access token expires. |
| `sender` | no | Default sender header, used when `$options['sender']` is absent. |

Either `token`, or `username` + `password`, must be supplied. Otherwise the constructor throws
`MessengerException`.

### 1.5 Configuration keys

| Key | Type | Default | Description |
|---|---|---|---|
| `timeout` | int (seconds) | Guzzle default | Total request timeout. |
| `connect_timeout` | int (seconds) | Guzzle default | Connection timeout. |
| `retry_attempts` | int | `0` | Extra attempts for transport (connect/timeout) failures only. |
| `retry_delay_ms` | int | `100` | Delay between retries in milliseconds. |
| `http_client` | `ClientInterface` | — | Pre-built Guzzle client (fourth constructor argument). |
| `http_client_config` | array | `[]` | Options used when the internal `Client` is created. |
| `base_uri` | string | `https://ext-api.netgsm.com.tr` | Overrides the API host. |
| `sandbox` | bool | `false` | Uses `https://ext-sandbox.netgsm.com.tr` when `base_uri` is not set. |
| `scope` | string | `sms` | Scope requested during the token exchange. |
| `sender` | string | `NETGSM` | Fallback sender header. |
| `encoding` | string | `DEFAULT` | `DEFAULT`, `TURKISH` or `UNICODE`. |
| `iys_filter` | string | — | `NONE`, `BRAND`, `RETAILER` or `BRAND_AND_RETAILER`; sent as `iysFilter` when set. |
| `otp_enterprise` | bool | `false` | Adds `x-client: enterprise` to OTP requests. |
| `refresh_token` | string | — | Alternative to the `refresh_token` credential. |

### 1.6 Capability checks

```php
use MyMessenger\Enums\Capability;

$messenger->supports(Capability::SEND_BULK);        // true
$messenger->supports('send_otp');                   // true — plain strings are accepted
$messenger->supports(Capability::SEND_PHOTO);       // false — SMS has no media
```

---

## 2. Result Objects

```php
$result = $messenger->sendMessage('905551112233', 'Hello');

$result->success;    // bool
$result->messageId;  // ?string — jobId
$result->platform;   // 'netgsm'
$result->raw;        // array — raw NetGSM JSON response
$result->error;      // ?MessageError { code, message }
$result->toArray();  // all fields as an array
```

Failures never escape as exceptions: `execute()` catches them and returns `MessageResult::fail()`.
The bearer token is replaced with `***` inside error messages.

---

## 3. Sending SMS

### 3.1 `sendMessage()` — single recipient

```php
$result = $messenger->sendMessage(
    '0555 111 22 33',
    'Siparişiniz kargoya verildi.',
    [
        'sender'      => 'EPIKSEL',
        'encoding'    => 'TURKISH',
        'iysFilter'   => 'BRAND',
        'referenceId' => 'order-4711',
    ],
);

if ($result->success) {
    echo 'Job: ' . $result->messageId;             // 179309912974612345062827
    echo 'Accepted: ' . $result->raw['accepted'];  // 1
} else {
    echo $result->error->message . ' (code: ' . $result->error->code . ')';
}
```

Internally this calls `sendBulk()` with a single recipient, so the payload is identical to
`POST /send`.

### 3.2 `sendBulk()` — many recipients in one job

```php
$result = $messenger->sendBulk(
    ['05551112233', '905551112244', '-4915112345678'],
    'Kampanya başladı!',
    ['sender' => 'EPIKSEL'],
);

$result->raw['accepted'];              // 3
$result->raw['rejected'];              // 0
$result->raw['rejectedItems'];         // [] — first 100 rejected records with index + reason
$result->raw['rejectedItemsTruncated'];// false
$result->raw['status'];                // QUEUED | SCHEDULED
```

Payload:

```json
{
  "sender": "EPIKSEL",
  "messages": [
    { "text": "Kampanya başladı!", "recipient": "905551112233" },
    { "text": "Kampanya başladı!", "recipient": "905551112244" }
  ],
  "encoding": "DEFAULT"
}
```

`POST /sms/v3/send` returns `202 Accepted`: the job is queued and delivery happens afterwards.
Partial failures do not fail the whole request — the accepted records are sent and the rejected
ones are reported in `rejectedItems`. If **every** record is rejected, NetGSM answers
`422 NO_DELIVERABLE_MESSAGES` and `$result->success` is `false`.

Per-record rejection reasons: `INVALID_RECIPIENT`, `INVALID_MESSAGE`, `MESSAGE_TOO_LONG`,
`MALFORMED_ENTRY`, `DUPLICATE_RECIPIENT`, `NO_INTERNATIONAL_PERMISSION`.

### 3.3 `sendOtp()` — one-time password

```php
$result = $messenger->sendOtp('905551112233', 'Dogrulama kodunuz: 123456', [
    'appName' => 'MyApp',
]);

echo $result->messageId;   // OTP job id
```

OTP runs on a dedicated channel: one message, one recipient, delivered immediately. Job ids from
this endpoint are **not** queryable through `getReport()`.

NetGSM transliterates the OTP text before sending (`ç→c`, `ğ→g`, `ı→i`, …) and always sends a
single SMS: at most 155 characters for an alphanumeric header and 160 for a subscriber number.

Set `otp_enterprise => true` in the config to send the `x-client: enterprise` header required by
corporate OTP accounts.

### 3.4 `sendTemplateMessages()` — pre-approved templates

```php
$result = $messenger->sendTemplateMessages(
    [
        [
            'templateId' => 3,
            'recipient'  => '905551112233',
            'params'     => ['otpCode' => '123456'],
        ],
        [
            'templateId' => 3,
            'recipient'  => '905551112244',
            'params'     => ['otpCode' => '654321'],
        ],
    ],
    ['sender' => 'EPIKSEL'],
);
```

Between 1 and 20 records per request (validated locally). Unlike `/send`, template errors reject
the whole request: a missing or unknown `templateId`, an unusable template or parameters that do
not match it return `400`. After the templates are filled, records are validated exactly like
`/send` and reported through `rejectedItems` with their `index`.

Use `listTemplates()` to discover template ids, placeholders and accepted parameter types.

### 3.5 `measureLength()` — SMS part count

```php
$result = $messenger->measureLength('Merhaba dünya', [
    'encoding'           => 'TURKISH',
    'alphanumericSender' => true,
]);

$result->raw['length'];                 // 30 — counted the way /send counts it
$result->raw['parts'];                  // 1  — SMS parts
$result->raw['limit'];                  // 888 — maximum allowed length for this sender type
$result->raw['remaining'];              // 858
$result->raw['remainingUntilNextPart']; // 125
$result->raw['fits'];                   // true
```

`POST /sms/v3/length` only measures; nothing is sent.

### 3.6 Recipient formats

| Type | Format | Example |
|---|---|---|
| Türkiye | `90` + 10 digits | `905061234567` |
| International | `-` + country code and number (9–17 digits) | `-4915112345678` |

`sendMessage()`/`sendBulk()`/`sendOtp()` normalise common spellings before sending:

| Input | Sent as |
|---|---|
| `0555 111 22 33` | `905551112233` |
| `+90 555 111 22 33` | `905551112233` |
| `5551112233` | `905551112233` |
| `905551112233` | `905551112233` |
| `-49 151 12345678` | `-4915112345678` |

### 3.7 Sender headers

The sender header must match a header registered on the account **exactly**, including case and
without trimming. Subscriber-number headers are written without a leading `0` or `90`
(`5061234567`). An unknown header returns `422 REJECTED_HEADER`.

Resolution order: `$options['sender']` → `sender` credential → `sender` config → `NETGSM`.

---

## 4. Reports, Inbox and Statistics

### 4.1 `getReport()` — delivery reports

```php
$result = $messenger->getReport([
    'jobIds'  => ['179309912974612345062827'],
    'startAt' => '2026-08-01T00:00:00+03:00',
    'stopAt'  => '2026-08-31T23:59:59+03:00',
    'page'    => 0,
]);

foreach ($result->raw['reports'] as $report) {
    $report['number'];        // recipient without the 90 prefix
    $report['status'];        // PENDING | DELIVERED | NOT_DELIVERED
    $report['operator'];      // VODAFONE | TURK_TELEKOM | TURKCELL | NETGSM | AKONTEL | INTERNATIONAL | UNKNOWN
    $report['messageLength']; // SMS parts
    $report['deliveredAt'];   // only when DELIVERED
    $report['failReason'];    // only when NOT_DELIVERED
}
```

At most 100 rows per page; keep requesting the next `page` until `count` drops below 100.
`jobIds` accepts an array (joined with commas) or an already formatted string. `startAt` and
`stopAt` must be sent together and the range cannot exceed one month.

Common `failReason` values: `INVALID_NUMBER`, `NOT_SENT_TO_OPERATOR`, `OPERATOR_REJECTED`,
`INSUFFICIENT_CREDIT`, `BLACKLISTED`, `IYS_REJECTED`, `TIMEOUT`, `UNKNOWN`.

### 4.2 `getStats()` — per-job totals

```php
$result = $messenger->getStats([
    'jobId' => '179309912974612345062827',
    'month' => '2026-09',   // YYYY-MM, defaults to the current month
]);

foreach ($result->raw['stats'] as $row) {
    $row['status'];        // PENDING | DELIVERED | NOT_DELIVERED
    $row['failReason'];    // only for NOT_DELIVERED
    $row['messageCount'];  // recipients with this outcome
    $row['totalParts'];    // SMS parts
    $row['domestic'];      // bool
    $row['charging'];      // charged | refunded | uncharged
}
```

`jobId` is required and validated locally. A single `failReason` may appear on several rows
(different internal result codes); sum `messageCount` per reason for a total.

### 4.3 `readInbox()` — received SMS

```php
$result = $messenger->readInbox([
    'startAt' => '2026-08-31T00:00:00+03:00',
    'stopAt'  => '2026-08-31T23:59:59+03:00',
]);

foreach ($result->raw['messages'] as $message) {
    $message['sender'];     // number without the 90 prefix
    $message['text'];
    $message['operator'];   // e.g. "NETGSM MOBIL"
    $message['receivedAt']; // ISO-8601 or null
    $message['jobId'];      // null when not linked to a job
}

$result->raw['truncated'];  // true when the 500-message limit was hit
```

`startAt` and `stopAt` must be sent together; the range cannot exceed two months. Without them the
current month up to now (Europe/Istanbul) is used. When `truncated` is `true`, query a narrower
range for the remaining rows.

### 4.4 `getBalance()` — packages, campaigns and credit

```php
$result = $messenger->getBalance(['stip' => 3, 'service' => 'sms']);

$result->raw;   // raw balance payload — amount may be a string or a number
```

| Parameter | Values | Meaning |
|---|---|---|
| `stip` | `1` (default), `2`, `3` | `1` assets by type, `2` one-line summary, `3` assets plus credit balance. |
| `service` | `sms`, `otp`, `ses`, `fax`, `whatsapp`, `santral`, `netsipp`, `gsm`, `internet`, `numara`, `ai`, `ucret` | Restricts the list to one service group. |

The response body does not follow the API's general envelope: `amount` may be a string or a
number, credit is a comma-separated string, and `stip=2` may omit fields entirely. Responses are
cached by NetGSM for 10 seconds per account/`stip`/`service`.

---

## 5. Sender Headers, Templates and Cancellation

### 5.1 `listSenders()`

```php
$result = $messenger->listSenders();

foreach ($result->raw['senders'] as $sender) {
    $sender['name'];       // header as it must be passed to "sender"
    $sender['type'];       // ALPHANUMERIC | SUBSCRIBER_NUMBER | UNKNOWN
    $sender['brandCode'];  // İYS brand code, 0 when absent
    $sender['usable'];     // bool — would a send from this header be accepted?
    $sender['reason'];     // ROUTING_CODE_MISSING | SUBSCRIBER_NUMBER_RESTRICTED | null
}
```

`GET /sms/v3/senders` returns `count: 0` for accounts without headers.

### 5.2 `listTemplates()`

```php
$result = $messenger->listTemplates([
    'includePublic' => false,
    'type'          => 'AUTH_OTP',
    'search'        => 'verification',
    'page'          => 0,
    'size'          => 20,
]);

foreach ($result->raw['templates'] as $template) {
    $template['templateId'];   // pass this to sendTemplateMessages()
    $template['content'];      // "Dogrulama kodunuz: ${otpCode}"
    $template['type'];         // AUTH_OTP | SECURITY_ALERT | SERVICE_ALERT | …
    $template['public'];       // bool
    $template['parameters'];   // [['name' => 'otpCode', 'type' => 'STRING'], …]
}

$result->raw['total'];        // templates matching the filter
$result->raw['totalPages'];
```

`size` is capped at 100.

### 5.3 `cancelJob()`

```php
$result = $messenger->cancelJob('179309912974612345062827');

$result->raw['cancelledAt'];   // ISO-8601 with timezone offset
```

Only jobs still in `SCHEDULED` state can be cancelled. A job whose `status` is `QUEUED` has
already started sending; NetGSM answers `404` for unknown or non-cancellable jobs.

### 5.4 `getAccountInfo()` — senders + summary balance

```php
$result = $messenger->getAccountInfo();

$result->raw['senders'];   // same payload as listSenders()
$result->raw['balance'];   // same payload as getBalance(['stip' => 2])
```

Two requests are made, both inside the same `execute()` call.

---

## 6. Credential and Recipient Validation

### 6.1 `validateCredentials()`

```php
$validation = $messenger->validateCredentials();

if ($validation->valid) {
    echo $validation->message;              // "NetGSM credentials are valid."
    echo $validation->data['balance'];      // remaining credit / summary
} else {
    echo $validation->reason;               // unauthorized | no_permission | network_error | …
    echo $validation->data['api_code'];     // UNAUTHENTICATED, FORBIDDEN, …
    echo $validation->message;
}
```

Flow: token/username presence → `getBalance(['stip' => 2])` → `valid` on success, otherwise a
reason mapped from the NetGSM error code.

| NetGSM code | reason |
|---|---|
| `UNAUTHENTICATED` | `unauthorized` |
| `FORBIDDEN`, `api_access_denied`, `service_not_activated` | `no_permission` |
| `REJECTED_HEADER`, `REJECTED_IYS` | `no_permission` |
| `INSUFFICIENT_CREDIT` | `unknown_error` |
| `NO_DELIVERABLE_MESSAGES` | `invalid_format` |
| `RATE_LIMITED` | `unknown_error` |
| `NOT_FOUND` | `not_found` |
| `SERVICE_UNAVAILABLE`, `INTERNAL_ERROR` | `network_error` |

### 6.2 `validateRecipient()`

```php
$validation = $messenger->validateRecipient('0555 111 22 33');

$validation->valid;             // true
$validation->data['phone'];     // 905551112233
```

Format check only, no network request. An empty value yields `EMPTY_VALUE`, an unparsable one
`INVALID_FORMAT`.

Add `send_test_message => true` to send a real SMS and confirm end-to-end delivery:

```php
$validation = $messenger->validateRecipient('905551112233', [
    'send_test_message' => true,
    'test_message'      => '✅ NetGSM connection successfully validated.',
    'sender'            => 'EPIKSEL',
]);

$validation->data['job_id'];    // set when the test SMS was accepted
$validation->data['accepted'];
$validation->data['rejected'];
```

### 6.3 Static format helpers

```php
Messenger::isCredentialFormatValid('eyJhbGciOiJIUzI1NiJ9.eyJzdWIiOiIxIn0.sig'); // true — JWT shape
Messenger::isCredentialFormatValid('abc123');                                  // false
Messenger::isRecipientFormatValid('905551112233');                             // true
Messenger::isRecipientFormatValid('-4915112345678');                           // true
Messenger::isRecipientFormatValid('05551112233');                              // false
```

---

## 7. Authentication and Token Refresh

1. `token` credential present → used directly.
2. Otherwise `username` + `password` are exchanged at `POST /auth/v3/token` with
   `{username, password, scope}`; `access_token`, `refresh_token` and `expires_in` are stored.
3. On a `401` / `UNAUTHENTICATED` answer the provider refreshes once and replays the same request:
   `POST /auth/v3/refresh` when a refresh token exists, otherwise a full token exchange.
4. If the replay also fails, the error is returned as `MessageResult::fail()`.

```php
$messenger->tokenExpiresAt();   // ?int — unix timestamp when known
```

---

## 8. Error Handling and Logging

- NetGSM error envelope: `{code, message, requestId, timestamp, violations}`. `code` becomes
  `MessageResult::$error->code`; `violations` and `requestId` are appended to the message, e.g.
  `Sender header not found. [sender: unknown header] (requestId: abc123)`.
- `429` answers include `Retry-After`; the provider does not retry them automatically. Add the
  code to `retryable_api_codes` if you want `execute()` to retry it:

  ```php
  MessengerFactory::make('netgsm', $credentials, [
      'retry_attempts'      => 2,
      'retry_delay_ms'      => 500,
      'retryable_api_codes' => ['RATE_LIMITED', 'SERVICE_UNAVAILABLE'],
  ]);
  ```

- `ConnectException` is retried `retry_attempts` times, waiting `retry_delay_ms` between attempts.
- A response that cannot be decoded raises `MessengerApiException`
  ("Could not decode the netgsm response (invalid JSON).").
- When a `LoggerInterface` is supplied, `info` / `warning` / `error` events are written and all
  context values are sanitised before logging.

```php
$messenger = MessengerFactory::make('netgsm', $credentials, [], $logger);
```

---

## 9. Capability Map

| Capability | Method | Endpoint |
|---|---|---|
| `send_message` | `sendMessage()` | `POST /sms/v3/send` |
| `send_bulk` | `sendBulk()` | `POST /sms/v3/send` |
| `send_otp` | `sendOtp()` | `POST /sms/v3/send/otp` |
| `send_template_messages` | `sendTemplateMessages()` | `POST /sms/v3/send/template` |
| `get_balance` | `getBalance()` | `GET /sms/v3/balance` |
| `get_report` | `getReport()` | `GET /sms/v3/report` |
| `get_stats` | `getStats()` | `GET /sms/v3/stats` |
| `read_inbox` | `readInbox()` | `GET /sms/v3/inbox` |
| `list_senders` | `listSenders()` | `GET /sms/v3/senders` |
| `list_templates` | `listTemplates()` | `GET /sms/v3/templates` |
| `cancel_job` | `cancelJob()` | `POST /sms/v3/cancel` |
| `measure_length` | `measureLength()` | `POST /sms/v3/length` |
| `get_account_info` | `getAccountInfo()` | `GET /sms/v3/senders` + `GET /sms/v3/balance` |

---

## 10. Implementation Notes

- Authentication uses `Authorization: Bearer <token>` with `Content-Type: application/json`.
- Sends and cancels are `POST` with a JSON body; reports, inbox, senders, templates and balance are
  `GET` with query parameters.
- There is no request de-duplication: sending the same payload twice creates two jobs. After a
  `503`, check `getReport()` before resending blindly.
- Request limits: `50.000` requests/minute for `/send`, `1.000` for `/send/template`, `60` for
  `/balance`.
- `sendBulk()` accepts up to 1.000.000 records per request; prefer one large request over many
  small ones.

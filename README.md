# MyMessenger

A platform-agnostic, multi-network-ready PHP messaging library.

- **PHP ≥ 8.3**
- **Guzzle ≥ 8.0**
- **PSR-4 namespace:** `MyMessenger\`

## Installation

```bash
composer require epiksel/messenger
```

## Providers

| Platform | Aliases | Class | Docs |
|---|---|---|---|
| `telegram` | `tg` | `MyMessenger\Provider\Telegram\Messenger` | [README](src/Provider/Telegram/README.md) |
| `whatsapp` | `wa`, `whatsapp_cloud` | `MyMessenger\Provider\WhatsApp\Messenger` | [README](src/Provider/WhatsApp/README.md) |
| `facebook` | `fb`, `messenger`, `facebook_messenger` | `MyMessenger\Provider\Facebook\Messenger` | [README](src/Provider/Facebook/README.md) |
| `netgsm` | `net_gsm`, `sms` | `MyMessenger\Provider\NetGSM\Messenger` | [README](src/Provider/NetGSM/README.md) |

## 🚀 Basic Usage

### 1. Create a Messenger

`MessengerFactory` resolves every supported platform and its aliases:

```php
use MyMessenger\Core\MessengerFactory;

$messenger = MessengerFactory::make(
    'telegram',
    ['token' => 'YOUR_TOKEN'],
    [
        'timeout'         => 10,
        'connect_timeout' => 5,
        'retry_attempts'  => 2,
        'retry_delay_ms'  => 200,
    ],
);
```

Credentials differ per platform:

```php
$whatsapp = MessengerFactory::make('whatsapp', [
    'token'           => 'EAAG...',
    'phone_number_id' => '1234567890',
]);

$facebook = MessengerFactory::make('facebook', [
    'token'   => 'EAAG...',
    'page_id' => '1234567890',
]);

$netgsm = MessengerFactory::make('netgsm', [
    'username' => '312XXXXXXX',
    'password' => 'YOUR_PASSWORD',
    'sender'   => 'EPIKSEL',
]);
```

Credentials may also come from a `CredentialProviderInterface`, in which case the bag is resolved
per platform:

```php
use MyMessenger\Credentials\ArrayCredentialProvider;
use MyMessenger\Core\MessengerFactory;

$provider = new ArrayCredentialProvider([
    'telegram' => ['token' => 'YOUR_TOKEN'],
    'netgsm'   => ['username' => '312XXXXXXX', 'password' => 'YOUR_PASSWORD'],
]);

$messenger = MessengerFactory::make('telegram', $provider);
```

`MessengerFactory::platforms()` lists the canonical names and `MessengerFactory::supports($name)`
tells you whether an alias resolves. A `CredentialBag` can be passed directly as well.

### 2. Send a Message

```php
$result = $messenger->sendMessage(
    'RECIPIENT_ID',
    'Hello, world!'
);

if ($result->success) {
    echo 'Message sent successfully with ID: ' . $result->messageId;
} else {
    echo 'Error: ' . $result->error->message . ' (code: ' . $result->error->code . ')';
}
```

`messageId` is nullable: some platforms do not return a message identifier, and for WhatsApp the
id lives in `$result->raw['messages'][0]['id']`.

### 3. Validate Credentials

Validation lives on `ValidatesCredentialsInterface`, which is not part of `MessengerInterface`, so
check the instance first:

```php
use MyMessenger\Contracts\ValidatesCredentialsInterface;

if ($messenger instanceof ValidatesCredentialsInterface) {
    $validation = $messenger->validateCredentials();

    if ($validation->valid) {
        echo 'Token valid.';
    } else {
        echo $validation->reason . ': ' . $validation->message;
    }
}
```

### 4. Validate Recipient

```php
use MyMessenger\Contracts\ValidatesCredentialsInterface;

if ($messenger instanceof ValidatesCredentialsInterface) {
    $validation = $messenger->validateRecipient('RECIPIENT_ID');

    if ($validation->valid) {
        echo 'Recipient valid.';
    } else {
        echo $validation->reason . ': ' . $validation->message;
    }
}
```

### 5. Check Capabilities

```php
use MyMessenger\Enums\Capability;

if ($messenger->supports(Capability::SEND_PHOTO)) {
    $messenger->sendPhoto('RECIPIENT_ID', 'https://example.com/photo.jpg');
}

$messenger->capabilities();   // list<string> of every advertised capability value
```

Calling an unsupported method throws `UnsupportedCapabilityException`.

### 6. SMS Gateways

SMS providers implement `SmsMessengerInterface`, which extends `MessengerInterface` with the
operations that only make sense for a gateway:

```php
use MyMessenger\Contracts\SmsMessengerInterface;

if ($messenger instanceof SmsMessengerInterface) {
    $messenger->sendBulk(['905551112233', '905551112244'], 'Kampanya başladı!');
    $messenger->sendOtp('905551112233', 'Dogrulama kodunuz: 123456');
    $messenger->sendTemplateMessages([
        ['templateId' => 3, 'recipient' => '905551112233', 'params' => ['otpCode' => '123456']],
    ]);
    $messenger->getBalance(['stip' => 2]);
    $messenger->getReport(['jobIds' => ['179309912974612345062827']]);
    $messenger->getStats(['jobId' => '179309912974612345062827', 'month' => '2026-09']);
    $messenger->readInbox();
    $messenger->listSenders();
    $messenger->listTemplates(['type' => 'AUTH_OTP']);
    $messenger->cancelJob('179309912974612345062827');
    $messenger->measureLength('Merhaba dünya', ['encoding' => 'TURKISH']);
}
```

See the [NetGSM README](src/Provider/NetGSM/README.md) for the full payload and response shapes.

## 🧩 Shared Configuration

Every provider accepts the same transport keys:

| Key | Type | Default | Description |
|---|---|---|---|
| `timeout` | int (seconds) | Guzzle default | Total request timeout. |
| `connect_timeout` | int (seconds) | Guzzle default | Connection timeout. |
| `retry_attempts` | int | `0` | Extra attempts for transport failures and `retryable_api_codes`. |
| `retry_delay_ms` | int | `100` | Delay between retries in milliseconds. |
| `retryable_api_codes` | list<int\|string> | `[]` | API error codes that `execute()` retries. |
| `http_client` | `ClientInterface` | — | Pre-built Guzzle client. |
| `http_client_config` | array | `[]` | Options used when the internal `Client` is created. |

Each provider documents its own extra keys (`api_version`, `graph_base`, `base_uri`, `sandbox`,
`sender`, `encoding`, …).

## 🧱 Architecture

```
src/
├── Contracts/    MessengerInterface, SmsMessengerInterface, ValidatesCredentialsInterface
├── Core/         AbstractMessenger (shared plumbing), MessengerFactory
├── Credentials/  CredentialBag, ArrayCredentialProvider, CredentialProviderInterface
├── Enums/        Capability
├── Exceptions/   MessengerException hierarchy
├── Logging/      LoggerInterface, NullLogger
├── Provider/     Telegram, WhatsApp, Facebook, NetGSM
└── ValueObject/  MessageResult, MessageError, ValidationResult
```

`AbstractMessenger` supplies credential handling, secret masking, HTTP client resolution, retries,
capability guards, JSON/multipart helpers, media normalisation and response normalisation. Every
`MessengerInterface` method has a default implementation that throws
`UnsupportedCapabilityException`, so a provider only overrides what it really supports.

## 🧪 Smoke Test

`tests/smoke.php` exercises the factory, all four providers and the NetGSM flows against a mocked
Guzzle handler — no network access or real credentials required:

```bash
composer install
composer smoke     # or: php tests/smoke.php
composer lint      # php -l over src/ and tests/
```

## ⚖️ License & Commercial Use

This software is licensed under the **Apache License 2.0** with the **Commons Clause** condition.

Under these terms, you are free to download, modify, and use the software for personal or internal business purposes. However, **commercial sale, hosting, or offering this software as a managed service (SaaS) is strictly prohibited** without a dedicated commercial license.

## 💼 Commercial Licensing

If you wish to use this software in a proprietary commercial product, bypass the Commons Clause restrictions, or require enterprise support, please visit our **[Commercial Licensing Page](https://epiksel.net)** to purchase a license.

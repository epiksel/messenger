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

- [Telegram](src/Provider/Telegram/README.md)
- [WhatsApp](src/Provider/WhatsApp/README.md)
- [Facebook Messenger](src/Provider/Facebook/README.md)
- [NetGSM](src/Provider/NetGsm/README.md)

## 🚀 Basic Usage

### 1. Create a Messenger

```php
use MyMessenger\Core\MessengerFactory;

$messenger = MessengerFactory::make(
    'telegram', // or 'whatsapp', 'facebook', 'netgsm'
    ['token' => 'YOUR_TOKEN'],
    [
        'timeout'          => 10,
        'connect_timeout'  => 5,
        'retry_attempts'   => 2,
        'retry_delay_ms'   => 200,
    ],
);

// Or via a CredentialProviderInterface
use MyMessenger\Credentials\ArrayCredentialProvider;

$provider  = new ArrayCredentialProvider(['telegram' => ['token' => 'YOUR_TOKEN']]);
$messenger = MessengerFactory::make('telegram', $provider);
```

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

### 3. Validate Credentials

```php
$validation = $messenger->validateCredentials();

if ($validation->valid) {
    echo 'Token valid.';
} else {
    echo $validation->message;
}
```

### 4. Validate Recipient

```php
$validation = $messenger->validateRecipient('RECIPIENT_ID');

if ($validation->valid) {
    echo 'Recipient valid.';
} else {
    echo $validation->message;
}
```

## ⚖️ License & Commercial Use

This software is licensed under the **Apache License 2.0** with the **Commons Clause** condition.

Under these terms, you are free to download, modify, and use the software for personal or internal business purposes. However, **commercial sale, hosting, or offering this software as a managed service (SaaS) is strictly prohibited** without a dedicated commercial license.

## 💼 Commercial Licensing

If you wish to use this software in a proprietary commercial product, bypass the Commons Clause restrictions, or require enterprise support, please visit our **[Commercial Licensing Page](https://epiksel.net)** to purchase a license.

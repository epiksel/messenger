# My Messenger

A platform-agnostic, multi-network-ready PHP messaging library.
Send messages, photos, documents, video, audio, location, and contacts through a single interface — starting with Telegram (WhatsApp, Facebook, Instagram, and more coming soon).

- **PHP ≥ 8.3**
- **Guzzle ≥ 8.0**
- **PSR-4 namespace:** `MyMessenger\`

## 📦 Installation

```bash
composer require epiksel/messenger
composer install
```

## 🚀 Basic Usage

### 1. Create a Messenger

```php
<?php

use MyMessenger\Core\MessengerFactory;

// Simple: credentials as an array
$messenger = MessengerFactory::make(
    'telegram',
    ['token' => 'BOT_TOKEN'],
    [
        'timeout'          => 10,
        'connect_timeout'  => 5,
        'retry_attempts'   => 2,
        'retry_delay_ms'   => 200,
    ],
);

// Or via a CredentialProviderInterface
use MyMessenger\Credentials\ArrayCredentialProvider;

$provider  = new ArrayCredentialProvider(['telegram' => ['token' => 'BOT_TOKEN']]);
$messenger = MessengerFactory::make('telegram', $provider);
```

### 2. Send a Message

```php
$result = $messenger->sendMessage(
    '123456789',
    'Hello, world!',
    ['parse_mode' => 'html'],
);

if ($result->success) {
    echo 'Message ID: ' . $result->messageId;
    echo 'Raw: ' . json_encode($result->raw);
} else {
    echo 'Error: ' . $result->error->message . ' (code: ' . $result->error->code . ')';
}
```

### 3. Send a Photo

```php
$result = $messenger->sendPhoto(
    '123456789',
    '/path/to/local/photo.jpg',        // or 'https://example.com/photo.jpg'
    ['caption' => 'Here is the photo'],
);
```

### 4. Validate Credentials (Recommended)

Verify the token works before attempting to use it:

```php
if ($messenger instanceof \MyMessenger\Contracts\ValidatesCredentialsInterface) {
    $validation = $messenger->validateCredentials();

    if ($validation->valid) {
        echo 'Token valid. Bot: @' . $validation->data['username'];
    } else {
        echo $validation->message;   // e.g., "Invalid bot token format..."
    }
}
```

### 5. Validate Recipient

Verify that the Chat ID exists and the bot has permissions:

```php
if ($messenger instanceof \MyMessenger\Contracts\ValidatesCredentialsInterface) {
    $validation = $messenger->validateRecipient(
        'recipient_chat_id',
        ['send_test_message' => true, 'test_message' => '✅ Test Connection']
    );

    if ($validation->valid) {
        echo 'Chat verified: ' . $validation->data['title'];
    } else {
        echo $validation->message;
    }
}
```

## 📡 Supported Operations (Capabilities)

The library supports the following operations (varies by platform):

- `send_message`
- `send_photo`
- `send_document`
- `send_video`
- `send_audio`
- `send_location`
- `send_contact`
- `edit_message`
- `delete_message`
- `send_chat_action`
- `get_me`
- `get_account_info`
- `set_webhook`
- `delete_webhook`
- `get_webhook_info`
- `get_updates`
- `answer_callback_query`
- `get_chat`
- `get_chat_member`
- `pin_message`
- `unpin_message`
- `forward_message`
- `copy_message`

## ⚖️ License & Commercial Use

This software is licensed under the **Apache License 2.0** with the **Commons Clause** condition.

Under these terms, you are free to download, modify, and use the software for personal or internal business purposes. However, **commercial sale, hosting, or offering this software as a managed service (SaaS) is strictly prohibited** without a dedicated commercial license.

## 💼 Commercial Licensing

If you wish to use this software in a proprietary commercial product, bypass the Commons Clause restrictions, or require enterprise support, please visit our **[Commercial Licensing Page](https://epiksel.net)** to purchase a license.

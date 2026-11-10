# Telegram Provider

This provider enables sending messages via the Telegram Bot API.

## Supported Operations (Capabilities)

Each provider supports different operations. The capabilities are defined in the `Capability` enum:

- `SEND_MESSAGE` - Send text messages
- `SEND_PHOTO` - Send photos
- `SEND_DOCUMENT` - Send documents
- `SEND_VIDEO` - Send videos
- `SEND_AUDIO` - Send audio files
- `SEND_LOCATION` - Send location information
- `SEND_CONTACT` - Send contact information
- `EDIT_MESSAGE` - Edit previously sent messages
- `DELETE_MESSAGE` - Delete messages
- `SEND_CHAT_ACTION` - Send chat action (typing, etc.)
- `GET_ME` - Get bot/user information
- `GET_ACCOUNT_INFO` - Get account information
- `SET_WEBHOOK` - Set webhook URL
- `DELETE_WEBHOOK` - Delete webhook
- `GET_WEBHOOK_INFO` - Get webhook information
- `GET_UPDATES` - Get updates
- `ANSWER_CALLBACK_QUERY` - Answer callback queries
- `GET_CHAT` - Get chat information
- `GET_CHAT_MEMBER` - Get chat member information
- `PIN_MESSAGE` - Pin messages
- `UNPIN_MESSAGE` - Unpin messages
- `FORWARD_MESSAGE` - Forward messages
- `COPY_MESSAGE` - Copy messages

## Usage

### 1. Create a Messenger

```php
use MyMessenger\Core\MessengerFactory;

$messenger = MessengerFactory::make(
    'telegram',
    ['token' => 'YOUR_TELEGRAM_BOT_TOKEN'],
    [
        'timeout'          => 10,
        'connect_timeout'  => 5,
        'retry_attempts'   => 2,
        'retry_delay_ms'   => 200,
    ],
);

// Or via a CredentialProviderInterface
use MyMessenger\Credentials\ArrayCredentialProvider;

$provider  = new ArrayCredentialProvider(['telegram' => ['token' => 'YOUR_TELEGRAM_BOT_TOKEN']]);
$messenger = MessengerFactory::make('telegram', $provider);
```

### 2. Send a Text Message

```php
$result = $messenger->sendMessage(
    'CHAT_ID',
    'Hello, world!'
    ['parse_mode' => 'html']
);

if ($result->success) {
    echo 'Message sent successfully with ID: ' . $result->messageId;
    echo 'Raw: ' . json_encode($result->raw);
} else {
    echo 'Error: ' . $result->error->message . ' (code: ' . $result->error->code . ')';
}
```

### 3. Send a Photo

```php
$result = $messenger->sendPhoto(
    '123456789',
    '/path/to/local/photo.jpg', // or 'https://example.com/photo.jpg'
    ['caption' => 'Here is the photo']
);
```

### 4. Send a Document

```php
$result = $messenger->sendDocument(
    'CHAT_ID',
     '/path/to/local/photo.jpg', // or 'https://example.com/document.pdf'
);
```

### 5. Send a Video

```php
$result = $messenger->sendVideo(
    'CHAT_ID',
     '/path/to/local/photo.jpg', // or 'https://example.com/video.mp4'
);
```

### 6. Send Location

```php
$result = $messenger->sendLocation(
    'CHAT_ID',
    40.7128,
    -74.0060
);
```

### 7. Send Contact

```php
$result = $messenger->sendContact(
    'CHAT_ID',
    '+1234567890',
    'John Doe'
);
```

### 8. Validate Credentials

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

### 9. Validate Recipient

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
# WhatsApp Provider

This provider enables sending messages via the WhatsApp Business API.

## Usage

### 1. Create a Messenger

```php
use MyMessenger\Core\MessengerFactory;

$messenger = MessengerFactory::make(
    'whatsapp',
    ['token' => 'YOUR_WHATSAPP_ACCESS_TOKEN'],
    [
        'timeout'          => 10,
        'connect_timeout'  => 5,
        'retry_attempts'   => 2,
        'retry_delay_ms'   => 200,
    ],
);
```

### 2. Send a Text Message

```php
$result = $messenger->sendMessage(
    'PHONE_NUMBER',
    'Hello, world!'
);

if ($result->success) {
    echo 'Message sent successfully with ID: ' . $result->messageId;
} else {
    echo 'Error: ' . $result->error->message . ' (code: ' . $result->error->code . ')';
}
```

### 3. Send a Photo

```php
$result = $messenger->sendPhoto(
    'PHONE_NUMBER',
    'https://example.com/image.jpg'
);
```

### 4. Send a Document

```php
$result = $messenger->sendDocument(
    'PHONE_NUMBER',
    'https://example.com/document.pdf'
);
```

### 5. Send a Video

```php
$result = $messenger->sendVideo(
    'PHONE_NUMBER',
    'https://example.com/video.mp4'
);
```

### 6. Send Location

```php
$result = $messenger->sendLocation(
    'PHONE_NUMBER',
    40.7128,
    -74.0060
);
```

### 7. Send Contact

```php
$result = $messenger->sendContact(
    'PHONE_NUMBER',
    '+1234567890',
    'John Doe'
);
```

### 8. Validate Credentials

```php
$validation = $messenger->validateCredentials();

if ($validation->valid) {
    echo 'Access token valid.';
} else {
    echo $validation->message;
}
```

### 9. Validate Recipient

```php
$validation = $messenger->validateRecipient('PHONE_NUMBER');

if ($validation->valid) {
    echo 'Phone number is valid.';
} else {
    echo $validation->message;
}
```
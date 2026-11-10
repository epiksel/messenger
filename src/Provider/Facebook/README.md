# Facebook Messenger Provider

This provider enables sending messages via the Facebook Messenger API.

## Usage

### 1. Create a Messenger

```php
use MyMessenger\Core\MessengerFactory;

$messenger = MessengerFactory::make(
    'facebook',
    ['token' => 'YOUR_FACEBOOK_PAGE_ACCESS_TOKEN'],
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
    'USER_ID',
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
    'USER_ID',
    'https://example.com/image.jpg'
);
```

### 4. Send a Document

```php
$result = $messenger->sendDocument(
    'USER_ID',
    'https://example.com/document.pdf'
);
```

### 5. Send Location

```php
$result = $messenger->sendLocation(
    'USER_ID',
    40.7128,
    -74.0060
);
```

### 6. Send Quick Replies

```php
$quickReplies = [
    [
        'content_type' => 'text',
        'title' => 'Yes',
        'payload' => 'USER_CLICKED_YES',
    ],
    [
        'content_type' => 'text',
        'title' => 'No',
        'payload' => 'USER_CLICKED_NO',
    ],
];

$result = $messenger->sendQuickReplies(
    'USER_ID',
    'Do you like this?',
    $quickReplies
);
```

### 7. Send Button Template

```php
$buttons = [
    [
        'type' => 'postback',
        'title' => 'Click Me',
        'payload' => 'BUTTON_CLICKED',
    ],
    [
        'type' => 'web_url',
        'title' => 'Visit Website',
        'url' => 'https://example.com',
    ],
];

$result = $messenger->sendButtonTemplate(
    'USER_ID',
    'Check out this button!',
    $buttons
);
```

### 8. Send Generic Template

```php
$elements = [
    [
        'title' => 'Item 1',
        'image_url' => 'https://example.com/image1.jpg',
        'subtitle' => 'Description 1',
        'default_action' => [
            'type' => 'web_url',
            'url' => 'https://example.com/item1',
        ],
        'buttons' => [
            [
                'type' => 'postback',
                'title' => 'Select',
                'payload' => 'SELECT_ITEM_1',
            ],
        ],
    ],
];

$result = $messenger->sendGenericTemplate(
    'USER_ID',
    $elements
);
```

### 9. Set Webhook

```php
$result = $messenger->setWebhook(
    'https://yourdomain.com/webhook',
    [
        'verify_token' => 'YOUR_VERIFY_TOKEN',
        'fields' => ['messages', 'messaging_postbacks', 'message_reads'],
    ]
);
```

### 10. Validate Credentials

```php
$validation = $messenger->validateCredentials();

if ($validation->valid) {
    echo 'Token valid.';
} else {
    echo $validation->message;
}
```

### 11. Validate Recipient

```php
$validation = $messenger->validateRecipient('USER_ID');

if ($validation->valid) {
    echo 'User ID is valid.';
} else {
    echo $validation->message;
}
```
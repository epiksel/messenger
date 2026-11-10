# NetGSM Provider

This provider enables sending SMS messages via the NetGSM API.

## Usage

### 1. Create a Messenger

```php
use MyMessenger\Core\MessengerFactory;

$messenger = MessengerFactory::make(
    'netgsm',
    ['token' => 'YOUR_NETGSM_API_TOKEN'],
    [
        'timeout'          => 10,
        'connect_timeout'  => 5,
        'retry_attempts'   => 2,
        'retry_delay_ms'   => 200,
    ],
);
```

### 2. Send an SMS

```php
$result = $messenger->smsGonder(
    '905551234567',
    'Hello, world!',
    ['originator' => 'NETGSM']
);

if ($result->success) {
    echo 'Message sent successfully with ID: ' . $result->messageId;
} else {
    echo 'Error: ' . $result->error->message . ' (code: ' . $result->error->code . ')';
}
```

### 3. Check Balance

```php
$result = $messenger->bakiyeSorgula();

if ($result->success) {
    echo 'Balance: ' . $result->raw['balance'];
} else {
    echo 'Error: ' . $result->error->message;
}
```

### 4. Send Credit

```php
$result = $messenger->krediGonder(
    '905551234567',
    '100'
);

if ($result->success) {
    echo 'Credit sent successfully';
} else {
    echo 'Error: ' . $result->error->message;
}
```

### 5. Validate Credentials

```php
$validation = $messenger->validateCredentials();

if ($validation->valid) {
    echo 'Token valid.';
} else {
    echo $validation->message;
}
```

### 6. Validate Recipient

```php
$validation = $messenger->validateRecipient('905551234567');

if ($validation->valid) {
    echo 'Phone number is valid.';
} else {
    echo $validation->message;
}
```
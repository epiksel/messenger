<?php
declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use MyMessenger\Core\MessengerFactory;
use MyMessenger\Enums\Capability;
use MyMessenger\Provider\NetGSM\Messenger as NetGSM;
use MyMessenger\Credentials\CredentialBag;

$history = [];

function mockClient(array $responses, array &$history): Client {
	$stack = HandlerStack::create(new MockHandler($responses));
	$stack->push(Middleware::history($history));

	return new Client(['handler' => $stack]);
}

function check(string $label, bool $ok, string $detail = ''): void {
	printf("%s %s%s\n", $ok ? 'PASS' : 'FAIL', $label, $detail === '' ? '' : ' — ' . $detail);
	if (!$ok) {
		$GLOBALS['failures'] = ($GLOBALS['failures'] ?? 0) + 1;
	}
}

/* ------------------------------------------------ Factory ------------------ */
$telegram = MessengerFactory::make('tg', ['token' => '123:ABC'], ['http_client' => mockClient([], $history)]);
check('factory alias tg -> telegram', $telegram->platform() === 'telegram');

$netgsm = MessengerFactory::make(
	'sms',
	['token' => 'eyJhbGciOiJIUzI1NiJ9.eyJzdWIiOiIxIn0.sig'],
	['http_client' => mockClient([], $history)],
);
check('factory alias sms -> netgsm', $netgsm instanceof NetGSM, $netgsm->platform());
check('netgsm capabilities', in_array(Capability::SEND_BULK->value, $netgsm->capabilities(), true), (string) count($netgsm->capabilities()));

/* ------------------------------------------------ Token exchange ---------- */
$history = [];
$client = mockClient([
	new Response(200, [], json_encode([
		'access_token' => 'eyJ0b2tlbiI',
		'token_type' => 'Bearer',
		'expires_in' => 86400,
		'refresh_token' => 'refresh-uuid',
		'scope' => 'sms',
		'user_id' => 100001,
		'sub_user_id' => 0,
	])),
	new Response(202, [], json_encode([
		'jobId' => '179309912974612345062827',
		'status' => 'QUEUED',
		'accepted' => 2,
		'rejected' => 0,
		'rejectedItems' => [],
		'rejectedItemsTruncated' => false,
		'referenceId' => 'order-4711',
	])),
], $history);

$messenger = new NetGSM(
	new CredentialBag(['username' => '312XXXXXXX', 'password' => 'secret', 'sender' => 'EPIKSEL']),
	[],
	null,
	$client,
);

$result = $messenger->sendBulk(['0555 111 22 33', '905551112244'], 'Merhaba', ['referenceId' => 'order-4711']);
check('token exchange + bulk send', $result->success, (string) $result->messageId);
check('job id extracted', $result->messageId === '179309912974612345062827');
check('two requests made', count($history) === 2, (string) count($history));
check('auth endpoint', (string) $history[0]['request']->getUri() === 'https://ext-api.netgsm.com.tr/auth/v3/token', (string) $history[0]['request']->getUri());
check('send endpoint', (string) $history[1]['request']->getUri() === 'https://ext-api.netgsm.com.tr/sms/v3/send', (string) $history[1]['request']->getUri());

$sendBody = json_decode((string) $history[1]['request']->getBody(), true);
check('sender from credentials', ($sendBody['sender'] ?? null) === 'EPIKSEL', (string) ($sendBody['sender'] ?? 'null'));
check('recipient normalised 0->90', ($sendBody['messages'][0]['recipient'] ?? null) === '905551112233', (string) ($sendBody['messages'][0]['recipient'] ?? ''));
check('recipient normalised 90 kept', ($sendBody['messages'][1]['recipient'] ?? null) === '905551112244');
check('default encoding', ($sendBody['encoding'] ?? null) === 'DEFAULT');
check('bearer header', $history[1]['request']->getHeaderLine('Authorization') === 'Bearer eyJ0b2tlbiI');

/* ------------------------------------------------ Error mapping ----------- */
$history = [];
$client = mockClient([
	new Response(422, [], json_encode([
		'code' => 'REJECTED_HEADER',
		'message' => 'Sender header not found.',
		'requestId' => 'abc123',
		'timestamp' => '2026-09-10T12:00:00+03:00',
		'violations' => [['field' => 'sender', 'message' => 'unknown header']],
	])),
], $history);

$messenger = new NetGSM(new CredentialBag(['token' => 'eyJhbGciOiJIUzI1NiJ9.eyJzdWIiOiIxIn0.sig']), [], null, $client);
$result = $messenger->sendMessage('905551112233', 'test');
check('api error surfaced', !$result->success && $result->error?->code === 'REJECTED_HEADER', (string) $result->error?->code);
check('violations appended', str_contains((string) $result->error?->message, 'unknown header'), (string) $result->error?->message);
check('requestId appended', str_contains((string) $result->error?->message, 'abc123'));

/* ------------------------------------------------ Token refresh ----------- */
$history = [];
$client = mockClient([
	new Response(401, [], json_encode(['code' => 'UNAUTHENTICATED', 'message' => 'no bearer token'])),
	new Response(200, [], json_encode([
		'access_token' => 'eyJuZXci',
		'token_type' => 'Bearer',
		'expires_in' => 3600,
		'refresh_token' => 'r2',
		'scope' => 'sms',
		'user_id' => 1,
		'sub_user_id' => 0,
	])),
	new Response(200, [], json_encode(['count' => 0, 'senders' => []])),
], $history);

$messenger = new NetGSM(
	new CredentialBag(['token' => 'eyJvbGQi.eyJzdWIiOiIxIn0.sig', 'refresh_token' => 'r1']),
	[],
	null,
	$client,
);
$result = $messenger->listSenders();
check('refresh on 401', $result->success, (string) $result->error?->message);
check('three requests (fail, refresh, retry)', count($history) === 3, (string) count($history));
check('retry used new token', $history[2]['request']->getHeaderLine('Authorization') === 'Bearer eyJuZXci', $history[2]['request']->getHeaderLine('Authorization'));

/* ------------------------------------------------ Length / balance -------- */
$history = [];
$client = mockClient([
	new Response(200, [], json_encode([
		'encoding' => 'TURKISH', 'length' => 30, 'parts' => 1, 'limit' => 888,
		'fits' => true, 'remaining' => 858, 'remainingUntilNextPart' => 125,
	])),
], $history);
$messenger = new NetGSM(new CredentialBag(['token' => 'eyJh.eyJi.c']), [], null, $client);
$result = $messenger->measureLength('Merhaba dünya', ['encoding' => 'TURKISH']);
check('measureLength', $result->success && ($result->raw['parts'] ?? null) === 1);
check('measureLength endpoint', (string) $history[0]['request']->getUri() === 'https://ext-api.netgsm.com.tr/sms/v3/length');

/* ------------------------------------------------ Validation -------------- */
check('isRecipientFormatValid TR', NetGSM::isRecipientFormatValid('905551112233'));
check('isRecipientFormatValid intl', NetGSM::isRecipientFormatValid('-4915112345678'));
check('isRecipientFormatValid rejects 0555', !NetGSM::isRecipientFormatValid('05551112233'));
check('isCredentialFormatValid jwt', NetGSM::isCredentialFormatValid('eyJhbGciOiJIUzI1NiJ9.eyJzdWIiOiIxIn0.sig'));
check('isCredentialFormatValid rejects opaque', !NetGSM::isCredentialFormatValid('abc123'));

$history = [];
$client = mockClient([
	new Response(200, [], json_encode(['balance' => '1234.56', 'type' => 'credit'])),
], $history);
$messenger = new NetGSM(new CredentialBag(['token' => 'eyJh.eyJi.c']), [], null, $client);
$validation = $messenger->validateCredentials();
check('validateCredentials ok', $validation->valid, $validation->reason);
check('balance endpoint', (string) $history[0]['request']->getUri() === 'https://ext-api.netgsm.com.tr/sms/v3/balance?stip=2', (string) $history[0]['request']->getUri());

$validation = $messenger->validateRecipient('0555 111 22 33');
check('validateRecipient normalises', $validation->valid && $validation->data['phone'] === '905551112233', $validation->reason);

/* ------------------------------------------------ WhatsApp ---------------- */
$history = [];
$client = mockClient([
	new Response(200, [], json_encode([
		'messaging_product' => 'whatsapp',
		'messages' => [['id' => 'wamid.HBgM']],
	])),
], $history);
$whatsapp = MessengerFactory::make('wa', ['token' => 'EAAG'.str_repeat('x', 30), 'phone_number_id' => '1234567890'], ['http_client' => $client]);
$result = $whatsapp->sendMessage('905551112233', 'Merhaba');
check('whatsapp sendMessage', $result->success && $result->messageId === 'wamid.HBgM', (string) $result->messageId);
check('whatsapp endpoint', (string) $history[0]['request']->getUri() === 'https://graph.facebook.com/v27.0/1234567890/messages', (string) $history[0]['request']->getUri());
$body = json_decode((string) $history[0]['request']->getBody(), true);
check('whatsapp text payload', ($body['text']['body'] ?? null) === 'Merhaba' && ($body['type'] ?? null) === 'text');
check('whatsapp get_templates capability', $whatsapp->supports(Capability::GET_TEMPLATES->value));
check('whatsapp editMessage unsupported', !$whatsapp->supports(Capability::EDIT_MESSAGE->value));

/* ------------------------------------------------ WhatsApp template ------- */
$history = [];
$client = mockClient([
	new Response(200, [], json_encode(['messages' => [['id' => 'wamid.TPL']]])),
	new Response(200, [], json_encode(['messages' => [['id' => 'wamid.INT']]])),
	new Response(200, [], json_encode(['success' => true])),
], $history);
$whatsapp = MessengerFactory::make('wa', ['token' => 'EAAG'.str_repeat('z', 30), 'phone_number_id' => '42'], ['http_client' => $client]);

$result = $whatsapp->sendTemplate('905551112233', 'order_update', [
	'language'   => ['code' => 'tr'],
	'components' => [['type' => 'body', 'parameters' => [['type' => 'text', 'text' => '#4711']]]],
]);
check('whatsapp sendTemplate', $result->success && $result->messageId === 'wamid.TPL', (string) $result->messageId);
$templateBody = json_decode((string) $history[0]['request']->getBody(), true);
check('whatsapp template payload', ($templateBody['template']['name'] ?? null) === 'order_update' && ($templateBody['template']['language']['code'] ?? null) === 'tr');

$result = $whatsapp->sendInteractive('905551112233', [
	'type' => 'button',
	'body' => ['text' => 'Confirm?'],
	'action' => ['buttons' => [['type' => 'reply', 'reply' => ['id' => 'yes', 'title' => 'Yes']]]],
]);
check('whatsapp sendInteractive', $result->success && $result->messageId === 'wamid.INT');

$result = $whatsapp->markAsRead('wamid.IN');
check('whatsapp markAsRead', $result->success);
$readBody = json_decode((string) $history[2]['request']->getBody(), true);
check('whatsapp read payload', ($readBody['status'] ?? null) === 'read' && ($readBody['message_id'] ?? null) === 'wamid.IN');

/* ------------------------------------------------ Facebook ---------------- */
$history = [];
$client = mockClient([
	new Response(200, [], json_encode(['recipient_id' => '1234', 'message_id' => 'm_ABC'])),
], $history);
$facebook = MessengerFactory::make('fb', ['token' => 'EAAG'.str_repeat('y', 30), 'page_id' => '999'], ['http_client' => $client]);
$result = $facebook->sendMessage('1234567890', 'Hello');
check('facebook sendMessage', $result->success && $result->messageId === 'm_ABC', (string) $result->messageId);
check('facebook endpoint', (string) $history[0]['request']->getUri() === 'https://graph.facebook.com/v27.0/999/messages', (string) $history[0]['request']->getUri());

/* ------------------------------------------------ Telegram ---------------- */
$history = [];
$client = mockClient([
	new Response(200, [], json_encode(['ok' => true, 'result' => ['message_id' => 42, 'chat' => ['id' => 1]]])),
	new Response(200, [], json_encode(['ok' => true, 'result' => [['message_id' => 43], ['message_id' => 44]]])),
	new Response(200, [], json_encode(['ok' => true, 'result' => ['message_id' => 45, 'poll' => ['id' => 'p1']]])),
	new Response(200, [], json_encode(['ok' => true, 'result' => true])),
	new Response(200, [], json_encode(['ok' => true, 'result' => ['file_id' => 'f1', 'file_path' => 'photos/file_1.jpg']])),
], $history);
$telegram = MessengerFactory::make('telegram', ['token' => '123:ABC'], ['http_client' => $client]);
$result = $telegram->sendMessage('1', 'Hi');
check('telegram sendMessage', $result->success && $result->messageId === '42', (string) $result->messageId);

$result = $telegram->sendMediaGroup('1', [
	['type' => 'photo', 'media' => 'https://example.com/1.jpg'],
	['type' => 'photo', 'media' => 'https://example.com/2.jpg'],
]);
check('telegram sendMediaGroup', $result->success, (string) $result->error?->message);
$groupBody = json_decode((string) $history[1]['request']->getBody(), true);
check('telegram album media array', count($groupBody['media'] ?? []) === 2);

try {
	$telegram->sendMediaGroup('1', [['type' => 'photo', 'media' => 'x']]);
	check('telegram album size guard', false, 'no exception');
} catch (\MyMessenger\Exceptions\MessengerApiException $e) {
	check('telegram album size guard', str_contains($e->getMessage(), 'between 2 and 10'), $e->getMessage());
}

$result = $telegram->sendPoll('1', 'Pick one', ['a', 'b'], ['is_anonymous' => false]);
check('telegram sendPoll', $result->success && $result->messageId === '45', (string) $result->messageId);

$result = $telegram->sendReaction('1', '45', '👍');
check('telegram sendReaction', $result->success, (string) $result->error?->message);
$reactionBody = json_decode((string) $history[3]['request']->getBody(), true);
check('telegram reaction payload', ($reactionBody['reaction'][0]['emoji'] ?? null) === '👍');

$result = $telegram->getFile('f1');
check('telegram getFile', $result->success && $result->messageId === 'f1', (string) $result->messageId);
check('telegram fileUrl', $telegram->fileUrl('photos/file_1.jpg') === 'https://api.telegram.org/file/bot123:ABC/photos/file_1.jpg', $telegram->fileUrl('photos/file_1.jpg'));

try {
	$telegram->sendSenderAction('1', 'typing_on');
	check('telegram rejects unsupported capability', false, 'no exception');
} catch (\MyMessenger\Exceptions\UnsupportedCapabilityException $e) {
	check('telegram rejects unsupported capability', true, $e->getMessage());
}

/* ------------------------------------------------ Capability integrity ---- */
$enumValues = array_map(static fn (Capability $c): string => $c->value, Capability::cases());
$declared = [];

foreach ([$telegram, $whatsapp, $facebook, $netgsm] as $provider) {
	foreach ($provider->capabilities() as $capability) {
		$declared[$capability] = true;

		if (!in_array($capability, $enumValues, true)) {
			check(sprintf('%s capability "%s" exists in the enum', $provider->platform(), $capability), false);
		}
	}
}

check('every capability value is valid', true, (string) count($declared) . ' declared');
check('enum covers SMS capabilities', in_array(Capability::SEND_OTP->value, $enumValues, true));
check('no provider declares an unknown capability', array_diff(array_keys($declared), $enumValues) === []);

echo "\n", ($GLOBALS['failures'] ?? 0) === 0 ? "ALL PASS\n" : "FAILURES: {$GLOBALS['failures']}\n";

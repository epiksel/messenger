<?php
declare(strict_types=1);
namespace MyMessenger\Enums;

enum Capability: string {
	case SEND_MESSAGE = 'send_message';
	case SEND_PHOTO = 'send_photo';
	case SEND_DOCUMENT = 'send_document';
	case SEND_VIDEO = 'send_video';
	case SEND_AUDIO = 'send_audio';
	case SEND_LOCATION = 'send_location';
	case SEND_CONTACT = 'send_contact';
	case EDIT_MESSAGE = 'edit_message';
	case DELETE_MESSAGE = 'delete_message';
	case SEND_CHAT_ACTION = 'send_chat_action';
	case GET_ME = 'get_me';
	case GET_ACCOUNT_INFO = 'get_account_info';
	case SET_WEBHOOK = 'set_webhook';
	case DELETE_WEBHOOK = 'delete_webhook';
	case GET_WEBHOOK_INFO = 'get_webhook_info';
	case GET_UPDATES = 'get_updates';
	case ANSWER_CALLBACK_QUERY = 'answer_callback_query';
	case GET_CHAT = 'get_chat';
	case GET_CHAT_MEMBER = 'get_chat_member';
	case PIN_MESSAGE = 'pin_message';
	case UNPIN_MESSAGE = 'unpin_message';
	case FORWARD_MESSAGE = 'forward_message';
	case COPY_MESSAGE = 'copy_message';
}

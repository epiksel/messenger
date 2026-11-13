<?php
declare(strict_types=1);
namespace MyMessenger\Enums;

enum Capability: string {
	// Messaging
	case SEND_MESSAGE = 'send_message';
	case SEND_PHOTO = 'send_photo';
	case SEND_DOCUMENT = 'send_document';
	case SEND_VIDEO = 'send_video';
	case SEND_AUDIO = 'send_audio';
	case SEND_VOICE = 'send_voice';
	case SEND_STICKER = 'send_sticker';
	case SEND_VIDEO_NOTE = 'send_video_note';
	case SEND_MEDIA_GROUP = 'send_media_group';
	case SEND_LOCATION = 'send_location';
	case SEND_CONTACT = 'send_contact';
	case SEND_POLL = 'send_poll';
	case STOP_POLL = 'stop_poll';
	case SEND_DICE = 'send_dice';
	case SEND_REACTION = 'send_reaction';
	case SEND_TEMPLATE = 'send_template';
	case SEND_INTERACTIVE = 'send_interactive';
	case SEND_QUICK_REPLIES = 'send_quick_replies';
	case SEND_BUTTON_TEMPLATE = 'send_button_template';
	case SEND_GENERIC_TEMPLATE = 'send_generic_template';

	// Message management
	case EDIT_MESSAGE = 'edit_message';
	case DELETE_MESSAGE = 'delete_message';
	case SEND_CHAT_ACTION = 'send_chat_action';
	case SEND_SENDER_ACTION = 'send_sender_action';
	case MARK_AS_READ = 'mark_as_read';
	case FORWARD_MESSAGE = 'forward_message';
	case COPY_MESSAGE = 'copy_message';
	case PIN_MESSAGE = 'pin_message';
	case UNPIN_MESSAGE = 'unpin_message';

	// Account / bot
	case GET_ME = 'get_me';
	case GET_ACCOUNT_INFO = 'get_account_info';
	case GET_PHONE_NUMBERS = 'get_phone_numbers';
	case GET_USER_PROFILE = 'get_user_profile';
	case GET_TEMPLATES = 'get_templates';
	case SET_MY_COMMANDS = 'set_my_commands';
	case GET_MY_COMMANDS = 'get_my_commands';
	case DELETE_MY_COMMANDS = 'delete_my_commands';

	// Chat administration
	case GET_CHAT = 'get_chat';
	case GET_CHAT_MEMBER = 'get_chat_member';
	case GET_CHAT_ADMINISTRATORS = 'get_chat_administrators';
	case GET_CHAT_MEMBER_COUNT = 'get_chat_member_count';
	case SET_CHAT_TITLE = 'set_chat_title';
	case SET_CHAT_DESCRIPTION = 'set_chat_description';
	case BAN_CHAT_MEMBER = 'ban_chat_member';
	case UNBAN_CHAT_MEMBER = 'unban_chat_member';
	case LEAVE_CHAT = 'leave_chat';

	// Webhooks / updates
	case SET_WEBHOOK = 'set_webhook';
	case DELETE_WEBHOOK = 'delete_webhook';
	case GET_WEBHOOK_INFO = 'get_webhook_info';
	case GET_UPDATES = 'get_updates';
	case ANSWER_CALLBACK_QUERY = 'answer_callback_query';
	case ANSWER_INLINE_QUERY = 'answer_inline_query';

	// Files
	case GET_FILE = 'get_file';
	case UPLOAD_MEDIA = 'upload_media';
	case GET_MEDIA = 'get_media';
	case DELETE_MEDIA = 'delete_media';

	// SMS specific
	case SEND_OTP = 'send_otp';
	case SEND_BULK = 'send_bulk';
	case SEND_TEMPLATE_MESSAGES = 'send_template_messages';
	case GET_BALANCE = 'get_balance';
	case GET_REPORT = 'get_report';
	case GET_STATS = 'get_stats';
	case READ_INBOX = 'read_inbox';
	case LIST_SENDERS = 'list_senders';
	case LIST_TEMPLATES = 'list_templates';
	case CANCEL_JOB = 'cancel_job';
	case MEASURE_LENGTH = 'measure_length';
}

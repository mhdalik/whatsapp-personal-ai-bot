<?php

namespace App\Http\Controllers;

use App\Helpers\WhatsAppHelper;
use App\Models\WhatsAppMessage;
use App\Services\BotActionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class WhatsAppWebhookController extends Controller
{
    // dont need to touch this function in future, it is for webhook verification
    public function verify(Request $request)
    {
        info('Received WhatsApp Webhook Verification Request');
        info($request);

        $mode = $request->query('hub_mode');
        $token = $request->query('hub_verify_token');
        $challenge = $request->query('hub_challenge');

        if ($mode === 'subscribe' && $token === env('WHATSAPP_VERIFY_TOKEN')) {
            // Respond with the plain-text challenge string and 200 OK
            return response($challenge, 200)->header('Content-Type', 'text/plain');
        }

        return response('Forbidden', 403);
    }

    /**
     * Handle incoming webhook events from WhatsApp (POST)
     */
    public function handle(Request $request)
    {
        if (!WhatsAppHelper::isValidWebhookSignature($request)) {
            Log::warning('WhatsApp Webhook rejected: invalid signature');
            return response('Forbidden', 403);
        }

        $ignoreMessages = ['hello', 'hi'];

        // Parse incoming WhatsApp payload
        $entry   = $request->input('entry.0.changes.0.value');
        $message = $entry['messages'][0] ?? null;

        if (!$message) return response('EVENT_RECEIVED', 200);

        // ingnoring webhook retries, if the message already exists in the database
        if (!empty($message['id']) && WhatsAppMessage::where('message_id', $message['id'])->exists()) {
            return response('EVENT_RECEIVED', 200);
        }

        $from = $message['from'];
        $type = $message['type'];

        $messageTextBody = WhatsAppHelper::getWhatsAppMessageTextBody($request);

        // 3. dump/store all message webhook to a table for future references
        WhatsAppMessage::storeFromWebhook($request);


        // 1. ignore message from unknown numbers
        if ($from != env('ALLOW_MESSAGE_ONLY_FROM')) {
            info("Ignoring message from {$from} as it does not match the allowed sender.");
            return response('EVENT_RECEIVED', 200);
        }


        if (in_array(strtolower($message['text']['body'] ?? ''), $ignoreMessages)) {
            info("Ignoring message from {$from} as it is in the ignore list.");
            return response('EVENT_RECEIVED', 200);
        }

        WhatsAppHelper::forwardWhatsAppWebhookMessageToTelegram($request);

        // Ignore response and actionmessages older than 1 hour (e.g. offline device reconnection or webhook retries)
        // not tested
        $msgTimestamp = (int) ($message['timestamp'] ?? 0);
        if ($msgTimestamp > 0 && (now()->timestamp - $msgTimestamp) > 3600) {
            info("Ignoring old message from {$from} sent at timestamp {$msgTimestamp} (> 1 hour old).");
            return response('EVENT_RECEIVED', 200);
        }


        // 2. send message to telegrame (text/media) & process AI chatbot actions
        // todo: handle other types of messages (location, contacts, stickers, etc.)
        // todo: add timstamp to message if the webhook failed and get callback after delay...
        // 2.1. Text Messages


        $buttonId = $message['interactive']['button_reply']['id'] ?? ($message['interactive']['list_reply']['id'] ?? null);

        if (($type === 'text' || $type === 'interactive') && ($messageTextBody || $buttonId)) {
            // return response('EVENT_RECEIVED', 200);

            // Process AI Action (addReminder, addTransaction, or chat) or button clicks
            try {
                $userMsgId = $message['id'] ?? null;
                $reply = BotActionService::handleUserMessage($messageTextBody ?? '', $from, $buttonId);

                if (!empty($reply)) {
                    if (is_array($reply) && !empty($reply['reaction'])) {
                        if ($userMsgId) {
                            WhatsAppHelper::sendReaction($from, $userMsgId, $reply['reaction']);
                        }
                    } else if (is_array($reply) && !empty($reply['buttons'])) {
                        WhatsAppHelper::sendInteractiveMessage($from, $reply['text'], $reply['buttons'], null, $userMsgId, $reply['footer'] ?? null);
                    } else {
                        $text = is_array($reply) ? ($reply['text'] ?? '') : $reply;

                        if (!empty($text)) WhatsAppHelper::sendMessage($from, $text, null, $userMsgId);
                    }
                }
            } catch (\Throwable $e) {
                Log::error('Error processing user/interactive message with BotActionService: ' . $e->getMessage(), ['exception' => $e]);
            }
        } else if (in_array($type, ['image', 'video', 'audio', 'document'])) {
            // 2.2. Media Messages (image, video, audio, document)
            // do nothing medai with ai, do in future

        } else {
            if ($type != 'contacts' || $type != 'location' || $type != 'reaction') info('Edge cases type: ' . $type);
        }

        return response('EVENT_RECEIVED', 200);
    }
}

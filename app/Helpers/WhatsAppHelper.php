<?php

namespace App\Helpers;

use App\Models\OutgoingWhatsappMessage;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class WhatsAppHelper
{
    /**
     * Send a text message via WhatsApp Cloud API.
     */
    public static function sendMessage(string $to, string $text, ?string $phoneNumberId = null, ?string $replyToMessageId = null): bool
    {
        //todo: imp: store all send messages to a table
        // todo: check how many meesages sent this month, is lessthan 1k...
        // todo: validate $text nullness , legth...
        try {
            $token = env('WHATSAPP_TOKEN');
            $phoneId = $phoneNumberId ?: env('WHATSAPP_PHONE_NUMBER_ID');

            if (!$token || !$phoneId || !$to) {
                Log::warning('WhatsApp sendMessage skipped: missing token, phoneId, or recipient', [
                    'to' => $to,
                    'phoneId' => $phoneId,
                ]);
                return false;
            }

            $url = "https://graph.facebook.com/v25.0/{$phoneId}/messages";

            $payload = [
                'messaging_product' => 'whatsapp',
                'recipient_type'    => 'individual',
                'to'                => $to,
                'type'              => 'text',
                'text'              => [
                    'preview_url' => false,
                    'body'        => $text,
                ],
            ];

            if ($replyToMessageId) {
                $payload['context'] = ['message_id' => $replyToMessageId];
            }

            $response = Http::withToken($token)->post($url, $payload);

            if ($response->successful()) {
                OutgoingWhatsappMessage::create([
                    'to'      => $to,
                    'message' => $text,
                    'type'    => 'text',
                    'sent_at' => now(),
                    'meta'    => $response->json(),
                ]);
                return true;
            }

            Log::error('WhatsApp sendMessage failed: ' . $response->body());
            return false;
        } catch (\Throwable $e) {
            Log::error('Exception in WhatsApp sendMessage: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Send an interactive button message to WhatsApp (e.g. for the 24-hr session window keeper).
     *
     * @param string $to
     * @param string $bodyText
     * @param array $buttons Array of ['id' => 'button_id', 'title' => 'Button Title'] (max 3 buttons)
     * @param string|null $phoneNumberId
     * @param string|null $replyToMessageId
     * @param string|null $footerText Optional footer text (max 60 chars)
     * @return bool
     */
    public static function sendInteractiveMessage(string $to, string $bodyText, array $buttons, ?string $phoneNumberId = null, ?string $replyToMessageId = null, ?string $footerText = null): bool
    {
        try {
            $token = env('WHATSAPP_TOKEN');
            $phoneId = $phoneNumberId ?: env('WHATSAPP_PHONE_NUMBER_ID');

            if (!$token || !$phoneId || !$to) return false;

            $formattedButtons = [];
            foreach (array_slice($buttons, 0, 3) as $btn) {
                $formattedButtons[] = [
                    'type'  => 'reply',
                    'reply' => [
                        'id'    => (string) ($btn['id'] ?? 'btn'),
                        'title' => mb_substr($btn['title'] ?? 'Hello', 0, 20),
                    ],
                ];
            }

            $payload = [
                'messaging_product' => 'whatsapp',
                'recipient_type'    => 'individual',
                'to'                => $to,
                'type'              => 'interactive',
                'interactive'       => [
                    'type'   => 'button',
                    'body'   => [
                        'text' => $bodyText,
                    ],
                    'action' => [
                        'buttons' => $formattedButtons,
                    ],
                ],
            ];

            if (!empty($footerText)) {
                $payload['interactive']['footer'] = ['text' => mb_substr($footerText, 0, 60)];
            }

            if ($replyToMessageId) {
                $payload['context'] = ['message_id' => $replyToMessageId];
            }

            $url = "https://graph.facebook.com/v25.0/{$phoneId}/messages";
            $response = Http::withToken($token)->post($url, $payload);

            if ($response->successful()) {
                OutgoingWhatsappMessage::create([
                    'to'      => $to,
                    'message' => $bodyText,
                    'type'    => 'interactive',
                    'sent_at' => now(),
                    'meta'    => $response->json(),
                ]);
                return true;
            }

            Log::error('WhatsApp sendInteractiveMessage failed: ' . $response->body());
            // Fallback to plain text if interactive fails
            return static::sendMessage($to, $bodyText, $phoneNumberId, $replyToMessageId);
        } catch (\Throwable $e) {
            Log::error('Exception in sendInteractiveMessage: ' . $e->getMessage());
            return static::sendMessage($to, $bodyText, $phoneNumberId, $replyToMessageId);
        }
    }

    /**
     * Send an emoji reaction to a specific WhatsApp message.
     */
    public static function sendReaction(string $to, string $messageId, string $emoji = '✅'): bool
    {
        try {
            $token = env('WHATSAPP_TOKEN');
            $phoneId = env('WHATSAPP_PHONE_NUMBER_ID');

            if (!$token || !$phoneId || !$to || !$messageId) return false;

            $url = "https://graph.facebook.com/v25.0/{$phoneId}/messages";

            $payload = [
                'messaging_product' => 'whatsapp',
                'recipient_type'    => 'individual',
                'to'                => $to,
                'type'              => 'reaction',
                'reaction'          => [
                    'message_id' => $messageId,
                    'emoji'      => $emoji,
                ],
            ];

            $response = Http::withToken($token)->post($url, $payload);

            if ($response->successful()) {
                OutgoingWhatsappMessage::create([
                    'to'      => $to,
                    'message' => $emoji,
                    'type'    => 'reaction',
                    'sent_at' => now(),
                    'meta'    => $response->json(),
                ]);
                return true;
            }

            Log::error('WhatsApp sendReaction failed: ' . $response->body());
            return false;
        } catch (\Throwable $e) {
            Log::error('Exception in WhatsApp sendReaction: ' . $e->getMessage());
            return false;
        }
    }



    /**
     * Verify that incoming webhook is authentically from Meta using HMAC-SHA256.
     */
    public static function isValidWebhookSignature(Request $request): bool
    {
        $appSecret = env('WHATSAPP_APP_SECRET');
        if (empty($appSecret)) return true; // Allow through if secret is not configured in .env

        $signature = $request->header('X-Hub-Signature-256');
        if (!$signature || !str_starts_with($signature, 'sha256=')) return false;

        $expected = 'sha256=' . hash_hmac('sha256', $request->getContent(), $appSecret);
        return hash_equals($expected, $signature);
    }

    /**
     * Extract user-typed text or caption from a WhatsApp webhook.
     * Returns null if no text or caption is present (e.g. sticker, plain audio/image without caption).
     *
     * @param Request|array $request
     * @return string|null
     */
    public static function getWhatsAppMessageTextBody(Request $request): ?string
    {
        $payload = $request instanceof Request ? $request->all() : $request;
        $message = data_get($payload, 'entry.0.changes.0.value.messages.0');

        if (!$message) return null;

        $type = $message['type'] ?? null;

        $content = match ($type) {
            // Plain text message
            'text' => $message['text']['body'] ?? null,

            // Media captions (returns null if sent without a caption)
            'image', 'video', 'document' => !empty($message[$type]['caption'])
                ? $message[$type]['caption']
                : null,

            // Interactive button or list replies
            'interactive' => $message['interactive']['button_reply']['title']
                ?? $message['interactive']['list_reply']['title']
                ?? null,
            // $buttonId = $message['interactive']['button_reply']['id'] ?? ($message['interactive']['list_reply']['id'] ?? '');


            // Quick-reply button
            'button' => $message['button']['text'] ?? null,

            // Location text (name or address, otherwise null)
            'location' => trim(($message['location']['name'] ?? '') . ' ' . ($message['location']['address'] ?? '')) ?: null,

            // Stickers, voice notes, audio, reactions, unsupported types without text
            default => null,
        };

        return !empty($content) ? trim($content) : null;
    }






    // unused
    /**
     * Forward any incoming WhatsApp webhook message to Telegram.
     *
     * @param Request|array $request
     * @return bool
     */
    public static function forwardWhatsAppWebhookMessageToTelegram(Request|array $request): bool
    {
        try {
            $telegramBotToken = env('TELEGRAM_BOT_TOKEN');
            $telegramChatId   = env('TELEGRAM_CHAT_ID');
            $waToken          = env('WHATSAPP_TOKEN');


            if (!$telegramBotToken || !$telegramChatId) {
                Log::warning('Telegram forwarding skipped: missing TELEGRAM_BOT_TOKEN or TELEGRAM_CHAT_ID.');
                return false;
            }

            // Extract message and entry data
            $payload = $request instanceof Request ? $request->all() : $request;
            $entry   = data_get($payload, 'entry.0.changes.0.value');
            $message = data_get($entry, 'messages.0');
            if (!$message) {
                return false;
            }
            $type     = $message['type'] ?? 'unknown';
            $baseUri  = "https://api.telegram.org/bot{$telegramBotToken}";
            // Handle topic-enabled Telegram groups (e.g., "-100123456_99")
            $chatId = $telegramChatId;
            $threadId = null;
            if (str_contains($chatId, '_')) {
                [$chatId, $threadId] = explode('_', $chatId, 2);
            }
            // Default params helper
            $baseParams = array_filter([
                'chat_id'           => $chatId,
                'message_thread_id' => $threadId,
            ]);

            // Append short 12-hour datetime if delayed by more than 10 minutes (600 seconds)
            $msgTimestamp = (int) ($message['timestamp'] ?? 0);
            $timeSuffix = '';
            if ($msgTimestamp > 0 && abs(time() - $msgTimestamp) > 600) {
                $dt = Carbon::createFromTimestamp($msgTimestamp)->timezone(config('app.timezone', 'Asia/Kolkata'));
                $formattedTime = $dt->format('d M, h:i A');
                $timeSuffix = "\n\n(" . $formattedTime . ")";
            }

            // ==========================================
            // 1. TEXT MESSAGES
            // ==========================================
            if ($type === 'text') {
                $text = ($message['text']['body'] ?? '') . $timeSuffix;
                $response = Http::post("{$baseUri}/sendMessage", array_merge($baseParams, [
                    'text'       => $text,
                    'parse_mode' => 'HTML',
                ]));
                return $response->successful();
            }
            // ==========================================
            // 2. INTERACTIVE BUTTON / LIST REPLIES
            // ==========================================
            if ($type === 'interactive') {
                $title = $message['interactive']['button_reply']['title']
                    ?? $message['interactive']['list_reply']['title']
                    ?? ($message['interactive']['button_reply']['id'] ?? '');
                $response = Http::post("{$baseUri}/sendMessage", array_merge($baseParams, [
                    'text' => "🔘 <i>Clicked:</i> <b>{$title}</b>",
                    'parse_mode' => 'HTML',
                ]));
                return $response->successful();
            }
            // ==========================================
            // 3. MEDIA (Image, Video, Audio, Voice, Document, Sticker)
            // ==========================================
            if (in_array($type, ['image', 'video', 'audio', 'document', 'sticker'])) {
                $mediaId = $message[$type]['id'] ?? null;
                $caption = $message[$type]['caption'] ?? '';
                if (!empty($caption) && !empty($timeSuffix)) {
                    $caption .= $timeSuffix;
                }
                if (!$mediaId || !$waToken) {
                    return false;
                }
                // Step A: Get temporary download URL from WhatsApp Graph API
                $metaRes = Http::withToken($waToken)
                    ->get("https://graph.facebook.com/v25.0/{$mediaId}")
                    ->json();
                $downloadUrl = $metaRes['url'] ?? null;
                if (!$downloadUrl) {
                    Log::error("Failed to retrieve media URL from WhatsApp for ID: {$mediaId}");
                    return false;
                }
                // Step B: Download binary file stream
                $fileContent = Http::withToken($waToken)->get($downloadUrl)->body();
                // Step C: Map WhatsApp type to Telegram API method & field
                $isVoice = ($type === 'audio' && ($message['audio']['voice'] ?? false));
                $method = match ($type) {
                    'image'    => 'sendPhoto',
                    'video'    => 'sendVideo',
                    'audio'    => $isVoice ? 'sendVoice' : 'sendAudio',
                    'sticker'  => 'sendSticker',
                    default    => 'sendDocument',
                };
                $field = match ($type) {
                    'image'    => 'photo',
                    'video'    => 'video',
                    'audio'    => $isVoice ? 'voice' : 'audio',
                    'sticker'  => 'sticker',
                    default    => 'document',
                };
                $filename = $message[$type]['filename'] ?? "media_{$mediaId}";
                // Step D: Multipart upload to Telegram
                $postData = array_merge($baseParams, array_filter([
                    'caption' => $caption ?: null,
                ]));
                $response = Http::attach($field, $fileContent, $filename)
                    ->post("{$baseUri}/{$method}", $postData);
                return $response->successful();
            }
            // ==========================================
            // 4. LOCATION
            // ==========================================
            if ($type === 'location') {
                $latitude  = $message['location']['latitude'];
                $longitude = $message['location']['longitude'];
                $name      = $message['location']['name'] ?? '';
                $address   = $message['location']['address'] ?? '';
                // Send map coordinates
                Http::post("{$baseUri}/sendLocation", array_merge($baseParams, [
                    'latitude'  => $latitude,
                    'longitude' => $longitude,
                ]));
                // Send venue name/address if available
                if ($name || $address) {
                    Http::post("{$baseUri}/sendMessage", array_merge($baseParams, [
                        'text' => "📍 <b>" . e($name) . "</b>\n" . e($address),
                        'parse_mode' => 'HTML',
                    ]));
                }
                return true;
            }
            // ==========================================
            // 5. CONTACT CARDS
            // ==========================================
            if ($type === 'contacts') {
                $contact   = $message['contacts'][0] ?? [];
                $name      = $contact['name']['formatted_name'] ?? 'Contact';
                $phoneNum  = $contact['phones'][0]['phone'] ?? '';
                $response = Http::post("{$baseUri}/sendContact", array_merge($baseParams, [
                    'phone_number' => $phoneNum,
                    'first_name'   => $name,
                ]));
                return $response->successful();
            }
            // ==========================================
            // 6. REACTIONS
            // ==========================================
            if ($type === 'reaction') {
                $emoji = $message['reaction']['emoji'] ?? '';
                if ($emoji) {
                    Http::post("{$baseUri}/sendMessage", array_merge($baseParams, [
                        'text' => "Reacted with {$emoji}",
                    ]));
                }
                return true;
            }
            // Fallback for unhandled edge cases
            Log::info("Unhandled WhatsApp message type: {$type}");
            return false;
        } catch (\Throwable $e) {
            Log::error('TelegramHelper::forwardWhatsAppWebhookMessageToTelegram Exception: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString(),
            ]);
            return false;
        }
    }
}

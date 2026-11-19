<?php

namespace App\Helpers;

use Illuminate\Support\Facades\Http;

class TelegramHelper
{

    public static function sendMessage($chatId, $text, $inlineButtons = [], $buttons = [])
    {
        try {
            if (!$chatId) return;
            $botToken = env('TELEGRAM_BOT_TOKEN');
            $url = "https://api.telegram.org/bot$botToken/sendMessage";

            $payload = ['chat_id' => $chatId, 'text' => $text, 'parse_mode' => 'HTML'];
            // (for topic enabled groups) if the chatId containunderscore and a number after it, parse it and pass it as message_thread_id to payload
            if (strpos($chatId, '_') !== false) {
                $parts = explode('_', $chatId);
                if ($parts[1]) $payload['message_thread_id'] = $parts[1];
            }

            if (!empty($inlineButtons)) { // inline keyboard
                $payload['reply_markup'] = json_encode(['inline_keyboard' => $inlineButtons]);
            } else if (!empty($buttons)) { // reply keyboard
                $payload['reply_markup'] = json_encode([
                    'keyboard' => $buttons,
                    'resize_keyboard' => true,
                    'one_time_keyboard' => true
                ]);
            }

            $response = Http::post($url, $payload);
            if ($response->json('ok') != true) {
                info("Telegram sendMessage failed for chatId: $chatId. Response: " . $response->body());
            }
        } catch (\Exception $e) {
            info("Telegram sendMessage error for chatId: $chatId: " . $e->getMessage());
        }
    }

    public static function sendAttachment($chatId, $imageUrl, $caption = '')
    {
        $botToken = env('TELEGRAM_BOT_TOKEN');
        $url = "https://api.telegram.org/bot$botToken/sendPhoto";

        Http::post($url, [
            'chat_id' => $chatId,
            'photo' => $imageUrl,
            'caption' => $caption,
        ]);
    }
}

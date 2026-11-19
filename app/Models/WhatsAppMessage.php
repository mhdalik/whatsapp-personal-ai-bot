<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class WhatsAppMessage extends Model
{
    use HasFactory;

    protected $table = 'whatsapp_messages';

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $guarded = [];

    protected function casts(): array
    {
        return ['datetime' => 'datetime', 'payload' => 'array'];
    }

    /**
     * Store incoming WhatsApp webhook payload into the database.
     *
     * @param Request|array $request
     * @param array|null $message
     * @param array|null $entry
     * @return self|null
     */
    public static function storeFromWebhook(Request|array $request, ?array $message = null, ?array $entry = null): ?self
    {
        try {
            $payload = $request instanceof Request ? $request->all() : $request;

            if ($entry === null) $entry = data_get($payload, 'entry.0.changes.0.value', []);
            if ($message === null) $message = data_get($entry, 'messages.0');
            if (!$message) return null;

            $type = $message['type'] ?? 'unknown';
            $from = $message['from'] ?? null;

            $messageBody = match ($type) {
                'text' => $message['text']['body'] ?? null,
                'image', 'video', 'audio', 'document' => $message[$type]['caption'] ?? null,
                'location' => trim(($message['location']['name'] ?? '') . ' ' . ($message['location']['address'] ?? '')) ?: null,
                'interactive' => $message['interactive']['button_reply']['title'] ?? ($message['interactive']['list_reply']['title'] ?? null),
                default => null,
            };

            $mediaId = in_array($type, ['image', 'video', 'audio', 'document', 'sticker'])
                ? ($message[$type]['id'] ?? null)
                : null;

            $mimeType = in_array($type, ['image', 'video', 'audio', 'document', 'sticker'])
                ? ($message[$type]['mime_type'] ?? null)
                : null;

            $caption = in_array($type, ['image', 'video', 'audio', 'document'])
                ? ($message[$type]['caption'] ?? null)
                : null;

            $messageData = [
                'from'                 => $from,
                'message'              => $messageBody,
                'sender_name'          => $entry['contacts'][0]['profile']['name'] ?? null,
                'phone_number_id'      => $entry['metadata']['phone_number_id'] ?? null,
                'display_phone_number' => $entry['metadata']['display_phone_number'] ?? null,
                'type'                 => $type,
                'caption'              => $caption,
                'media_id'             => $mediaId,
                'mime_type'            => $mimeType,
                'datetime'             => !empty($message['timestamp']) ? Carbon::createFromTimestamp((int) $message['timestamp']) : now(),
                'payload'              => $payload,
                'timestamp'            => $message['timestamp'] ?? null,
            ];

            if (!empty($message['id'])) {
                return static::updateOrCreate(['message_id' => $message['id']], $messageData);
            }

            return static::create($messageData);
        } catch (\Throwable $e) {
            Log::error('Failed to store WhatsApp message to database: ' . $e->getMessage(), [
                'exception' => $e,
            ]);

            return null;
        }
    }

    public static function getLastMessageTimeAgo()
    {
        $lastUserMsg = WhatsAppMessage::orderByRaw('CAST(`timestamp` AS UNSIGNED) DESC')->first();
        $footerText = null;

        if ($lastUserMsg) {
            $minutes = (int) Carbon::createFromTimestamp((int) $lastUserMsg->timestamp, 'Asia/Kolkata')->diffInMinutes(now('Asia/Kolkata'));
            $footerText = 'Last message: ' . ($minutes < 60 ? "{$minutes}m ago" : intdiv($minutes, 60) . 'h ' . ($minutes % 60) . 'm ago');
        }
        return $footerText;
    }
}

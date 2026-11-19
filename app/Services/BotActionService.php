<?php

namespace App\Services;


use App\Models\AiModelLog;
use App\Models\Reminder;
use App\Models\Transaction;
use Carbon\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class BotActionService
{
    public static function parseUserIntent(string $userMessage): ?array
    {
        $apiKey = env('GEMINI_API_KEY');
        if (empty($apiKey)) {
            Log::warning('GeminiActionService: GEMINI_API_KEY is not set in environment.');
            return null;
        }

        $models = ['gemini-3.1-flash-lite', 'gemini-3.5-flash', 'gemini-3.6-flash', 'gemini-3.8-flash']; // order of preference

        // $model = config('services.gemini.model', env('GEMINI_MODEL', 'gemini-3.8-flash')); // long time // cURL error 28: Operation timed out after 60005 milliseconds with 0 bytes receive (2)
        // $model = config('services.gemini.model', env('GEMINI_MODEL', 'gemini-3.1-flash-lite')); // working night good, low latency
        // $model = config('services.gemini.model', env('GEMINI_MODEL', 'gemini-3.5-flash')); // working morning good, no at night (onnly secod time 1f,1s,1f... at night), low latency (ggl says depricated, will forward to gemini-3.6-flash)
        // $model = config('services.gemini.model', env('GEMINI_MODEL', 'gemini-3.5-flash-lite')); // Request contains an invalid argument
        // $model = config('services.gemini.model', env('GEMINI_MODEL', 'gemini-3.6-flash')); // working at night/not working, morning not checked, night slow again: Operation timed out after 60010 millisecond,
        // $url = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent";

        $nowIst = Carbon::now('Asia/Kolkata');
        $currentTimeContext = $nowIst->toIso8601String() . " (" . $nowIst->format('l, d F Y, h:i A') . " IST, Asia/Kolkata)";

        $systemInstruction = "You are an intelligent personal assistant WhatsApp bot. "
            . "The current Indian Standard Time (IST, Asia/Kolkata) is: {$currentTimeContext}.\n"
            . "The user may send messages with typos, spelling errors, grammatical mistakes, abbreviations, or informal phrasing.\n"
            . "if anything about date and time present in message, consider it timezone as IST Asia/Kolkata.\n"
            . "All date values must use YYYY-MM-DD format, and all datetime values must use YYYY-MM-DD HH:MM:SS (24-hour format) in Asia/Kolkata (IST, UTC+05:30), without timezone identifiers or UTC offsets; return null if unavailable.\n"
            . "use human readable 12 hr date time format for chat_params.message\n\n"
            . "Your job is to understand the user's intent and return a strictly structured JSON response mapping to one of the predefined actions:\n\n"
            . "ACTIONS:\n"
            . "1. 'addReminder': When user wants to set a reminder or task alert (one-off or recurring).\n"
            . "   - E.g. 'remind to wash pillow every week saturday evening' -> cron_rule: '0 18 * * 6'\n"
            . "   - E.g. 'remind me to call mom tomorrow at 10am' -> start_at: datetime for tomorrow 10:00 AM IST, cron_rule: null\n"
            . "   - E.g. 'remind everyday to take medicine at 9pm' -> cron_rule: '0 21 * * *'\n"
            . "   - Always use standard 5-part cron format (minute hour dom month dow) in 24-hr IST format (0 = Sunday, 6 = Saturday).\n\n"
            . "2. 'addTransaction': When user mentions an expense, spending, income, lend or borrow.\n"
            . "   - Normal transaction: update income or expense, and set is_loan to false. E.g. 'spent 250 on lunch' -> expense: 250, income: 0, is_loan: false, description: 'Lunch'\n"
            . "   - If user LENDS money to someone: set expense as the amount, set is_loan to true, and add the person name and details in the description column. E.g. 'gave 1000 to Rahul' or 'Rahul borrowed 1000 from me' -> expense: 1000, income: 0, is_loan: true, description: 'Lent to Rahul'\n"
            . "   - If user BORROWS money from someone: set income as the amount, set is_loan to true, and add the person name and details in the description column. E.g. 'borrowed 500 from Amit' -> income: 500, expense: 0, is_loan: true, description: 'Borrowed from Amit'\n"
            . "   - Datetime should default to current datetime if not mentioned seperately.\n\n"
            . "3. 'chat': Use for general conversation, knowledge questions, or when you are strongly unsure which backend action to trigger. Put your reply in chat_params.message.\n"
            // . "   - Prefer making a best-effort decision for actions from the single message. Only fall back to 'chat' if the message is genuinely ambiguous or clearly a question/conversation.\n"
            // . "   - If falling back to 'chat' due to doubt, ask a single short clarifying question in chat_params.message.\n\n"
            . "IMPORTANT: You process each message in isolation with NO conversation history. Never ask follow-up questions after creating an action.";

        $jsonSchema = [
            'type' => 'OBJECT',
            'properties' => [
                'action' => [
                    'type' => 'STRING',
                    'enum' => ['addReminder', 'addTransaction', 'chat'],
                    'description' => 'The action to run',
                ],
                'reminder_params' => [
                    'type' => 'OBJECT',
                    'properties' => [
                        'message'   => ['type' => 'STRING', 'description' => 'Clean reminder text'],
                        'cron_rule' => ['type' => 'STRING', 'description' => '5-part cron rule in 24h IST, or null if one-time', 'nullable' => true],
                        'start_at'  => ['type' => 'STRING', 'description' => 'IST YYYY-MM-DD HH:MM:SS (24 hr format,Asia/Kolkata) for one-time or start datetime if mentioned', 'nullable' => true],
                        'end_at'    => ['type' => 'STRING', 'description' => 'IST YYYY-MM-DD HH:MM:SS (24 hr format,Asia/Kolkata) if reminder expires', 'nullable' => true],
                    ],
                ],
                'transaction_params' => [
                    'type' => 'OBJECT',
                    'properties' => [
                        'datetime'        => ['type' => 'STRING', 'description' => 'YYYY-MM-DD HH:MM:SS'],
                        'description' => ['type' => 'STRING', 'description' => 'Description of transaction (include person name if available and details)'],
                        'income'      => ['type' => 'NUMBER', 'description' => 'Income amount (or borrowed amount if loan), null if not income'],
                        'expense'     => ['type' => 'NUMBER', 'description' => 'Expense amount (or lent amount if loan), null if not expense'],
                        'is_loan'     => ['type' => 'BOOLEAN', 'description' => 'True if lending to someone or borrowing from someone, false otherwise'],
                    ],
                ],
                'chat_params' => [
                    'type' => 'OBJECT',
                    'properties' => [
                        'message' => ['type' => 'STRING', 'description' => 'The reply message to the user: a confirmation for actions 1-2, or a general conversational response / clarifying question for the chat action.'],
                    ],
                    'required' => ['message'],
                ],
            ],
            'required' => ['action'],
        ];
        $payload = [
            'system_instruction' => [
                'parts' => [
                    ['text' => $systemInstruction],
                ],
            ],
            'contents' => [
                [
                    'role'  => 'user',
                    'parts' => [
                        ['text' => $userMessage],
                    ],
                ],
            ],
            'generationConfig' => [
                'response_mime_type' => 'application/json',
                'response_schema'    => $jsonSchema,
                'thinking_config'    => [
                    'thinking_budget' => 0,
                ],
            ],
        ];

        foreach ($models as $model) {
            $url = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent";
            $startTime = microtime(true);
            try {
                $response = Http::withHeaders([
                    'x-goog-api-key' => $apiKey,
                ])->withOptions(['ip_resolve' => CURL_IPRESOLVE_V4])
                    ->connectTimeout(10)
                    ->timeout(60)
                    ->post($url, $payload);

                $duration = round(microtime(true) - $startTime, 2);

                if (!$response->successful()) {
                    Log::error("Gemini [{$model}] request failed: " . $response->body());
                    self::logAiCall($model, $duration, $payload, $response->json() ?? ['raw' => $response->body()], 'failed', $userMessage);
                    continue; // try next model
                }

                $body = $response->json();
                $rawText = $body['candidates'][0]['content']['parts'][0]['text'] ?? null;

                if (!$rawText) {
                    Log::error("Gemini [{$model}] returned empty text.", ['response' => $body]);
                    self::logAiCall($model, $duration, $payload, $body, 'empty_response', $userMessage);
                    continue; // try next model
                }

                $parsed = json_decode($rawText, true);
                if (!is_array($parsed)) {
                    Log::error("Gemini [{$model}] failed to json_decode response text: " . $rawText);
                    self::logAiCall($model, $duration, $payload, ['raw_text' => $rawText, 'body' => $body], 'invalid_json', $userMessage);
                    continue; // try next model
                }

                self::logAiCall($model, $duration, $payload, $body, 'success', $userMessage);

                return $parsed; // success — return immediately
            } catch (\Throwable $e) {
                $duration = round(microtime(true) - $startTime, 2);
                Log::error("Gemini [{$model}] exception: " . $e->getMessage());
                self::logAiCall($model, $duration, $payload, ['error' => $e->getMessage()], 'exception', $userMessage);
                // continue to next model
            }
        }

        // All models exhausted without a valid response
        Log::error('All Gemini models failed for message: ' . $userMessage);
        return null;
    }

    /**
     * Safely log AI model API call to database without breaking the execution flow.
     */
    private static function logAiCall(string $model, float $duration, array $request, ?array $response, string $status, string $message = null): void
    {
        try {
            AiModelLog::create([
                'model_name'            => $model,
                'response_time_seconds' => $duration,
                'request'               => $request,
                'response'              => $response,
                'status'                => $status,
                'user_message'          => $message,
            ]);
        } catch (\Throwable $e) {
            Log::error('Failed to write to AiModelLog: ' . $e->getMessage());
        }
    }


    /**
     * Main entry point to process an incoming user message via Gemini and dispatch the action.
     */
    public static function handleUserMessage(string $userMessage, string $phone, ?string $buttonId = null): array|string
    {
        $userMessage = trim($userMessage);
        $buttonToCheck = $buttonId ?: '';

        // Check interactive button clicks or quick replies
        if ($buttonToCheck) {
            if (strtolower($buttonToCheck) === 'keep_window_active_hello') {
                return ""; // Do not send any response, just resets the 24-hr customer service window
            }

            if (str_starts_with($buttonToCheck, 'cancel_reminder_')) {
                $id = (int) str_replace('cancel_reminder_', '', $buttonToCheck);
                $reminder = Reminder::find($id);
                if ($reminder) {
                    $reminder->update(['status' => 'inactive']);
                    return ['reaction' => '✅'];
                }
                return "Reminder not found or already deactivated.";
            }

            if (str_starts_with($buttonToCheck, 'active_reminder_')) {
                $id = (int) str_replace('active_reminder_', '', $buttonToCheck);
                $reminder = Reminder::find($id);
                if ($reminder) {
                    $nextRunAt = !empty($reminder->cron_rule)
                        ? Reminder::calculateNextRunAt($reminder->cron_rule, now('Asia/Kolkata'), 'Asia/Kolkata')
                        : now('Asia/Kolkata')->addHour();
                    $reminder->update(['status' => 'active', 'next_run_at' => $nextRunAt]);
                    return ['reaction' => '✅'];
                }
                return "Reminder not found.";
            }

            if (str_starts_with($buttonToCheck, 'delete_transaction_')) {
                $id = (int) str_replace('delete_transaction_', '', $buttonToCheck);
                $transaction = Transaction::find($id);
                if ($transaction) {
                    $transaction->delete();
                    return ['reaction' => '✅'];
                }
                return "Transaction not found or already deleted.";
            }
        }

        // Call Gemini structured AI
        // info("BotActionService: Processing user message via Gemini: {$userMessage}");
        $parsed = self::parseUserIntent($userMessage);

        if (!$parsed) return "Sorry, I had trouble processing your request right now. Please try again in a moment!";

        $action = $parsed['action'] ?? 'chat';

        // Log::info("BotActionService: Intent classified as [{$action}]", ['payload' => $parsed]);

        return match ($action) {
            'addReminder'    => self::addReminder($parsed['reminder_params'] ?? [], $phone, $userMessage),
            'addTransaction' => self::addTransaction($parsed['transaction_params'] ?? [], $phone, $userMessage),
            'chat'           => self::chat($parsed['chat_params'] ?? [], $phone, $userMessage),
            default          => "Hello! You can ask me to set reminders or track transactions, no ai actions taken for non text messages and all user messages are forwarded to telegram for backup",
        };
    }

    /**
     * Action: Add reminder (one-off or recurring cron).
     */
    public static function addReminder(array $params, string $phone, ?string $userMessage = null): array
    {
        $message  = $params['message'] ?? 'Reminder';
        $cronRule = !empty($params['cron_rule']) ? trim($params['cron_rule']) : null;
        $startAtStr = $params['start_at'] ?? null;
        $endAtStr   = $params['end_at'] ?? null;

        $startAt = $startAtStr ? Carbon::parse($startAtStr, 'Asia/Kolkata') : null;
        $endAt   = $endAtStr ? Carbon::parse($endAtStr, 'Asia/Kolkata') : null;

        // Calculate next_run_at
        if (!empty($cronRule)) {
            $nextRunAt = Reminder::calculateNextRunAt($cronRule, $startAt ?: Carbon::now('Asia/Kolkata'), 'Asia/Kolkata');
        } elseif ($startAt) {
            $nextRunAt = $startAt;
        } else {
            $nextRunAt = Carbon::now('Asia/Kolkata')->addHour();
        }

        $reminder = Reminder::create([
            'phone'       => $phone,
            'message'     => $message,
            'cron_rule'   => $cronRule,
            'function'    => 'sendMessage',
            'next_run_at' => $nextRunAt,
            'start_at'    => $startAt,
            'end_at'      => $endAt,
            'status'      => 'active',
            'original_message' => $userMessage ?? '',
            'meta'        => $params,
        ]);
        // todo: handle creation fail

        $nextRunFormatted = $nextRunAt ? $nextRunAt->timezone('Asia/Kolkata')->format('l, d M Y \a\t h:i A') : 'soon';
        $replyText = $cronRule
            ? "⏰ *Reminder Scheduled!*\n\n"
            . "📌 *Task*: {$message}\n"
            . "🔄 *Schedule*: `{$cronRule}`\n"
            . "⏳ *Next occurrence*: {$nextRunFormatted}"
            : "⏰ *Reminder Scheduled!*\n\n"
            . "📌 *Task*: {$message}\n"
            . "⏳ *Trigger time*: {$nextRunFormatted}";

        return [
            'text'    => $replyText,
            'buttons' => [
                ['title' => 'Deactive', 'id' => 'cancel_reminder_' . $reminder->id],
                ['title' => 'Re Activate', 'id' => 'active_reminder_' . $reminder->id],
            ],
        ];
    }

    /**
     * Action: Add financial transaction (income, expense, or loan).
     */
    public static function addTransaction(array $params, string $phone, ?string $original_message = null): array
    {
        $date = !empty($params['datetime']) ? $params['datetime'] : Carbon::now('Asia/Kolkata')->toDateString();
        $description = !empty($params['description']) ? trim($params['description']) : 'General Transaction';
        $income = !empty($params['income']) ? (float) $params['income'] : 0.0;
        $expense = !empty($params['expense']) ? (float) $params['expense'] : 0.0;
        $isLoan = !empty($params['is_loan']) ? (bool) $params['is_loan'] : false;

        $transaction = Transaction::create([
            'phone'       => $phone,
            'datetime'    => $date,
            'description' => $description,
            'income'      => $income,
            'expense'     => $expense,
            'is_loan'     => $isLoan,
            'meta'        => $params,
            'original_message' => $original_message ?? '',
        ]);

        $formattedDate = Carbon::parse($date)->format('d M Y');
        if ($isLoan) {
            $replyText = ($expense > 0)
                ? "🤝 *Loan Given (Lent)!*\n\n"
                . "💰 *Amount*: ₹" . number_format($expense, 2) . "\n"
                . "📝 *Details*: {$description}\n"
                . "📅 *Date*: {$formattedDate}"
                : "🤝 *Loan Taken (Borrowed)!*\n\n"
                . "💰 *Amount*: ₹" . number_format($income, 2) . "\n"
                . "📝 *Details*: {$description}\n"
                . "📅 *Date*: {$formattedDate}";
        } elseif ($income > 0) {
            $replyText = "💵 *Income Recorded!*\n\n"
                . "📈 *Amount*: +₹" . number_format($income, 2) . "\n"
                . "📝 *For*: {$description}\n"
                . "📅 *Date*: {$formattedDate}";
        } else {
            $replyText = "💸 *Expense Recorded!*\n\n"
                . "📉 *Amount*: -₹" . number_format($expense, 2) . "\n"
                . "📝 *For*: {$description}\n"
                . "📅 *Date*: {$formattedDate}";
        }

        return [
            'text'    => $replyText,
            'buttons' => [
                ['title' => 'Delete', 'id' => 'delete_transaction_' . $transaction->id],
            ],
        ];
    }

    /**
     * Action: Chat / Clarification response.
     */
    public static function chat(array $params, string $phone, ?string $userMessage = null): string
    {
        $message = $params['message'] ?? null;

        return $message ?: "I'm here to help! You can ask me to set reminders or track transactions.";
    }
}

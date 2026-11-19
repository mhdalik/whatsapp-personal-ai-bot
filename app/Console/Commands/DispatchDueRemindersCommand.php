<?php

namespace App\Console\Commands;

use App\Helpers\WhatsAppHelper;
use App\Models\Reminder;
use App\Models\WhatsAppMessage;
use App\Services\BotActionService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class DispatchDueRemindersCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'reminders:dispatch-due';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Find and dispatch all due reminders in Indian Standard Time (Asia/Kolkata)';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $now = Carbon::now('Asia/Kolkata');
        $this->info("Checking due reminders at {$now->toDateTimeString()} IST...");

        $dueReminders = Reminder::due()->limit(10)->get();

        if ($dueReminders->isEmpty()) {
            $this->line("No due reminders found.");
            return self::SUCCESS;
        }

        $this->info("Found {$dueReminders->count()} due reminder(s). Dispatching...");

        foreach ($dueReminders as $reminder) {
            $fn = $reminder->function;
            $this->line("-> Executing [{$fn}] for reminder ID #{$reminder->id} (phone: {$reminder->phone})");

            try {
                if ($fn == 'sendMessage' || $fn == '') {
                    WhatsAppHelper::sendMessage(env('ALLOW_MESSAGE_ONLY_FROM'), $reminder->message);
                } else {
                    self::$fn($reminder);
                }
            } catch (\Throwable $e) {
                Log::error("Failed to execute reminder #{$reminder->id} [{$fn}]: " . $e->getMessage(), ['exception' => $e]);
            } finally {
                // Advance or deactivate
                $reminder->advanceNextRun();
                $this->line("   ✓ Processed. Next run at: " . ($reminder->next_run_at ? $reminder->next_run_at->toDateTimeString() : 'None (Completed)'));
            }
        }

        return self::SUCCESS;
    }
}

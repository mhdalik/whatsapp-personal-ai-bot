<?php

namespace App\Models;

use Carbon\Carbon;
use Cron\CronExpression;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Reminder extends Model
{
    use HasFactory;

    protected $table = 'reminders';
    protected $guarded  = [];

    protected function casts(): array
    {
        return [
            'next_run_at' => 'datetime',
            'last_run_at' => 'datetime',
            'start_at'    => 'datetime',
            'end_at'      => 'datetime',
            'meta'        => 'array',
        ];
    }

    /**
     * Scope query to active reminders that are due for dispatch.
     */
    public function scopeDue($query)
    {
        $now = Carbon::now('Asia/Kolkata');

        return $query->where('status', 'active')
            ->whereNotNull('next_run_at')
            ->where('next_run_at', '<=', $now)
            // move this logic to the area when next_run_at is calculated, so that we don't have to check start_at and end_at every time
            ->where(fn($q) => $q->whereNull('start_at')->orWhere('start_at', '<=', $now))
            ->where(fn($q) => $q->whereNull('end_at')->orWhere('end_at', '>=', $now));
    }

    /**
     * Calculate next_run_at by inputting cron_rule and last_run_at (or now).
     */
    public static function calculateNextRunAt(?string $cronRule, ?Carbon $fromTime = null, string $timezone = 'Asia/Kolkata'): ?Carbon
    {
        if (empty($cronRule)) return null;

        try {
            $cron = new CronExpression($cronRule);
            $baseTime = $fromTime ? $fromTime->copy()->timezone($timezone) : Carbon::now($timezone);
            $nextDate = $cron->getNextRunDate($baseTime->toDateTimeString(), 0, false, $timezone);

            return Carbon::instance($nextDate)->timezone($timezone);
        } catch (\Throwable $e) {
            info("Error calculating next_run_at for cron [{$cronRule}]: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Advance next_run_at after execution or deactivate if completed.
     */
    public function advanceNextRun(): void
    {
        $now = Carbon::now('Asia/Kolkata');
        $this->last_run_at = $now;

        if (!empty($this->cron_rule)) {
            $next = static::calculateNextRunAt($this->cron_rule, $now);

            // Check if reminder reached end_at
            if ($this->end_at && $next && $next->greaterThan($this->end_at)) {
                $this->status = 'finished';
                $this->next_run_at = null;
            } else {
                $this->next_run_at = $next;
            }
        } else {
            // One-off reminder without cron completed
            $this->status = 'finished';
            $this->next_run_at = null;
        }

        $this->save();
    }
}

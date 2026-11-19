<?php

namespace App\Helpers;

use DateTime;
use Illuminate\Support\Facades\Log;

class DateHelper
{
    public static  function calculateWeeks(int $totalDays)
    {
        try {
        } catch (\Exception $e) {
            Log::error('Error (catch block) occured in fn:--- in DateHelper.php');
            Log::error($e->getMessage());
        }
    }
}

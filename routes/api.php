<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\WhatsAppWebhookController;

Route::get('/wk/wp', [WhatsAppWebhookController::class, 'verify']);
Route::post('/wk/wp', [WhatsAppWebhookController::class, 'handle']);

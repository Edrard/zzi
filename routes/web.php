<?php

use App\Http\Controllers\ZnunyInlineImageController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/admin');

Route::middleware('web')->group(function () {
    Route::get('/znuny/ticket/{ticketId}/article/{articleId}/inline-image/{token}', [ZnunyInlineImageController::class, 'show'])
        ->name('znuny.inline-image.show')
        ->whereNumber(['ticketId', 'articleId'])
        ->where('token', '[a-zA-Z0-9\-_]+');
});

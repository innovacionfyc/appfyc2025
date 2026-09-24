<?php

use App\Http\Controllers\PodcastPublicController;
use Illuminate\Support\Facades\Route;

Route::get('/podcast', [PodcastPublicController::class, 'index'])->name('podcast.index');

Route::get('/podcast/{slug}', [PodcastPublicController::class, 'show'])
    ->where('slug', '[a-z0-9-]+')
    ->name('podcast.show');

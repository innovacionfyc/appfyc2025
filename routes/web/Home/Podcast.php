<?php

use App\Http\Controllers\PodcastPublicController;
use Illuminate\Support\Facades\Route;

Route::get('/podcast', [PodcastPublicController::class, 'index'])->name('podcast.index');

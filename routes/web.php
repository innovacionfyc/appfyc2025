<?php
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\File;
use Laravel\Socialite\Facades\Socialite;

$routesPath = __DIR__ . '/web';
foreach (File::allFiles($routesPath) as $routeFile) {
    require_once $routeFile->getPathname();
}

Route::get('/ping', function () {
    return response()->json(['status' => 'ok']);
});

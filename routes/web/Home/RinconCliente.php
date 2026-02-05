<?php

use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/rincon-del-cliente', function () {
    return Inertia::render('Home/RinconDelCliente');
});

<?php

namespace App\Http\Controllers\Comercial;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;
use Illuminate\Validation\ValidationException;


class ComercialController extends Controller{
 public function show(): Response
    {
        return Inertia::render('Dashboard/Comercial');
    }

}
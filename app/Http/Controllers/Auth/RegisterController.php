<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Inertia\Inertia;
use Illuminate\Support\Facades\Auth;
use Inertia\Response;



class RegisterController extends Controller
{

    public function showRegisterForm(): Response
    {
        return Inertia::render('Auth/Register');
    }


   
}
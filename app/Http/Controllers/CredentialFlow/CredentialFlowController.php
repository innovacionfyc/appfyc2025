<?php

namespace App\Http\Controllers\CredentialFlow;

use App\Http\Controllers\Controller;
use Inertia\Inertia;
use Inertia\Response;

class CredentialFlowController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('CredentialFlow/Inicio');
    }
}

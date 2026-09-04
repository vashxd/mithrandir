<?php

namespace App\Http\Controllers;

use App\Services\HojeService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Tela 1. "O que nao posso deixar passar?"
 */
class HojeController extends Controller
{
    public function index(Request $request, HojeService $hoje): Response
    {
        return Inertia::render('Hoje', [
            'painel' => $hoje->montar(contexto()->advogado()),
        ]);
    }
}

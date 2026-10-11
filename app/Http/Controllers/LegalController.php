<?php

namespace App\Http\Controllers;

use Illuminate\View\View;

/**
 * Conditions d'utilisation et de vente, politique de confidentialité (jibiat-marchand.com).
 */
class LegalController extends Controller
{
    public const VERSION = '11 octobre 2026';

    public function terms(): View
    {
        return view('platform.legal.terms');
    }

    public function privacy(): View
    {
        return view('platform.legal.privacy');
    }
}

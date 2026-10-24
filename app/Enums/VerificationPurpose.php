<?php

namespace App\Enums;

enum VerificationPurpose: string
{
    case Signup = 'signup';
    case Invitation = 'invitation';
    case PasswordReset = 'password_reset';
    case ConsoleLogin = 'console_login';
    // Connexion par WhatsApp, sans mot de passe (gestion d'une boutique, ou plateforme pour retrouver ses boutiques)
    case Login = 'login';
}

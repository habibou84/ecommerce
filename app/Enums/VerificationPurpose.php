<?php

namespace App\Enums;

enum VerificationPurpose: string
{
    case Signup = 'signup';
    case Invitation = 'invitation';
    case PasswordReset = 'password_reset';
    case ConsoleLogin = 'console_login';
}

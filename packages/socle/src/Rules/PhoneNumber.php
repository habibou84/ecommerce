<?php

namespace Jibiat\Socle\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Jibiat\Socle\PhoneNumber as Phone;

class PhoneNumber implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || ! Phone::isValid($value)) {
            $fail('Le champ :attribute doit être un numéro de téléphone valide.');
        }
    }
}

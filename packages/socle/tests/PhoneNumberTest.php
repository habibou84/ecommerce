<?php

namespace Jibiat\Socle\Tests;

use Jibiat\Socle\PhoneNumber;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class PhoneNumberTest extends TestCase
{
    public static function validNumbers(): array
    {
        return [
            'local 10 chiffres' => ['0707070707', '+2250707070707'],
            'local avec espaces' => ['07 07 07 07 07', '+2250707070707'],
            'local avec tirets et points' => ['07-07.07-07-07', '+2250707070707'],
            'indicatif sans plus' => ['2250707070707', '+2250707070707'],
            'indicatif avec plus' => ['+225 07 07 07 07 07', '+2250707070707'],
            'préfixe 00' => ['002250707070707', '+2250707070707'],
            'numéro étranger' => ['+33 6 12 34 56 78', '+33612345678'],
        ];
    }

    #[DataProvider('validNumbers')]
    public function test_it_normalizes_numbers_to_e164(string $input, string $expected): void
    {
        $this->assertSame($expected, PhoneNumber::normalize($input));
    }

    public static function invalidNumbers(): array
    {
        return [
            'vide' => [''],
            'lettres' => ['abc'],
            'trop court' => ['0707'],
            'local à 8 chiffres sans indicatif' => ['07070707'],
            'trop long' => ['+1234567890123456'],
        ];
    }

    #[DataProvider('invalidNumbers')]
    public function test_it_rejects_invalid_numbers(string $input): void
    {
        $this->assertNull(PhoneNumber::normalize($input));
        $this->assertFalse(PhoneNumber::isValid($input));
    }
}

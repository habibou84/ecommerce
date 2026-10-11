<?php

namespace Database\Factories;

use App\Enums\UserStatus;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    protected static ?string $password;

    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            // Mobile ivoirien (07) unique
            'phone' => '+22507'.fake()->unique()->numerify('########'),
            'password' => static::$password ??= Hash::make('password'),
            'phone_verified_at' => now(),
            'status' => UserStatus::Active,
        ];
    }

    public function suspended(): static
    {
        return $this->state(fn () => ['status' => UserStatus::Suspended]);
    }
}

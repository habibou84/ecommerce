<?php

namespace Database\Factories;

use App\Enums\StoreStatus;
use App\Models\Store;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Store>
 */
class StoreFactory extends Factory
{
    public function definition(): array
    {
        return [
            'owner_id' => User::factory(),
            'slug' => fake()->unique()->regexify('[a-z]{6,10}'),
            'name' => fake()->company(),
            'status' => StoreStatus::Active,
        ];
    }

    public function status(StoreStatus $status): static
    {
        return $this->state(fn () => ['status' => $status]);
    }
}

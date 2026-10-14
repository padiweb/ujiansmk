<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    protected $model = User::class;

    public function definition(): array
    {
        return [
            'nama' => fake()->name(),
            'username' => fake()->unique()->userName(),
            'password' => 'kata-sandi-uji-123',
            'role' => User::GURU,
            'aktif' => true,
        ];
    }

    public function admin(): static
    {
        return $this->state(fn () => ['role' => User::ADMIN]);
    }

    public function pengawas(): static
    {
        return $this->state(fn () => ['role' => User::PENGAWAS]);
    }

    public function nonaktif(): static
    {
        return $this->state(fn () => ['aktif' => false]);
    }
}

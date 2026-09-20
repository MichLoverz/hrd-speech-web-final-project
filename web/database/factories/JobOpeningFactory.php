<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<\App\Models\JobOpening> */
class JobOpeningFactory extends Factory
{
    public function definition(): array
    {
        $min = fake()->numberBetween(40, 120) * 100_000;

        return [
            'title' => fake()->jobTitle(),
            'department' => fake()->randomElement(['Engineering', 'Operations', 'Marketing', 'Finance']),
            'description' => fake()->paragraph(),
            'salary_min' => $min,
            'salary_max' => $min + fake()->numberBetween(10, 50) * 100_000,
            'status' => 'open',
            'created_by' => User::factory()->state(['role' => User::ROLE_HRD]),
        ];
    }
}

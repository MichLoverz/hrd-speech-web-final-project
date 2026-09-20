<?php

namespace Database\Factories;

use App\Models\JobOpening;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<\App\Models\Candidate> */
class CandidateFactory extends Factory
{
    public function definition(): array
    {
        return [
            'job_opening_id' => JobOpening::factory(),
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'phone' => fake()->phoneNumber(),
            'expected_salary' => fake()->numberBetween(40, 150) * 100_000,
            'status' => 'applied',
        ];
    }
}

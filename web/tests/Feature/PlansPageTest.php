<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PlansPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_plans_page_lists_every_plan_from_config(): void
    {
        $user = User::factory()->create(['role' => User::ROLE_INTERVIEWER]);

        $response = $this->actingAs($user)->get(route('plans.index'))->assertOk();

        foreach (config('plans.plans') as $plan) {
            $response->assertSee($plan['name']);
        }
        $response->assertSee(rupiah(config('plans.plans.monthly.price')))
            ->assertSee('belum ada pembayaran yang diproses');
    }

    public function test_plans_page_requires_login(): void
    {
        $this->get(route('plans.index'))->assertRedirect(route('login'));
    }
}

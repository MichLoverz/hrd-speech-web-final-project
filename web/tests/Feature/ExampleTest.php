<?php

namespace Tests\Feature;

use Tests\TestCase;

class ExampleTest extends TestCase
{
    public function test_root_redirects_guests_to_login(): void
    {
        $this->get('/')->assertRedirect(route('job-openings.index'));
        $this->get(route('job-openings.index'))->assertRedirect(route('login'));
    }
}

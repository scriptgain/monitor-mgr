<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    use RefreshDatabase;

    /**
     * A fresh install has no admin yet, so the first-run guard sends every
     * request to the setup wizard. Reaching that redirect proves the container,
     * the DB-driven settings boot, and the middleware stack all came up.
     */
    public function test_a_fresh_install_is_sent_to_the_setup_wizard(): void
    {
        $this->get('/')->assertRedirect(route('setup.index'));
    }
}

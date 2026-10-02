<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LayoutPaletteTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_layout_uses_the_graphite_and_blue_palette(): void
    {
        $this->actingAs(User::factory()->create());

        $this->get('/vacancies')->assertOk()
            ->assertSee('--signal-fill: #2F6FEB', false)
            ->assertSee('--warn: #9A6700', false)
            ->assertDontSee('#FFB84D', false)
            ->assertSee('data-dirty', false);
    }
}

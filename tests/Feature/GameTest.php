<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithFaker;
use Tests\TestCase;
use App\Models\User;

class GameTest extends TestCase
{
    use RefreshDatabase;

    public function test_unauthorized_access(): void
    {
        $response = $this->get('/api/games');
        $response->assertStatus(302);
    }

    public function test_first_access(): void 
    {
        $user = User::factory()->create();
        $response = $this->actingAs($user)->get('/api/games');
        $response->assertOk();
        $this->assertTrue($response['message'] == 'Listing rooms available');
        $this->assertTrue(isset($response['rooms']));
        $this->assertTrue(empty($response['rooms']));
    }
}

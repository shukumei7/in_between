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

    public function test_basic_scenario(): void 
    {
        $first = User::factory()->create();
        $response = $this->actingAs($first)->get('/api/games');
        $response->assertOk();
        $this->assertTrue($response['message'] == 'Waiting for more players');
        // dd($response);

        $second = User::factory()->create();
        $response = $this->actingAs($second)->get('/api/games');
        $response->assertOk();
        $this->assertTrue($response['message'] == 'Waiting for '.$first->name);
        $this->assertTrue($response['deck'] == 48);
        $this->assertTrue($response['pot'] == 4);
        $this->assertTrue($response['dealer'] == $second->id);
        $this->assertTrue($response['current'] == $first->id);

        $response = $this->actingAs($first)->get('/api/games');
        $response->assertOk();
        $this->assertTrue($response['message'] == 'It is your turn!');
    }

}

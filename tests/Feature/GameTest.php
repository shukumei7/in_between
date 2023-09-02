<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithFaker;
use Tests\TestCase;
use App\Models\User;
use App\Models\Room;
use App\Models\Action;

class GameTest extends TestCase
{
    use RefreshDatabase;

    public function test_unauthorized_access(): void
    {
        $response = $this->get('/api/games');
        $response->assertStatus(302);
    }

    public function test_user_not_in_room(): void {
        $first = User::factory()->create();
        $response = $this->actingAs($first)->get('/api/games');
        $response->assertOk();
        $this->assertTrue(isset($response['rooms']));
        $this->assertTrue(empty($response['rooms']));
    }

    public function test_basic_scenario(): void 
    {
        $first = User::factory()->create();
        $this->assertTrue($first->id == 2);
        // test join without rooms
        $response = $this->actingAs($first)->post('/api/games');
        $response->assertOk();
        $this->assertTrue($response['message'] == 'Room created');
        $this->assertTrue($response['points'] == 0);
        // test status update
        $response = $this->actingAs($first)->get('/api/games');
        $response->assertOk();
        $this->assertTrue($response['message'] == 'Waiting for more players');
        $this->assertTrue(!isset($response['points']));
        // test trying to take action
        $response = $this->actingAs($first)->post('/api/games');
        $response->assertStatus(302);
        $this->assertTrue($response['message'] == 'Waiting for more players');
        // test new player
        $second = User::factory()->create();
        $this->assertTrue($second->id == 3);
        // test checking room list
        $response = $this->actingAs($second)->get('/api/games');
        $response->assertOk();
        $this->assertTrue(count($response['rooms']) == 1);
        // test joining room as player 2
        sleep(1);
        $response = $this->actingAs($second)->post('/api/games');
        $response->assertOk();
        if($response['message'] != 'New round started!') {
            dd($response);
        }
        $this->assertTrue($response['message'] == 'New round started!');
        $this->assertTrue($response['deck'] == 48);
        $this->assertTrue($response['pot'] == 4);
        $this->assertTrue($response['dealer'] == $first->id);
        $this->assertTrue($response['current'] == $second->id);
        $this->assertTrue($response['points'] == -2);
        // test checking status of inactive player
        $response = $this->actingAs($first)->get('/api/games');
        $response->assertOk();
        $this->assertTrue($response['message'] == 'Waiting for '.$second->name);
        $this->assertTrue($response['points'] == -2);
        // test trying to take action out of turn
        $response = $this->actingAs($first)->post('/api/games');
        $response->assertOk();
        $this->assertTrue($response['message'] == 'It is not your turn');
        $this->assertTrue(!isset($response['points']));
        // test checking status as current
        $response = $this->actingAs($second)->get('/api/games');
        $response->assertOk();
        $this->assertTrue($response['message'] == 'It is your turn!');
        $this->assertTrue(!isset($response['points']));
        // test trying an action without action
        $response = $this->actingAs($second)->post('/api/games');
        $response->assertStatus(302);
        $this->assertTrue($response['message'] == 'An action is required');
        // test trying to play with invalid bets
        $response = $this->actingAs($second)->post('/api/games', $data = ['action' => 'play']);
        $response->assertStatus(302);
        $this->assertTrue($response['message'] == $bet_1 = 'You need to place a bet of at least 1');
        $response = $this->actingAs($second)->post('/api/games', $data + ['bet' => -1]);
        $response->assertStatus(302);
        $this->assertTrue($response['message'] == $bet_1);
        $response = $this->actingAs($second)->post('/api/games', $data + ['bet' => 'test']);
        $response->assertStatus(302);
        $this->assertTrue($response['message'] == $bet_1);
        $response = $this->actingAs($second)->post('/api/games', $data + ['bet' => 5]);
        $response->assertStatus(302);
        $this->assertTrue($response['message'] == 'You cannot bet more than the pot of '.number_format($response['pot']));
        $response = $this->actingAs($second)->post('/api/games', $data + ['bet' => 4]);
        $response->assertStatus(302);
        $this->assertTrue($response['message'] == 'You can only bet a max of '.number_format(RESTRICT_BET).' if your points are less than 1');
        $this->assertTrue($response['points'] == -2);
        // manipulate winning hand
        $hand = $this->__changeHand($second->id, '10', '155');
        $response = $this->actingAs($second)->get('/api/games');
        $response->assertOk();
        $this->assertTrue($response['hand'] == $hand);
        // test win a play
        $response = $this->actingAs($second)->post('/api/games', $data + ['bet' => 2]);
        $response->assertOk();
        if($response['message'] != 'You win 2 points') {
            dd($response);
        }
        $this->assertTrue($response['message'] == 'You win 2 points');
        $this->assertTrue($response['pot'] == 2);
        $this->assertTrue($response['points'] == 0);
        $this->assertTrue(count($response['discards']) == 3);
        // test status update for player 2
        $response = $this->actingAs($first)->get('/api/games');
        $response->assertOk();
        if($response['message'] != 'It is your turn!') {
            dd($response);
        }
        $this->assertTrue($response['message'] == 'It is your turn!');
        // manipulate losing hand
        $hand = $this->__changeHand($first->id, '155', '155');
        $response = $this->actingAs($first)->get('/api/games');
        $response->assertOk();
        $this->assertTrue($response['hand'] == $hand);
        // test lose a play
        $response = $this->actingAs($first)->post('/api/games', $data + ['bet' => 1]);
        $response->assertOk();
        if($response['message'] != 'You lose 1 point. New round started!') {
            dd($response);
        }
        $this->assertTrue($response['pot'] == 3);
        $this->assertTrue($response['points'] == -3);
        $this->assertTrue(count($response['discards']) == 6);
        $this->assertTrue($response['deck'] == 42);
        $this->assertTrue($response['dealer'] == $second->id);
        $this->assertTrue($response['current'] == $first->id);
    }

    private function __changeHand($user_id, $f, $s) {
        $hands = Action::where('user_id', $user_id)->where('action', 'deal')->orderBy('id', 'desc')->limit(2)->get();
        $this->assertTrue(count($hands) == 2);
        $hands[0]->card = $f;
        $hands[0]->timestamps = false;
        $hands[0]->save();
        $hands[1]->card = $s;
        $hands[1]->timestamps = false;
        $hands[1]->save();
        return [$f, $s];
    }

}

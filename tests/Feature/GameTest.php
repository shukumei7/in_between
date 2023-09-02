<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithFaker;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use App\Models\User;
use App\Models\Room;
use App\Models\Action;

class GameTest extends TestCase
{
    // use RefreshDatabase;

    private function __changeHand($user, $f, $s) {
        $hands = Action::where('user_id', $user->id)->where('action', 'deal')->orderBy('id', 'desc')->limit(2)->get();
        $this->assertTrue(count($hands) == 2);
        $hands[0]->card = $f;
        $hands[0]->timestamps = false;
        $hands[0]->save();
        $hands[1]->card = $s;
        $hands[1]->timestamps = false;
        $hands[1]->save();
        $response = $this->actingAs($user)->get('/api/games');
        $response->assertOk();
        $this->assertTrue($response['hand'] == [$f, $s]);
    }


    public function test_unauthorized_access(): void
    {
        DB::statement('SET FOREIGN_KEY_CHECKS=0;');
        User::truncate();
        Room::truncate();
        Action::truncate();
        DB::statement('SET FOREIGN_KEY_CHECKS=1;');
        $response = $this->get('/api/games');
        $response->assertStatus(302);
    }

    public function test_user_not_in_room(): void {
        $user = User::factory()->create();
        $this->assertTrue($user->id == 1);
        $response = $this->actingAs($user)->get('/api/games');
        $response->assertOk();
        $this->assertTrue(isset($response['rooms']));
        $this->assertTrue(empty($response['rooms']));
    }

    public function test_join_without_rooms(): void 
    {
        $response = $this->actingAs(User::find(1))->post('/api/games');
        $response->assertOk();
        $this->assertTrue($response['message'] == 'Room created');
        $this->assertTrue($response['points'] == 0);
    }
    public function test_status_update(): void 
    {
        $response = $this->actingAs(User::find(1))->get('/api/games');
        $response->assertOk();
        $this->assertTrue($response['message'] == 'Waiting for more players');
        $this->assertTrue(!isset($response['points']));
    }
    public function test_trying_to_take_action(): void 
    {
        $response = $this->actingAs(User::find(1))->post('/api/games');
        $response->assertStatus(302);
        $this->assertTrue($response['message'] == 'Waiting for more players');
    }
    public function test_second_player_checking_room_list(): void 
    {
        $user = User::factory()->create();
        $this->assertTrue($user->id == 2);
        $response = $this->actingAs($user)->get('/api/games');
        $response->assertOk();
        $this->assertTrue(count($response['rooms']) == 1);
    }
    public function test_second_player_joining(): void 
    {
        sleep(1);
        $response = $this->actingAs(User::find(2))->post('/api/games');
        $response->assertOk();
        if($response['message'] != 'New round started!') {
            dd($response);
        }
        $this->assertTrue($response['message'] == 'New round started!');
        $this->assertTrue($response['deck'] == 48);
        $this->assertTrue($response['pot'] == 4);
        $this->assertTrue($response['dealer'] == User::find(1)->id);
        $this->assertTrue($response['current'] == User::find(2)->id);
        $this->assertTrue($response['points'] == -2);
    }
    public function test_checking_status_of_inactive_player(): void
    {
        $response = $this->actingAs(User::find(1))->get('/api/games');
        $response->assertOk();
        $this->assertTrue($response['message'] == 'Waiting for '.User::find(2)->name);
        $this->assertTrue($response['points'] == -2);
    }
    public function test_trying_to_take_action_out_of_turn(): void
    {
        $response = $this->actingAs(User::find(1))->post('/api/games');
        $response->assertOk();
        $this->assertTrue($response['message'] == 'It is not your turn');
        $this->assertTrue(!isset($response['points']));
    }
    public function test_checking_status_in_turn(): void
    {
        $response = $this->actingAs(User::find(2))->get('/api/games');
        $response->assertOk();
        if($response['message'] != 'It is your turn!') {
            dd($response);
        }
        $this->assertTrue($response['message'] == 'It is your turn!');
        $this->assertTrue(!isset($response['points']));
    }
    public function test_trying_to_take_action_without_action(): void
    {
        $response = $this->actingAs(User::find(2))->post('/api/games');
        $response->assertStatus(302);
        $this->assertTrue($response['message'] == 'An action is required');
    }
    public function test_trying_to_play_with_no_bets(): void
    {
        $response = $this->actingAs(User::find(2))->post('/api/games', ['action' => 'play']);
        $response->assertStatus(302);
        $this->assertTrue($response['message'] == 'You need to place a bet of at least 1');
    }
    public function test_trying_to_play_with_negative_bets(): void
    {
        $response = $this->actingAs(User::find(2))->post('/api/games', ['action' => 'play', 'bet' => -1]);
        $response->assertStatus(302);
        $this->assertTrue($response['message'] == 'You need to place a bet of at least 1');
    }
    public function test_trying_to_play_with_string_bets(): void
    {
        $response = $this->actingAs(User::find(2))->post('/api/games', ['action' => 'play', 'bet' => 'test']);
        $response->assertStatus(302);
        $this->assertTrue($response['message'] == 'You need to place a bet of at least 1');
    }
    public function test_trying_to_play_with_bet_above_pot(): void
    {
        $response = $this->actingAs(User::find(2))->post('/api/games', ['action' => 'play', 'bet' => 5]);
        $response->assertStatus(302);
        $this->assertTrue($response['message'] == 'You cannot bet more than the pot of '.number_format($response['pot']));
    }
    public function test_trying_to_play_with_bet_above_money(): void
    {
        $response = $this->actingAs(User::find(2))->post('/api/games', ['action' => 'play', 'bet' => 4]);
        $response->assertStatus(302);
        $this->assertTrue($response['message'] == 'You can only bet a max of '.number_format(RESTRICT_BET).' if your points are less than 1');
        $this->assertTrue($response['points'] == -2);
    }
    public function test_playing_a_win(): void
    {
        // manipulate winning hand
        $hand = $this->__changeHand($user = User::find(2), '10', '155');
        
        // test win a play
        $response = $this->actingAs($user)->post('/api/games', ['action' => 'play', 'bet' => 2]);
        $response->assertOk();
        if($response['message'] != 'You win 2 points') {
            dd($response);
        }
        $this->assertTrue($response['message'] == 'You win 2 points');
        $this->assertTrue($response['pot'] == 2);
        $this->assertTrue($response['points'] == 0);
        $this->assertTrue(count($response['discards']) == 3);
    }
    public function test_check_status_after_another(): void 
    {
        $response = $this->actingAs(User::find(1))->get('/api/games');
        $response->assertOk();
        if($response['message'] != 'It is your turn!') {
            dd($response);
        }
        $this->assertTrue($response['message'] == 'It is your turn!');
    }
    public function test_playing_a_lose(): void
    {
        // manipulate losing hand
        $hand = $this->__changeHand($user = User::find(1), '155', '155');
        // test lose a play
        $response = $this->actingAs($user)->post('/api/games', ['action' =>  'play', 'bet' => 1]);
        $response->assertOk();
        if($response['message'] != 'You lose 1 point. New round started!') {
            dd($response);
        }
        $this->assertTrue($response['pot'] == 3);
        $this->assertTrue($response['points'] == -3);
        $this->assertTrue(count($response['discards']) == 6);
        $this->assertTrue($response['deck'] == 42);
        $this->assertTrue($response['dealer'] == User::find(2)->id);
        $this->assertTrue($response['current'] == $user->id);
    }

    
}

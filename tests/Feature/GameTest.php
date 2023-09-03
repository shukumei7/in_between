<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithFaker;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\Eloquent\Builder;
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

    private function __addUser($user_id) {
        $user = User::factory()->create();
        $this->assertTrue($user->id == $user_id);
        return $user;
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
        $response = $this->actingAs($this->__addUser(1))->get('/api/games');
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
        $response = $this->actingAs($this->__addUser(2))->get('/api/games');
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
        $this->assertTrue($response['dealer'] == 1);
        $this->assertTrue($response['current'] == 2);
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
        $response = $this->actingAs(User::find(1))->post('/api/games', ['action' => 'test']);
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

    public function test_trying_to_take_an_invalid_action(): void
    {
        $response = $this->actingAs(User::find(2))->post('/api/games', ['action' => 'test']);
        $response->assertStatus(302);
        $this->assertTrue($response['message'] == 'You made an invalid action');
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
        $this->assertTrue($response['dealer'] == 2);
        $this->assertTrue($response['current'] == 1);
    }

    public function test_joining_before_first_move(): void 
    {
        $response = $this->actingAs($this->__addUser(3))->post('/api/games');
        $response->assertOk();
        $this->assertTrue($response['players'] == [1,2,3]);
        $this->assertTrue($response['playing'] == [1,2]);
    }

    public function test_joining_again(): void 
    {
        $response = $this->actingAs($this->__addUser(4))->post('/api/games');
        $response->assertOk();
        $this->assertTrue($response['players'] == [1,2,3,4]);
        $this->assertTrue($response['playing'] == [1,2]);
    }

    public function test_passing(): void 
    {
        $response = $this->actingAs(User::find(1))->post('/api/games', ['action' => 'pass']);
        $response->assertOk();
        $this->assertTrue($response['current'] == 2);
    }

    public function test_joining_afer_first_move(): void 
    {
        $response = $this->actingAs($this->__addUser(5))->post('/api/games');
        $response->assertOk();
        $this->assertTrue($response['players'] == [1,2,3,4,5]);
        $this->assertTrue($response['playing'] == [1,2]);
    }
    
    public function test_passing_with_new_players(): void 
    {
        $response = $this->actingAs(User::find(2))->post('/api/games', ['action' => 'pass']);
        $response->assertOk();
        $this->assertTrue($response['message'] == 'You passed. New round started!');
        $this->assertTrue($response['current'] == 4);
        $this->assertTrue($response['dealer'] == 3);
        $this->assertTrue($response['pot'] == 9);
        $this->assertTrue($response['deck'] == 32);
        $this->assertTrue($response['playing'] == [1,2,3,4,5]);
    }

    public function test_joining_with_dealer_in_the_middle(): void 
    {
        $response = $this->actingAs($this->__addUser(6))->post('/api/games');
        $response->assertOk();
        $this->assertTrue($response['players'] == [1,2,6,3,4,5]);
        $this->assertTrue($response['playing'] == [1,2,3,4,5]);
    }

    public function test_finish_pot_midround(): void 
    {
        $user = User::find(4);
        Action::add('pot', Room::factory()->create(['user_id' => 4])->id, ['user_id' => $user->id, 'bet' => 11]); // give pot money
        $this->__changeHand($user, 10, 155); // sure win
        $response = $this->actingAs($user)->post('/api/games', ['action' => 'play', 'bet' => 9]);
        // dd($response);
        $response->assertOk();
        $this->assertTrue($response['message'] == 'You win 9 points');
        $this->assertTrue($response['current'] == 5);
        $this->assertTrue($response['pot'] == 10);
        $this->assertTrue($response['deck'] == 31);
        $this->assertTrue($response['points'] == 16);
        $this->assertTrue(count($response['discards']) == 9);
        // dd($response);
    }

    public function test_cleanup_test_cards(): void 
    {
        // fix discards and dealt
        $cards = Action::select('id', 'card')->where('room_id', 1)->whereNotNull('card')->where(function (Builder $query) {
            $query->where('card', '<', '11')
                  ->orWhere('card', '>', '134');
        })->get();
        $room = Room::find(1);
        foreach($cards as $card) {
            $card->card = $room->dealCard();
            $card->timestamps = false;
            $card->save();
        }
        $status = $room->analyze();
        foreach($status['discards'] as $discard) {
            $this->assertTrue($discard > 10 && $discard < 135);
        }
    }

    public function test_player_in_turn_leaves(): void
    {
        $status = Room::find(1)->analyze();
        dd($status);
        $response = $this->actingAs(User::find(5))->post('/api/games', ['action' => 'leave']);
        $response->assertOk();
        $status = Room::find(1)->analyze();
        $this->assertTrue(!in_array(5, $status['players']));
        $this->assertTrue(!in_array(5, $status['playing']));
        dd($status);
    }

    public function test_player_before_turn_leaves(): void
    {

    }

    public function test_player_after_turn_leaves(): void
    {

    }

    public function test_player_way_before_turn_leaves(): void
    {

    }

    public function test_player_way_after_turn_leaves(): void
    {

    }

    /*
    public function test_bot_moves(): void 
    {
        $room = Room::find(1);
        $status = $room->analyze();
        //var_dump('History: '.number_format(count($status['activities'])));
        // dd($status);
        $turns = 0;
        $users = [];
        do {
            if(count($status['players']) < MAX_PLAYERS && rand(0,5) > 2) {
                $user = User::factory()->create();
                $user->activateBot();
                $users[$user->id] = $user;
                $response = $this->actingAs($user)->post('/api/games');
                $response->assertOk();
                $this->assertTrue(count($response['players']) > count($status['players']));
                $status = (array) json_decode($response->content(), true);
                continue;
            }
            $user_id = $status['current'];
            // var_dump('Current: User '.$user_id));
            $user = null;
            if(!empty($users[$user_id])) {
                $user = $users[$user_id];
            } else {
                $user = $users[$user_id] = User::find($user_id);
                $user->activateBot();
            }

            // var_dump('User '.$user->id.' is thinking');
            $status['hand'] = $status['hands'][$user->id];
            $status['points'] = $user->getPoints(true);
            $status['user_id'] = $user->id;
            if(false === $move = $user->decideMove($status)) {
                dd($status);
            }
            // var_dump('User '.$user->id.' will '.$move['action'].(!empty($move['bet'])? ' for '.number_format($move['bet']).' point(s)' : ''));
            $response = $this->actingAs($user)->post('/api/games/', $move);
            // dd($status);
            if($response->getStatusCode() != 200) {
                dd(compact('response', 'status', 'move'));
            }
            $response->assertOk();
            $status = (array) json_decode($response->content(), true);
            if(isset($status['points']) && $status['points'] < -10) {
                $left = $this->actingAs($user)->post('/api/games/', ['action' => 'leave']);
                $left->assertOk();
                $status = $room->analyze(true);
            }
        } while($turns++ < 50);
        foreach($users as $user) {
            var_dump($user->name.' : '.$user->getBotScheme().' : '.$user->getPoints());
        }
        dd($status['activities']);
    }
    */

}

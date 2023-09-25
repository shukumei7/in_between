<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithFaker;
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

    private function __checkResponseMessage($response, $message, $code = 200) {
        return $this->assertResponse($response, 'message', $message, $code);
    }

    private function __addUser($user_id) {
        $user = User::factory()->create();
        $this->assertTrue($user->id == $user_id);
        return $user;
    }

    public function test_unauthorized_access(): void
    {
        $this->_resetData();
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
        $room_name = Room::find(1)->name;
        $this->__checkResponseMessage($response, 'You joined Room '.$room_name.' (1)');
        $this->assertTrue($response['points'] == STARTING_MONEY);
    }

    public function test_status_update(): void 
    {
        $response = $this->actingAs(User::find(1))->get('/api/games');
        $this->__checkResponseMessage($response, 'Waiting for more players');
    }

    public function test_spectating_on_invalid_room(): void
    {
        $response = $this->get('/api/games/100');
        $this->__checkResponseMessage($response, 'Room ID not found', 302);
    }

    public function test_spectating_on_valid_room(): void
    {
        $response = $this->get('/api/games/1');
        $response->assertOk();
    }

    public function test_trying_to_take_action_without_action(): void
    {
        $response = $this->actingAs(User::find(1))->post('/api/games');
        $this->__checkResponseMessage($response, 'An action is required', 302);
    }

    public function test_trying_to_take_action(): void 
    {
        $response = $this->actingAs(User::find(1))->post('/api/games', ['action' => 'pass']);
        $this->__checkResponseMessage($response, 'Waiting for more players', 302);
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
        $room_name = Room::find(1)->name;
        $this->__checkResponseMessage($response, 'You joined Room '.$room_name.' (1). New round started!');
        $this->assertTrue($response['deck'] == 48);
        $this->assertTrue($response['pot'] == 4);
        $this->assertTrue($response['dealer'] == 1);
        $this->assertTrue($response['current'] == 2);
        $this->assertCase($response['points'] == STARTING_MONEY - 2, $response);
    }

    public function test_checking_status_of_inactive_player(): void
    {
        $response = $this->actingAs(User::find(1))->get('/api/games');
        $this->__checkResponseMessage($response, 'Waiting for '.User::find(2)->name);
        $this->assertTrue($response['points'] == STARTING_MONEY - 2);
    }

    public function test_trying_to_take_action_out_of_turn(): void
    {
        $response = $this->actingAs(User::find(1))->post('/api/games', ['action' => 'test']);
        $this->__checkResponseMessage($response, 'It is not your turn');
    }

    public function test_checking_status_in_turn(): void
    {
        $response = $this->actingAs(User::find(2))->get('/api/games');
        $this->__checkResponseMessage($response, 'It is your turn!');
    }

    public function test_trying_to_take_an_invalid_action(): void
    {
        $response = $this->actingAs(User::find(2))->post('/api/games', ['action' => 'test']);
        $this->__checkResponseMessage($response, 'You made an invalid action', 302);
    }

    public function test_trying_to_play_with_no_bets(): void
    {
        $response = $this->actingAs(User::find(2))->post('/api/games', ['action' => 'play']);
        $this->__checkResponseMessage($response, 'You need to place a bet of at least 1', 302);
    }

    public function test_trying_to_play_with_negative_bets(): void
    {
        $response = $this->actingAs(User::find(2))->post('/api/games', ['action' => 'play', 'bet' => -1]);
        $this->__checkResponseMessage($response, 'You need to place a bet of at least 1', 302);
    }

    public function test_trying_to_play_with_string_bets(): void
    {
        $response = $this->actingAs(User::find(2))->post('/api/games', ['action' => 'play', 'bet' => 'test']);
        $this->__checkResponseMessage($response, 'You need to place a bet of at least 1', 302);
    }

    public function test_trying_to_play_with_bet_above_pot(): void
    {
        $response = $this->actingAs(User::find(2))->post('/api/games', ['action' => 'play', 'bet' => 5]);
        $this->__checkResponseMessage($response, 'You cannot bet more than the pot of '.number_format($response['pot']), 302);
    }

    public function test_trying_to_play_with_bet_above_money(): void
    {
        // add pot money
        $room = Room::find(1);
        $status = $room->analyze(true);
        $dummy = Action::add('pot', 1, ['user_id' => 1, 'bet' => -1 * RESTRICT_BET]);
        $status = $room->analyze(true);
        $this->assertResponse($status, 'pot', 4 + RESTRICT_BET, 0);
        // remove starting money
        $user = User::find(2);
        $action = Action::add('pot', $room_id = Room::factory()->create(['user_id' => 2])->id, ['user_id' => 2, 'bet' => -1 * STARTING_MONEY]);
        $this->assertCase($user->getPoints() == -2, $user);
        $response = $this->actingAs($user)->post('/api/games', ['action' => 'play', 'bet' => RESTRICT_BET + 1]);
        $this->assertResponse($response, 'message', 'You can only bet a max of '.number_format(RESTRICT_BET).' if your points are less than 1', 302);
        $this->assertTrue($response['points'] == -2);
        $action->delete();
        $dummy->delete();
        Room::find($room_id)->delete();
    }

    public function test_playing_a_win(): void
    {
        // manipulate winning hand
        $hand = $this->__changeHand($user = User::find(2), '10', '155');
        // test win a play
        $response = $this->actingAs($user)->post('/api/games', ['action' => 'play', 'bet' => 2]);
        $this->__checkResponseMessage($response, 'You win 2 points');
        $this->assertTrue($response['pot'] == 2);
        $this->assertTrue($response['points'] == STARTING_MONEY);
        $this->assertTrue(count($response['discards']) == 3);
    }

    public function test_check_status_after_another(): void 
    {
        $response = $this->actingAs(User::find(1))->get('/api/games');
        $this->__checkResponseMessage($response, 'It is your turn!');
    }

    public function test_playing_a_lose(): void
    {
        // manipulate losing hand
        $hand = $this->__changeHand($user = User::find(1), '155', '155');
        // test lose a play
        $response = $this->actingAs($user)->post('/api/games', ['action' =>  'play', 'bet' => 1]);
        $this->__checkResponseMessage($response, 'You lose 1 point. New round started!');
        $this->assertTrue($response['pot'] == 3);
        $this->assertTrue($response['points'] == STARTING_MONEY - 3);
        $this->assertTrue(count($response['discards']) == 6);
        $this->assertTrue($response['deck'] == 42);
        $this->assertTrue($response['dealer'] == 2);
        $this->assertTrue($response['current'] == 1);
    }

    public function test_joining_before_first_move(): void 
    {
        $response = $this->actingAs($this->__addUser(3))->post('/api/games');
        $response->assertOk();
        $this->assertTrue(array_keys($response['players']) == [1,2,3]);
        $this->assertTrue($response['playing'] == [1,2]);
    }

    public function test_joining_again(): void 
    {
        $response = $this->actingAs($this->__addUser(4))->post('/api/games');
        $response->assertOk();
        $this->assertTrue(array_keys($response['players']) == [1,2,3,4]);
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
        $this->assertTrue(array_keys($response['players']) == [1,2,3,4,5]);
        $this->assertTrue($response['playing'] == [1,2]);
    }
    
    public function test_passing_with_new_players(): void 
    {
        $response = $this->actingAs(User::find(2))->post('/api/games', ['action' => 'pass']);
        $this->__checkResponseMessage($response, 'You passed. New round started!');
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
        $this->assertTrue(array_keys($response['players']) == [1,2,6,3,4,5]);
        $this->assertTrue($response['playing'] == [1,2,3,4,5]);
    }

    public function test_finish_pot_midround(): void 
    {
        $user = User::find(4);
        $action = Action::add('pot', $room_id = Room::factory()->create(['user_id' => 4])->id, ['user_id' => $user->id, 'bet' => 11]); // give pot money
        $this->__changeHand($user, 10, 155); // sure win
        $response = $this->actingAs($user)->post('/api/games', ['action' => 'play', 'bet' => 9]);
        $this->__checkResponseMessage($response, 'You win 9 points');
        $this->assertTrue($response['current'] == 5);
        $this->assertTrue($response['pot'] == 10);
        $this->assertTrue($response['deck'] == 31);
        $this->assertTrue($response['points'] == STARTING_MONEY + 16);
        $this->assertTrue(count($response['discards']) == 9);
        $action->delete();
        Room::find($room_id)->delete();
    }

    public function test_player_at_the_end_of_list_leaving(): void 
    {
        $response = $this->actingAs(User::find(5))->post('/api/games', ['action' => 'leave']);
        $this->__checkResponseMessage($response, 'You left the room');
        $status = $this->get('/api/games/1');
        $status->assertOk();
        $this->assertTrue($status['dealer'] == 3);
        $this->assertCase($status['current'] == 1, $status);
    }

    public function test_player_at_the_start_of_list_leaving(): void 
    {
        $response = $this->actingAs(User::find(1))->post('/api/games', ['action' => 'leave']);
        $response->assertOk();
        $status = $this->get('/api/games/1');
        $status->assertOk();
        $this->assertTrue($status['dealer'] == 3);
        $this->assertCase($status['current'] == 2, $status);
    }

    public function test_dealer_leaves_in_turn(): void  // most conflict
    {
        $response = $this->actingAs(User::find(2))->post('/api/games', ['action' => 'pass']);
        $response->assertOk();
        $response = $this->actingAs(User::find(3))->post('/api/games', ['action' => 'leave']);
        $response->assertOk();
        $status = $this->get('/api/games/1');
        $status->assertOk();
        $this->assertCase($status['playing'] == [2,6,4], $status);
        $this->assertCase($status['pot'] == 12, $status);
        $this->assertCase($status['dealer'] == 4, $status);
        $this->assertTrue($status['current'] == 2);
    }

    public function test_dealer_leaves_before_turn(): void  // soft conflict
    {
        $response = $this->actingAs(User::find(4))->post('/api/games', ['action' => 'leave']);
        $response->assertOk();
        $status = $this->get('/api/games/1');
        $status->assertOk();
        $this->assertCase($status['dealer'] == 6, $status);
        $this->assertCase($status['current'] == 2, $status);
    }

    public function test_second_to_the_last_player_leaves(): void // confusion
    {
        $response = $this->actingAs(User::find(2))->post('/api/games', ['action' => 'leave']);
        $response->assertOk();
        $status = $this->get('/api/games/1');
        $this->__checkResponseMessage($status, 'You are spectating. Waiting for more players');
        $this->assertTrue($status['dealer'] == 0);
        $this->assertTrue($status['current'] == 0);
        
    }

    public function test_trying_to_take_action_in_inactive_room(): void
    {
        $response = $this->actingAs(User::find(6))->post('/api/games', ['action' => 'pass']);
        $this->__checkResponseMessage($response, 'Waiting for more players', 302);
        $this->assertCase($response['dealer_index'] == 0, $response);
    }

    public function test_joining_to_reactivate_room_midplay(): void 
    {
        $response = $this->actingAs(User::find(4))->post('/api/games/1');
        $response->assertOk();
        $this->assertCase($response['deck'] == 48, $response);
        $this->assertCase($response['pot'] == 14, $response);
        $this->assertCase(array_keys($response['players']) == [6,4], $response);
        $this->assertCase($response['playing'] == [6,4], $response);
        $this->assertCase($response['dealer'] == 6, $response);
        $this->assertCase($response['current'] == 4, $response);
    }

    public function test_waiting_players_can_reactivate_empty_room(): void
    {
        $response = $this->actingAs(User::find(2))->post('/api/games/1');
        $response->assertOk();
        $response = $this->actingAs(User::find(3))->post('/api/games/1');
        $response->assertOk();
        $this->assertCase(array_keys($response['players']) == [6,4,2,3], $response);
        $this->assertCase($response['playing'] == [6,4], $response);
        $response = $this->actingAs($user = User::find(4))->post('/api/games', ['action' => 'leave']);
        $this->assertResponse($response, 'room_id', 0);
        $this->assertResponse($response, 'message', 'You left the room');
        $this->assertEquals(4, $user->id);
        $this->assertCase(0 == $user->getRoomID(true), $user->getRoomID());
        $response = $this->get('/api/games/1');
        $this->assertCase($response['dealer'] == 2, $response);
        $this->assertCase($response['current'] == 3, $response);
        $response = $this->actingAs($user = User::find(6))->post('/api/games', ['action' => 'leave']);
        $this->assertResponse($response, 'room_id', 0);
        $response = $this->get('/api/games/1');
        $this->assertCase($response['deck'] == 42, $response);
        $this->assertCase($response['pot'] == 18, $response);
        $this->assertCase(array_keys($response['players']) == [2,3], $response);
        $this->assertCase($response['playing'] == [2,3], $response);
        $this->assertCase($response['dealer'] == 2, $response);
        $this->assertCase($response['current'] == 3, $response);
    }

    public function test_leaving_as_last_player(): void 
    {
        $response = $this->actingAs(User::find(2))->post('/api/games', ['action' => 'leave']);
        $response->assertOk();
        $response = $this->actingAs(User::find(3))->post('/api/games', ['action' => 'leave']);
        $response->assertOk();
        $status = $this->get('/api/games/1');
        $this->__checkResponseMessage($status, 'You are spectating. Waiting for more players');
        $this->assertTrue(empty($status['players']));
        $this->assertTrue(empty($status['playing']));
        $this->assertTrue(empty($status['hands']));
        $this->assertTrue($status['dealer'] == 0);
        $this->assertTrue($status['current'] == 0);
    }

    public function test_join_an_empty_room(): void
    {
        $room = Room::find(1);
        $status = $room->analyze();
        $this->assertTrue(empty($status['players']));
        $response = $this->actingAs(User::factory()->create())->post('/api/games');
        $this->__checkResponseMessage($response, 'You joined Room '.$room->name.' (1)');
        $this->assertTrue(array_keys($response['players']) == [7]);
    }

    public function test_second_player_reactivates_room_game(): void
    {
        $response = $this->actingAs(User::factory()->create())->post('/api/games');
        $room_name = Room::find(1)->name;
        $this->__checkResponseMessage($response, 'You joined Room '.$room_name.' (1). New round started!');
        $this->assertTrue(array_keys($response['players']) == [7,8]);
        $this->assertTrue($response['dealer'] == 7);
        $this->assertTrue($response['current'] == 8);
        $this->assertCase($response['pot'] == 22, $response);
        $this->assertTrue($response['deck'] == 48);
    }

    public function test_middle_player_leaving_in_turn(): void 
    {
        $user = User::find(4);
        $this->assertEquals(0, $user->getRoomID());
        $response = $this->actingAs($user)->post('/api/games/1');
        $this->assertResponse($response, 'room_id', 1);
        $response = $this->actingAs(User::find(8))->post('/api/games', ['action' => 'pass']);
        $response->assertOk();
        $response = $this->actingAs(User::find(7))->post('/api/games', ['action' => 'pass']);
        $response->assertOk();
        $this->assertCase($response['playing'] == [7,8,4], $response);
        $this->assertCase($response['dealer'] == 8, $response);
        $this->assertCase($response['current'] == 4, $response);
        $this->assertCase($response['pot'] == 24, $response);
        $this->assertCase($response['deck'] == 42, $response);
        $response = $this->actingAs(User::find(4))->post('/api/games', ['action' => 'pass']);
        $response->assertOk();
        $response = $this->actingAs(User::find(7))->post('/api/games', ['action' => 'pass']);
        $response->assertOk();
        $response = $this->actingAs(User::find(8))->post('/api/games', ['action' => 'pass']);
        $response->assertOk();
        $response = $this->get('/api/games/1');
        $this->assertCase($response['playing'] == [7,8,4], $response);
        $this->assertCase($response['dealer'] == 4, $response);
        $this->assertCase($response['current'] == 7, $response);
        $response = $this->actingAs(User::find(7))->post('/api/games', ['action' => 'pass']);
        $response->assertOk();
        $response = $this->actingAs(User::find(8))->post('/api/games', ['action' => 'leave']);
        $response->assertOk();
        $response = $this->get('/api/games/1');
        $this->assertCase($response['playing'] == [7,4], $response);
        $this->assertCase($response['dealer'] == 4, $response);
        $this->assertCase($response['current'] == 4, $response);
        $this->assertCase(!empty($response['hands'][4]), $response);
        $this->assertCase(empty($response['hands'][7]), $response);
    }

    public function test_creating_a_new_room(): void 
    {
        $response = $this->actingAs($user = User::find(1))->post('/api/games', ['action' => 'create', 'passcode' => $pass = 'test']);
        $this->__checkResponseMessage($response, 'You created a room');
        $room = Room::find(4);
        // var_dump($room->name);
        $this->assertTrue($room->name == $user->name."'s room");
        $this->assertTrue($room->passcode == $pass);
    }

    public function test_creating_a_new_room_while_being_in_a_room(): void 
    {
        $response = $this->actingAs(User::find(1))->post('/api/games', ['action' => 'create', 'passcode' => $pass = 'test']);
        $this->__checkResponseMessage($response, 'You are already in a room', 302);
    }

    public function test_creating_a_new_named_room(): void 
    {
        $response = $this->actingAs(User::find(2))->post('/api/games', ['action' => 'create', 'name' => $name = 'Test']);
        $response->assertOk();
        $room = Room::find(5);
        $this->assertTrue($room->name == $name);
    }

    public function test_creating_a_duplicate_named_room(): void 
    {
        $room = Room::where('name', 'Test')->first();
        $this->assertTrue(!empty($room->id));
        $response = $this->actingAs(User::find(3))->post('/api/games', ['action' => 'create', 'name' => $name = 'Test']);
        $this->__checkResponseMessage($response, 'That Room name already exists: '.$name, 302);
    }
/*
    public function test_bots_playing(): void 
    {
        $room = Room::find(1);
        $status = $room->analyze();
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
            $user = null;
            if(!empty($users[$user_id])) {
                $user = $users[$user_id];
            } else {
                $user = $users[$user_id] = User::find($user_id);
                $user->activateBot();
            }
            $status['hand'] = $status['hands'][$user->id];
            $status['points'] = $user->getPoints(true);
            $status['user_id'] = $user->id;
            if(false === $move = $user->decideMove($status)) {
                // dump($status);
                dd('Cannot decide move');
            }
            $response = $this->actingAs($user)->post('/api/games/', $move);
            if($response->getStatusCode() != 200) {
                dd(compact('response', 'status', 'move'));
            }
            $response->assertOk();
            $status = json_to_array($response);
            if(isset($status['points']) && $status['points'] < BOT_DEFEATED) {
                $left = $this->actingAs($user)->post('/api/games/', ['action' => 'leave']);
                $left->assertOk();
                $status = $room->analyze(true);
            }
        } while($turns++ < 50);
        foreach($users as $user) {
            dump($user->name.' : '.$user->remember_token.' : '.$user->getPoints());
        }
        // dd($status['activities']);
    }
*/
}

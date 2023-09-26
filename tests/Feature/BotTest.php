<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithFaker;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;
use App\Http\Controllers\GameController;
use App\Models\User;
use App\Models\Room;
use App\Models\Action;
use App\Models\Snapshot;

class BotTest extends TestCase
{

    public function test_begin_populating(): void 
    {
        $this->_resetData();
        $this->assertTrue(User::get()->isEmpty());
        $this->assertTrue(Room::get()->isEmpty());
        $this->artisan('bots:run')
            ->assertExitCode(0)
            ->expectsOutput('Registered Bots: 0')
            ->expectsOutput('Available Rooms: 0')
            ->expectsOutput('Bot 1 started a room');
    }
    public function test_add_another_bot(): void
    {
        $this->artisan('bots:run')
            ->assertExitCode(0)
            ->expectsOutput('Registered Bots: 1')
            ->expectsOutput('Available Rooms: 1')
            ->expectsOutput('Added Bot 2');
    }
    public function test_make_first_move(): void
    {
        $this->withoutMockingConsoleOutput()->artisan('bots:run');
        $output = explode("\n", Artisan::output());
        $this->assertTrue($output[0] == 'Registered Bots: 2');
        $this->assertTrue($output[1] == 'Available Rooms: 1');
        $this->assertTrue(str_starts_with($output[2], 'Room 1: [1d,2c]'));
        $this->assertTrue($output[3] == 'Added Bot 3');
        $this->__testPlaying($output[4], 2);
    }
    public function test_make_second_move(): void
    {
        $this->withoutMockingConsoleOutput()->artisan('bots:run');
        $output = explode("\n", Artisan::output());
        $this->assertTrue($output[0] == 'Registered Bots: 3');
        $this->assertTrue($output[1] == 'Available Rooms: 1');
        $this->assertTrue(str_starts_with($output[2], 'Room 1: [1dc,2,3x]'));
        $this->assertTrue($output[3] == 'Added Bot 4');
        $this->__testPlaying($output[4], 1);
    }
    public function test_room_one_rotated(): void
    {
        $room = Room::find(1);
        $status = $room->analyze();
        $this->assertTrue($status['dealer'] == 2);
        $this->assertTrue($status['current'] == 3);
    }
    public function test_start_another_room(): void
    {
        $this->withoutMockingConsoleOutput()->artisan('bots:run');
        $output = explode("\n", Artisan::output());
        $this->assertTrue($output[0] == 'Registered Bots: 4');
        $this->assertTrue($output[1] == 'Available Rooms: 1');
        $this->assertCase(str_starts_with($output[2], 'Room 1: [1,2d,3c,4x]'), $output);
        $this->__testPlaying($output[3], 3);
        $this->assertCase($output[5] == 'Bot 5 started a room', $output);
    }
    public function test_join_second_room(): void
    {
        $this->withoutMockingConsoleOutput()->artisan('bots:run');
        $output = explode("\n", Artisan::output());
        $this->assertCase($output[0] == 'Registered Bots: 5', $output);
        $this->assertTrue($output[1] == 'Available Rooms: 2');
        $this->assertTrue(str_starts_with($output[2], 'Room 1: [1c,2d,3,4x]'));
        $this->__testPlaying($output[3], 1);
        $this->assertCase(str_starts_with($output[5], 'Room 2: [5x]'), $output);
        $this->assertTrue($output[6] == 'Added Bot 6');
    }
    public function test_play_in_multiple_rooms(): void
    {
        $this->withoutMockingConsoleOutput()->artisan('bots:run');
        $output = explode("\n", Artisan::output());
        $this->assertTrue($output[0] == 'Registered Bots: 6');
        $this->assertTrue($output[1] == 'Available Rooms: 2');
        $this->assertCase(str_starts_with($output[2], 'Room 1: [1,2dc,3,4x]'), $output);
        $this->__testPlaying($output[3], 2);
        $this->assertCase(str_starts_with($output[5], 'Room 2: [5d,6c]'), $output);
        $this->assertTrue($output[6] == 'Added Bot 7');
        $this->__testPlaying($output[7], 6);
    }
    public function test_play_in_inactive_room(): void 
    {
        $room = Room::find(1);
        $status = $room->analyze();
        $Game = new GameController;
        $Game->leaveRoom(User::find(1), true);
        $Game->leaveRoom(User::find(2), true);
        $Game->leaveRoom(User::find(3), true);
        $status = $room->analyze(true);
        $this->assertCase(!empty($status['players'][4]), $status);
        $this->withoutMockingConsoleOutput()->artisan('bots:run');
        $output = explode("\n", Artisan::output());
        $this->assertTrue($output[0] == 'Registered Bots: 7');
        $this->assertTrue($output[1] == 'Available Rooms: 2');
        $this->assertCase(str_starts_with($output[2], 'Room 1: [4x]'), $output);
        $this->assertCase($output[3] == 'Added Bot 1', $output);
        $this->assertCase(str_starts_with($output[6], 'Room 2: [5dc,6,7x]'), $output);
        $this->assertTrue($output[7] == 'Added Bot 2');
        $this->__testPlaying($output[8], 5);
    }
    public function test_play_back_to_normal(): void 
    {
        $this->withoutMockingConsoleOutput()->artisan('bots:run');
        $output = explode("\n", Artisan::output());
        $this->assertTrue($output[0] == 'Registered Bots: 7');
        $this->assertTrue($output[1] == 'Available Rooms: 2');
        $this->assertCase(str_starts_with($output[2], 'Room 1: [4d,1c]'), $output);
        $this->assertTrue($output[3] == 'Added Bot 3');
        $this->__testPlaying($output[4], 1);
        $this->assertCase(str_starts_with($output[6], 'Room 2: [5,6d,7c,2x]'), $output);
        $this->__testPlaying($output[7], 7);
    }

    public function test_auto_pass(): void
    {
        $room = Room::find(1);
        $status = $room->analyze();
        $this->assertResponse($status, 'dealer', 4, 0);
        $this->assertResponse($status, 'current', 4, 0);
        $response = json_decode((new GameController)->passHand($status['current'])->content(), true);
        $this->assertResponse($response, 'message', 'You passed. New round started!', 0);
        $this->assertResponse($response, 'playing', [4,1,3], 0);
        $this->assertResponse($response, 'dealer', 1, 0);
        $this->assertResponse($response, 'current', 3, 0);
        $this->assertCase(array_filter($response['hands']) == $response['hands'], $response);
    }

    public function test_continuous_play(): void
    {
        $limit = 50;
        Room::$snapshot_threshold = 0.03;
        for($x = 0; $x < $limit; $x++) {
            $this->artisan('bots:run')->assertExitCode(0);
            $this->__kickRandomBot();
            $this->__checkAllRooms();
            if(empty($snapshot = Snapshot::first())) {
                $limit++;
            } else if($snapshot) {
                break;
            }
        }
        // dump($snapshot);
    }

    public function test_snapshot_integrity(): void
    {
        Room::$debug = true;
        $snapshot = Snapshot::first()->toArray();
        // $this->artisan('bots:run')->assertExitCode(0);
        $room = Room::find($snapshot['room_id']);
        $snapped = $room->analyze();
        $rebuilt = $room->analyze(true, true);
        $comparison = [];
        $fields = ['current_index', 'current', 'dealer_index', 'dealer', 'pot', 'hidden', 'deck'];
        foreach($fields as $field) {
            $comparison[$field]['snapped'] = $snapped[$field];
            $comparison[$field]['rebuilt'] = $rebuilt[$field];
            $this->assertCase($snapped[$field] == $rebuilt[$field], $comparison);
        }
        // $this->assertCase($rebuilt['current_index'] == $snapshot['turn'], compact('comparison', 'snapshot'));
        // $this->assertCase($snapped['current_index'] == $snapshot['turn'], compact('comparison', 'snapshot'));
        // $this->assertCase($rebuilt['current_index'] == $snapped['current_index'], compact('comparison', 'snapshot'));
        // $this->assertCase($rebuilt['current'] == $snapped['current'], $comparison);
        // $this->assertEquals($rebuilt['dealer'], $snapped['dealer']);
        $this->assertEquals(json_encode($rebuilt['players']), json_encode($snapped['players']));
        return;
        $this->artisan('bots:run')->assertExitCode(0);
        $snapped = $room->analyze(true);
        $rebuilt = $room->analyze(true, true);
        $this->assertEquals($rebuilt['current'], $snapped['current']);
        $this->assertEquals($rebuilt['dealer'], $snapped['dealer']);
        $this->assertEquals(json_encode($rebuilt['players']), json_encode($snapped['players']));
        // dump(compact('snapped', 'rebuilt'));
    }

    private function __checkAllRooms() {
        $rooms = Room::get();
        foreach($rooms as $room) {
            $this->__checkRoom($room);
        }
    }

    private function __checkRoom($room) {
        $status = $room->analyze(true);
        if(count($status['players']) < 2) {
            return; // nothing to check
        }
        if($status['dealer'] == 0 || $status['current'] == 0) {
            // not activated
            dump($status);
            dd('Room not activated');
            die;
            return;
        }
        if(empty($status['hands'][$status['current']])) {
            // hands empty
            dump($status);
            dump('Player hands empty');
            die;
            return;
        }
    }

    private function __kickRandomBot() {
        // kick random bot
        $count = User::count();
        while(empty($bot = User::find(rand(0, $count - 1))) || empty($room_id = $bot->getRoomID(true)));
        (new GameController())->leaveRoom($bot, true);
    }
    
    private function __testPlaying($output, $bot_id, $bet = null, $points = null, $pot = null) {
        if(strstr($output, 'passed')) {
            $this->assertTrue($output == 'Bot '.$bot_id.' passed');
            return;
        } 
        $this->assertTrue(true == preg_match('/Bot '.$bot_id.' played and (won|lost) \d+/', $output));
    }

}

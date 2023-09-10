<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithFaker;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

use App\Models\User;
use App\Models\Room;

class CommandTest extends TestCase
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
            ->expectsOutput('Added Bot 2 to Room 1');
    }
    public function test_make_first_move(): void
    {
        $this->withoutMockingConsoleOutput()->artisan('bots:run');
        $output = explode("\n", Artisan::output());
        $this->assertTrue($output[0] == 'Registered Bots: 2');
        $this->assertTrue($output[1] == 'Available Rooms: 1');
        $this->assertTrue($output[2] == 'Added Bot 3 to Room 1');
        $this->__testPlaying($output[3], 2, 1);
    }
    public function test_make_second_move(): void
    {
        $this->withoutMockingConsoleOutput()->artisan('bots:run');
        $output = explode("\n", Artisan::output());
        $this->assertTrue($output[0] == 'Registered Bots: 3');
        $this->assertTrue($output[1] == 'Available Rooms: 1');
        $this->assertTrue($output[2] == 'Added Bot 4 to Room 1');
        $this->__testPlaying($output[3], 1, 1);
    }
    public function test_start_another_room(): void
    {
        $this->withoutMockingConsoleOutput()->artisan('bots:run');
        $output = explode("\n", Artisan::output());
        $this->assertTrue($output[0] == 'Registered Bots: 4');
        $this->assertTrue($output[1] == 'Available Rooms: 1');
        $this->__testPlaying($output[2], 2, 1);
        $this->assertTrue($output[3] == 'Bot 5 started a room');
    }
    public function test_join_second_room(): void
    {
        $this->withoutMockingConsoleOutput()->artisan('bots:run');
        $output = explode("\n", Artisan::output());
        $this->assertTrue($output[0] == 'Registered Bots: 5');
        $this->assertTrue($output[1] == 'Available Rooms: 2');
        $this->__testPlaying($output[2], 3, 1);
        $this->assertTrue($output[3] == 'Added Bot 6 to Room 2');
    }
    public function test_play_in_multiple_rooms(): void
    {
        $this->withoutMockingConsoleOutput()->artisan('bots:run');
        $output = explode("\n", Artisan::output());
        $this->assertTrue($output[0] == 'Registered Bots: 6');
        $this->assertTrue($output[1] == 'Available Rooms: 2');
        $this->__testPlaying($output[2], 1, 1);
        $this->assertTrue($output[3] == 'Added Bot 7 to Room 2');
        $this->__testPlaying($output[4], 6, 2);
    }
    public function test_continuous_play(): void
    {
        for($x = 0; $x < 50; $x++) {
            $this->artisan('bots:run')
                ->assertExitCode(0);
        }
    }

    private function __testPlaying($output, $bot_id, $room_id, $bet = null, $points = null, $pot = null) {
        if(strstr($output, 'passed')) {
            $this->assertTrue($output == 'Bot '.$bot_id.' passed on Room '.$room_id);
            return;
        } 
        $this->assertTrue(true == preg_match('/Bot '.$bot_id.' played on Room '.$room_id.' and (won|lost) \d+/', $output));
        /*
        $points = User::find($bot_id)->getPoints();
        $status = Room::find($room_id)->analyze();
        if(strstr($output, 'won')) {
            $this->assertTrue($output, 'Bot '.$bot_id.' played on Room '.$room_id.' and won '.number_format($bet));    
            return;
        }
        $this->assertTrue($output, 'Bot '.$bot_id.' played on Room '.$room_id.' and lost '.number_format($bet));
        */
    }

/*
    public function test_auto_kick(): void
    {
        $this->artisan('game:auto-kick')
            // ->expectsConfirmation('Do you really wish to import products?', 'no')
            // ->expectsOutput('Import cancelled')
            // ->doesntExpectOutput('Products imported')
            ->assertExitCode(0);
    }
*/
}

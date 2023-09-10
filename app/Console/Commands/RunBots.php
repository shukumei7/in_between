<?php

namespace App\Console\Commands;

use App\Http\Controllers\GameController;
use Illuminate\Console\Command;

use App\Models\User;
use App\Models\Action;
use App\Models\Room;

class RunBots extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'bots:run';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Run Bots to play the Game';

    /**
     * Execute the console command.
     */

    private $__bots = [];
    private $__api = 'http://localhost/api/games';
    private $Game = null;

    public function handle()
    {
        $this->__bots = $bots = User::where('type', 'bot')->get();
        $this->info('Registered Bots: '.number_format(count($this->__bots)));
        $rooms = Room::whereNull('passcode')->get();
        $this->info('Available Rooms: '.number_format(count($rooms)));
        if($rooms->isEmpty()) { // no games going, create bots
            return $this->__startNewRoom();
        }
        $this->Game = new GameController;
        $acted = false;
        foreach($rooms as $room) {
            $acted = $acted || $this->__analyzeRoom($room);
        }
        if(!$acted && count($this->__bots) < MAX_BOTS) {
            $this->__startNewRoom();
        }
    }

    private function __analyzeRoom($room) {
        $this->Game->clearData();
        $status = $room->analyze();
        $acted = false;
        if(count($status['players']) < MAX_ROOM_BOTS) {
            $this->__addBot($room);
            $acted = true;
        } 
        if(!empty($user_id = $status['current']) && ($bot = $this->__getBot($user_id)) && !empty($move = $bot->decideMove($status + ['hand' => $room->getHand($bot->id)]))) {
            $this->__analyzeMove($bot, $room, $move, $status);            
            $status = $this->Game->checkEndRound([], $status);
        }
        return $acted;
        if(!$acted) {
            dd($status);
        }
    }

    private function __analyzeMove($bot, $room, $move, $status) {
        switch($move['action']) {
            case 'pass':
                Action::add($move['action'], $room->id, ['user_id' => $bot->id] + $move);
                $this->info('Bot '.$bot->id.' passed on Room '.$room->id);
                return;
            case 'play':
                $bet = $move['bet'];
                $this->Game->playHand($bot, $room, $bet, $status);
                $this->info('Bot '.$bot->id.' played on Room '.$room->id.' and '.($bet > 0? 'won' : 'lost').' '.number_format(abs($bet)));
                return;
        }
    }

    private function __addBot($room) {
        $bot = $this->__getBot();
        $this->Game->joinRoom($bot, $room);
        $this->info('Added Bot '.$bot->id.' to Room '.$room->id);
    }

    private function __getBot($id = null) {
        if($id) {
            return $this->__bots->first(function($bot) use ($id) {
                return $bot->id == $id;
            });
        }
        if(empty($this->__bots)) {
            return $this->__createNewBot();
        }
        foreach($this->__bots as $bot) {
            if(empty($room_id = $bot->getRoomID())) {
                return $bot;
            }
        }
        return $this->__createNewBot();
    }

    private function __createNewBot() {
        $this->__bot []= $bot = User::create([
            'name'              => fake()->unique()->name, 
            'type'              => 'bot'
        ]);
        $bot->activateBot();
        return $bot;
    }

    private function __startNewRoom() {
        $bot = $this->__getBot();
        $room = Room::factory()->create([
            'user_id'   => $bot->id,
            'name'      => fake()->unique()->name.' '.Room::count()
        ]);
        Action::add('join', $room->id, ['user_id' => $bot->id]);
        $this->info('Bot '.$bot->id.' started a room');
        return 0;
    }

}

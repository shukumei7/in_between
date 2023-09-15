<?php

namespace App\Console\Commands;

use Illuminate\Support\Facades\DB;
use Illuminate\Console\Command;
use App\Http\Controllers\GameController;
use App\Models\User;
use App\Models\Action;
use App\Models\Room;

class AutoKick extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'game:auto-kick';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Auto Kick players idle after a timeout';

    /**
     * Execute the console command.
     */

    public function handle()
    {
        $now = date('Y-m-d H:i:s');
        $deadline = date('Y-m-d H:i:s', strtotime('-'.KICK_TIMEOUT.' seconds'));
        // get rooms with recent activity
        $recents = Action::select(DB::raw('DISTINCT room_id AS room_id'))->where('time', '>', $deadline)->pluck('room_id')->toArray();
        $this->info('Rooms with activity: '.count($recents));
        // get rooms not in recent activity and are public
        $rooms = Room::whereNotIn('id', $recents)->whereNull('passcode')->get();
        // dd(compact('now', 'deadline', 'recents', 'rooms'));
        $this->info('Rooms without activity: '.count($rooms));
        $this->Game = new GameController;
        foreach($rooms as $room) {
            $this->__checkKick($room);
        }
    }

    private function __checkKick($room) {
        $status = $room->analyze();
        if($status['current'] == 0 || count($status['players']) < 2) {
            $this->info('Room '.$room->id.' is inactive');
            return; // room is inactive
        }
        $this->Game->leaveRoom($user = User::find($status['current']), true);
        $this->info('Kick User '.$user->id.' from Room '.$room->id);
    }

}

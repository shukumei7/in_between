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
        $deadline = date('Y-m-d H:i:s', strtotime('-'.PASS_TIMEOUT.' seconds'));
        // get rooms with recent activity
        $recents = Action::select(DB::raw('DISTINCT room_id AS room_id'))->whereNotIn('action', ['join', 'leave', 'kick'])->where('time', '>', $deadline)->pluck('room_id')->toArray();
        $this->info('Rooms with activity: '.count($recents));
        // get rooms not in recent activity and are public
        $rooms = Room::whereNotIn('id', $recents)->whereNull('passcode')->get();
        // dd(compact('now', 'deadline', 'recents', 'rooms'));
        $this->info('Rooms without activity: '.count($rooms));
        $this->Game = new GameController;
        foreach($rooms as $room) {
            $start = microtime(true);
            $this->__checkKick($room);
            $this->info('Analysis time: '.number_format(microtime(true) - $start, 3).' seconds');
        }
    }

    private function __checkKick($room) {
        $status = $room->analyze();
        $this->info('Room '.$room->id.': '.number_format(count($status['activities'])).' activities');
        if($status['current'] == 0 || count($status['players']) < 2 || count($status['playing']) < 2) {
            $this->info('Room '.$room->id.' is inactive');
            return; // room is inactive
        }
        if($status['deck'] == 0) {
            $this->Game->resetRoom($room);
            $this->info('Room '.$room->id.' is restarted');
            return; // restart invalid room
        }
        // auto pass
        // Action::add('pass', $room->id, ['user_id' => $status['current']]);
        $this->Game->passHand($status['current'], $status, 'timeout');
        $this->info('Auto-Pass User '.$status['current'].' on Room '.$room->id);
        $user = User::find($user_id = $status['current']);
        $actions = $user->actions()->select('action')->whereIn('action', ['timeout' , 'pass', 'play'])->orderBy('id', 'desc')->limit(PASS_KICK)->pluck('action');
        // dump(compact('user_id', 'actions'));
        // get last 3 actions, check if all are timeout
        if(count($actions) < PASS_KICK) {
            return; // no need to kick yet
        }
        foreach($actions as $action) {
            if($action != 'timeout') {
                return;
            }
        }
        $this->Game->leaveRoom($user, true);
        $this->info('Auto-Kick User '.$status['current'].' from Room '.$room->id);
        
        
    }

}

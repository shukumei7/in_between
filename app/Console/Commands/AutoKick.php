<?php

namespace App\Console\Commands;

use Illuminate\Support\Facades\DB;
use Illuminate\Console\Command;
use App\Models\Action;
use App\Models\User;
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
        $rooms = Room::get();
        $this->info('Rooms: '.count($rooms));
        $now = date('Y-m-d H:i:s');
        $time = time();
        $this->info('Time now: '.$now);
        foreach($rooms as $room) {
            $latest_time = $room->actions()->whereIn('action', ['join', 'play', 'pass', 'deal'])->orderBy('time', 'desc')->first()->time;
            $this->info('Room '.$room->id.' latest activity: '.$latest_time);
            ($time - strtotime($latest_time) >  KICK_TIMEOUT) && $this->__checkKick($room);
        }
    }

    private function __checkKick($room) {
        $status = $room->analyze();
        if($status['current'] == 0 || count($status['players']) < 2) {
            $this->info('Room '.$room->id.' is inactive');
            return; // room is inactive
        }
        Action::add('kick', $room->id, ['user_id' => $status['current']]);
        $this->info('Kick User '.User::find($status['current'])->id.' from Room '.$room->id);
    }

}

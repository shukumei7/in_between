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
        $latest_times = Action::select('room_id', DB::raw('max(time) AS max_time'))->where('time', '<', date('Y-m-d H:i:s', strtotime('-'.KICK_TIMEOUT.' seconds')))->groupBy('room_id')->pluck('max_time', 'room_id')->toArray();
        $room_ids = [];
        $this->info('Stale Rooms: '.number_format(count($latest_times)));
        foreach($latest_times as $room_id => $time) {
            $this->__checkKick($room_id);
        }
    }

    private function __checkKick($room_id) {
        $room = Room::find($room_id);
        $status = $room->analyze();
        if($status['current'] == 0 || count($status['players']) < 2) {
            $this->info('Room '.$room->name.' is inactive');
            return; // room is inactive
        }
        Action::add('kick', $room_id, ['user_id' => $status['current']]);
        $this->info('Kick '.User::find($status['current'])->name.' from Room '.$room->name);
    }

}

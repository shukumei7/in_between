<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\User;
use App\Models\Room;

class RunBots extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:run-bots';

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

    public function handle()
    {
        $this->__bots = $bots = User::where('type', 'bot')->get();
        
    }
}

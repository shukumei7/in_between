<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\DB;

use App\Models\User;
use App\Models\Room;
use App\Models\Action;

abstract class TestCase extends BaseTestCase
{
    use CreatesApplication;

    protected function _resetData() {
        DB::statement('SET FOREIGN_KEY_CHECKS=0;');
        User::truncate();
        Room::truncate();
        Action::truncate();
        DB::statement('SET FOREIGN_KEY_CHECKS=1;');
    }

    protected function _checkResponseMessage($response, $message, $code = 200) {
        if($response['message'] != $message) {
            $trace = debug_backtrace()[1]['line'];
            dd(compact('response', 'message', 'trace'));
        }
        $response->assertStatus($code);
    }

    public function assertCase($case, $context): void 
    {
        if(!$case) {
            $trace = debug_backtrace()[0]['line'];
            dd(compact('context', 'trace'));
        }
    }
}

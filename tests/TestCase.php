<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\DB;

use App\Models\User;
use App\Models\Room;
use App\Models\Action;
use App\Models\Variable;

abstract class TestCase extends BaseTestCase
{
    use CreatesApplication;

    protected function _resetData() {
        DB::statement('SET FOREIGN_KEY_CHECKS=0;');
        User::truncate();
        Room::truncate();
        Action::truncate();
        Variable::truncate();
        DB::statement('SET FOREIGN_KEY_CHECKS=1;');
    }

    protected function _checkResponseMessage($response, $message, $code = 200) {
        return $this->assertResponse($response, 'message', $message, $code);
        /*
        if($response['message'] != $message) {
            $trace = debug_backtrace()[1]['line'];
            dd(compact('response', 'message', 'trace'));
        }
        $this->assertTrue($response['message'] == $message);
        $response->assertStatus($code);
        */
    }

    public function assertCase($case, $context): void 
    {
        if(!$case) {
            $trace = debug_backtrace()[0]['line'];
            dd(compact('context', 'trace'));
        }
        $this->assertTrue($case);
    }

    public function assertResponse($response, $field, $value, $code = 200) {
        if(($return = $response->getStatusCode()) != $code) {
            $trace = debug_backtrace()[0]['line'];
            dd(compact('response', 'code', 'return', 'trace'));
        }
        if(!isset($response[$field]) || $response[$field] != $value) {
            $trace = debug_backtrace()[0]['line'];
            $expected = !isset($response[$field]) ? $field.' is not set' : $response[$field];
            dd(compact('response', 'expected', 'value', 'trace'));
        }
        $response->assertStatus($code);
        $this->assertEquals($value, $response[$field]);
    }
}

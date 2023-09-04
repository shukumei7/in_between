<?php

if(!defined('MIN_PASS_LENGTH')) {
    define('MIN_PASS_LENGTH', 8);
    define('IDENTITY_LOCK_TIME', '48 hours');
    define('TOKEN_LENGTH', 15);
    define('DEFAULT_POT', 2);
    define('RESTRICT_BET', 2);
    define('MAX_PLAYERS', 8);
    define('MAX_ROOM_BOTS', 4);
    define('SESSION_POINT_UPDATE', 'point_update');
    define('MAX_BOTS', 100);
    define('BOT_DEFEATED', -10);
}
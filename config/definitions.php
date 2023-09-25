<?php

if(!defined('MIN_PASS_LENGTH')) {
    define('MIN_PASS_LENGTH', 8);
    define('IDENTITY_LOCK_TIME', '48 hours');
    define('TOKEN_LENGTH', 15);
    define('STARTING_MONEY', 10);
    define('DEFAULT_POT', 2);
    define('MAX_POT', 10);
    define('RESTRICT_BET', 5);
    define('MAX_PLAYERS', 8);
    define('MAX_ROOM_BOTS', 4);
    define('SNAPSHOT_THRESHOLD', 0.05); // 1);
    define('SESSION_POINT_UPDATE', 'point_update');
    define('MAX_BOTS', 20);
    define('BOT_DEFEATED', -50);
    define('PASS_TIMEOUT', 60);
    define('PASS_KICK', 3);
}

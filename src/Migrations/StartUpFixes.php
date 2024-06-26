<?php

namespace TelegramApiServer\Migrations;

use danog\MadelineProto\Magic;

class StartUpFixes
{
    public static function fix(): void
    {
        define('MADELINE_WORKER_TYPE', 'madeline-ipc');
        Magic::$isIpcWorker = true;
    }
}
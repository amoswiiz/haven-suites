<?php

namespace App\Console\Commands;

use App\Services\TaskNotifications;
use Illuminate\Console\Command;

class ProcessTaskNotifications extends Command
{
    protected $signature = 'haven:task-notifications';

    protected $description = 'Create task deadline reminders and deliver opted-in task notifications';

    public function handle(TaskNotifications $notifications): int
    {
        $this->info('Processed '.$notifications->process().' task notices.');

        return self::SUCCESS;
    }
}

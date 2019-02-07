<?php

namespace Stancl\Tenancy\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Stancl\Tenancy\Interfaces\DatabaseCreator;

class QueuedDatabaseCreator implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Create a new job instance.
     *
     * @param DatabaseCreator $databaseCreator
     * @param string $databaseName
     * @return void
     */
    public function __construct(DatabaseCreator $databaseCreator, string $databaseName)
    {
        $this->databaseCreator = $databaseCreator;
        $this->databaseName = $databaseName;
    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle()
    {
        $this->databaseCreator->createDatabase($databaseName);
    }
}

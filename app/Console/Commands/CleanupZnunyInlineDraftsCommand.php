<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class CleanupZnunyInlineDraftsCommand extends Command
{
    protected $signature = 'znuny:cleanup-inline-drafts';

    protected $description = 'Clean up orphaned Znuny ticket inline image draft directories older than 24 hours.';

    public function handle(): int
    {
        $disk = Storage::disk('local');
        $baseDir = 'znuny-ticket-inline';

        if (! $disk->exists($baseDir)) {
            $this->info("Base directory {$baseDir} does not exist.");

            return self::SUCCESS;
        }

        $directories = $disk->allDirectories($baseDir);
        $cutoff = now()->subHours(24)->timestamp;
        $deletedCount = 0;

        foreach ($directories as $dir) {
            // Only process the deepest directories (draft tokens)
            // path format: znuny-ticket-inline/{userId}/{draftToken}
            $parts = explode('/', $dir);
            if (count($parts) === 3) {
                $files = $disk->allFiles($dir);
                $newestModified = null;

                foreach ($files as $file) {
                    $mtime = $disk->lastModified($file);
                    if ($newestModified === null || $mtime > $newestModified) {
                        $newestModified = $mtime;
                    }
                }

                // If no files, we can just delete it immediately
                if ($newestModified === null) {
                    $disk->deleteDirectory($dir);
                    $deletedCount++;
                } else {
                    if ($newestModified <= $cutoff) {
                        $disk->deleteDirectory($dir);
                        $deletedCount++;
                    }
                }
            }
        }

        $this->info("Deleted {$deletedCount} orphaned draft directories.");

        return self::SUCCESS;
    }
}

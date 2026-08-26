<?php

use App\Jobs\PublishPostJob;
use App\Models\ScheduledPost;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::call(function () {
    $dueScheduledPosts = ScheduledPost::where('scheduled_at', '<=', now())
        ->where('status', 'pending')
        ->with('post')
        ->limit(50)
        ->get();

    foreach ($dueScheduledPosts as $scheduledPost) {
        if ($scheduledPost->post) {
            $scheduledPost->update(['status' => 'processing']);
            PublishPostJob::dispatch($scheduledPost->post);
        }
    }
})->everyMinute()->name('process-scheduled-posts');


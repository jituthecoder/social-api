<?php

use App\Jobs\PublishPostJob;
use App\Jobs\RefreshSocialTokenJob;
use App\Models\ScheduledPost;
use App\Models\SocialAccount;
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

/*
|--------------------------------------------------------------------------
| Token Refresh Schedule
|--------------------------------------------------------------------------
|
| Proactively refresh tokens that will expire within the next 7 days.
| This prevents publishing failures due to stale credentials.
|
*/
Schedule::call(function () {
    $expiringAccounts = SocialAccount::where('connection_status', 'connected')
        ->whereHas('token', function ($query) {
            $query->where('expires_at', '<=', now()->addDays(7))
                  ->where('expires_at', '>', now());
        })
        ->get();

    foreach ($expiringAccounts as $account) {
        RefreshSocialTokenJob::dispatch($account);
    }
})->dailyAt('03:00')->name('refresh-expiring-social-tokens');



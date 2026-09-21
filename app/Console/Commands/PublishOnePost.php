<?php

namespace App\Console\Commands;

use App\Models\Post;
use App\Services\PublishingService;
use Illuminate\Console\Command;

class PublishOnePost extends Command
{
    protected $signature = 'post:publish-one {id}';
    protected $description = 'Publish a single post in an isolated background process';

    public function handle(PublishingService $service): int
    {
        $id = $this->argument('id');
        $post = Post::find($id);

        if (!$post) {
            $this->error("Post {$id} not found.");
            return 1;
        }

        $this->info("Publishing post {$id} in background process...");
        $success = $service->publishPost($post);

        $this->info("Result: " . ($success ? 'SUCCESS' : 'FAILED'));
        return $success ? 0 : 1;
    }
}

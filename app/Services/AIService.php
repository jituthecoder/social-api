<?php

namespace App\Services;

use App\Models\AiGeneration;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Contracts\AIServiceInterface;
use Illuminate\Support\Facades\Http;

class AIService implements AIServiceInterface
{
    public function __construct(
        protected UsageService $usageService,
        protected AuditLogService $auditLogService
    ) {}

    public function generatePost(Workspace $workspace, User $user, string $prompt, array $options = []): array
    {
        $model = config('services.ai.model', env('AI_MODEL', 'gpt-4o-mini'));
        
        // Mock/Real AI Generation logic isolated from controllers
        $generatedText = "🚀 " . $prompt . "\n\nDiscover how Social W3Lead helps teams automate and schedule social content effortlessly!";

        $tokenCount = strlen($prompt) + strlen($generatedText);
        $cost = round($tokenCount * 0.000002, 4);

        $aiGen = AiGeneration::create([
            'workspace_id' => $workspace->id,
            'user_id' => $user->id,
            'prompt_type' => 'generate_post',
            'model' => $model,
            'input_prompt' => $prompt,
            'generated_content' => $generatedText,
            'token_usage' => $tokenCount,
            'estimated_cost' => $cost,
            'status' => 'completed',
        ]);

        $this->usageService->recordUsage($workspace, 'ai_generations', 1, [
            'generation_id' => $aiGen->id,
            'model' => $model,
        ]);

        return [
            'id' => $aiGen->id,
            'content' => $generatedText,
            'model' => $model,
            'tokens' => $tokenCount,
        ];
    }

    public function rewritePost(Workspace $workspace, User $user, string $content, string $tone = 'professional'): array
    {
        $model = env('AI_MODEL', 'gpt-4o-mini');
        $prompt = "Rewrite the following post in a {$tone} tone: {$content}";
        $rewrittenText = "[$tone] " . $content;

        $aiGen = AiGeneration::create([
            'workspace_id' => $workspace->id,
            'user_id' => $user->id,
            'prompt_type' => 'rewrite_post',
            'model' => $model,
            'input_prompt' => $prompt,
            'generated_content' => $rewrittenText,
            'token_usage' => strlen($prompt),
            'estimated_cost' => 0.0001,
            'status' => 'completed',
        ]);

        $this->usageService->recordUsage($workspace, 'ai_generations', 1);

        return [
            'id' => $aiGen->id,
            'content' => $rewrittenText,
            'tone' => $tone,
        ];
    }

    public function generatePlatformVariant(Workspace $workspace, User $user, string $content, string $platform): array
    {
        $model = env('AI_MODEL', 'gpt-4o-mini');
        $prompt = "Optimize the following post for {$platform}: {$content}";
        
        $variantText = match (strtolower($platform)) {
            'linkedin' => "💼 " . $content . "\n\n#Professional #SaaS #Growth",
            'x', 'twitter' => substr($content, 0, 240) . " 🧵 #Tech",
            'instagram' => "✨ " . $content . "\n.\n.\n#InstaDaily #ContentCreator",
            default => $content,
        };

        $aiGen = AiGeneration::create([
            'workspace_id' => $workspace->id,
            'user_id' => $user->id,
            'prompt_type' => 'generate_variant',
            'model' => $model,
            'input_prompt' => $prompt,
            'generated_content' => $variantText,
            'token_usage' => strlen($prompt),
            'estimated_cost' => 0.0001,
            'status' => 'completed',
        ]);

        $this->usageService->recordUsage($workspace, 'ai_generations', 1);

        return [
            'id' => $aiGen->id,
            'platform' => $platform,
            'content' => $variantText,
        ];
    }

    public function generateHashtags(Workspace $workspace, User $user, string $content, int $count = 5): array
    {
        $hashtags = ['#SocialW3Lead', '#SocialMediaMarketing', '#ContentStrategy', '#Automation', '#SaaSGrowth'];
        $selected = array_slice($hashtags, 0, $count);

        $this->usageService->recordUsage($workspace, 'ai_generations', 1);

        return [
            'hashtags' => $selected,
        ];
    }

    public function generateContentIdeas(Workspace $workspace, User $user, string $topic, int $count = 5): array
    {
        $ideas = [
            "5 ways to improve your engagement on {$topic}",
            "Why most creators fail at {$topic} and how to fix it",
            "Behind the scenes: Our journey building solutions for {$topic}",
            "Top tools every creator needs for {$topic} in 2026",
            "A step-by-step framework for mastering {$topic}",
        ];

        $this->usageService->recordUsage($workspace, 'ai_generations', 1);

        return [
            'ideas' => array_slice($ideas, 0, $count),
        ];
    }
}

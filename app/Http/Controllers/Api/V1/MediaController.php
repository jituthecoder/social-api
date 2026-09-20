<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Api\ApiResponseTrait;
use App\Models\Media;
use App\Models\Workspace;
use App\Services\MediaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class MediaController extends Controller
{
    use ApiResponseTrait;

    public function __construct(
        protected MediaService $mediaService
    ) {}

    protected function resolveWorkspace(Request $request): Workspace
    {
        $workspaceId = $request->header('X-Workspace-Id') ?? $request->query('workspace_id');
        if ($workspaceId) {
            $workspace = Workspace::findOrFail($workspaceId);
            Gate::authorize('view', $workspace);
            return $workspace;
        }

        return $request->user()->workspaces()->firstOrFail();
    }

    public function index(Request $request): JsonResponse
    {
        $workspace = $this->resolveWorkspace($request);
        $media = Media::where('workspace_id', $workspace->id)
            ->orderBy('created_at', 'desc')
            ->paginate($request->query('per_page', 20));

        return $this->successResponse($media, 'Workspace media items retrieved');
    }

    public function store(Request $request): JsonResponse
    {
        $workspace = $this->resolveWorkspace($request);

        $request->validate([
            'file' => [
                'required',
                'file',
                'max:51200', // 50MB max
                function ($attribute, $value, $fail) {
                    $mime = $value->getMimeType() ?? '';
                    $ext  = strtolower($value->getClientOriginalExtension() ?? '');

                    $allowedExts = [
                        'jpeg', 'jpg', 'png', 'gif', 'webp', 'jfif', 'avif', 'svg', 'heic', 'heif', 'bmp',
                        'mp4', 'mov', 'avi', 'webm', 'mkv', 'm4v', 'wmv'
                    ];

                    $isMediaMime = str_starts_with($mime, 'image/') || 
                                   str_starts_with($mime, 'video/') || 
                                   $mime === 'application/octet-stream';

                    if (!in_array($ext, $allowedExts) && !$isMediaMime) {
                        $fail('The file must be an image (JPG, PNG, WebP, GIF, AVIF) or a video (MP4, MOV, AVI).');
                    }
                },
            ],
        ]);

        $media = $this->mediaService->uploadMedia(
            $workspace,
            $request->user(),
            $request->file('file')
        );

        return $this->successResponse($media, 'File uploaded successfully', 201);
    }

    public function destroy(Request $request, Media $media): JsonResponse
    {
        $workspace = $this->resolveWorkspace($request);
        Gate::authorize('delete', [$media, $workspace]);

        $this->mediaService->deleteMedia($media, $workspace, $request->user());

        return $this->successResponse(null, 'Media file deleted successfully');
    }
}

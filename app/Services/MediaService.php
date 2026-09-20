<?php

namespace App\Services;

use App\Models\Media;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class MediaService
{
    public function __construct(
        protected AuditLogService $auditLogService
    ) {}

    public function uploadMedia(Workspace $workspace, User $user, UploadedFile $file): Media
    {
        $disk = config('filesystems.default', 'public');
        $mimeType = $file->getMimeType() ?? 'application/octet-stream';
        $extension = $file->getClientOriginalExtension();
        $filename = Str::uuid() . '.' . $extension;

        $folder = 'workspaces/' . $workspace->id . '/' . (str_starts_with($mimeType, 'video/') ? 'videos' : 'images');
        $path = $file->storeAs($folder, $filename, $disk);

        // Generate URL for asset
        $url = Storage::disk($disk)->url($path);
        if ($disk === 'public' && !str_starts_with($url, 'http')) {
            $url = rtrim(config('app.url', 'http://localhost:8000'), '/') . '/' . ltrim($url, '/');
        }

        $media = Media::create([
            'workspace_id' => $workspace->id,
            'user_id' => $user->id,
            'filename' => $filename,
            'original_name' => $file->getClientOriginalName(),
            'mime_type' => $mimeType,
            'size' => $file->getSize(),
            'path' => $path,
            'url' => $url,
            'processing_status' => 'ready',
            'metadata' => [
                'disk' => $disk,
                'extension' => $extension,
                'local_path' => $disk === 'public' ? storage_path('app/public/' . $path) : null,
            ],
        ]);

        $this->auditLogService->log('media.uploaded', $workspace, $user, [
            'media_id' => $media->id,
            'filename' => $media->filename,
            'size' => $media->size,
        ]);

        return $media;
    }

    public function deleteMedia(Media $media, Workspace $workspace, User $user): bool
    {
        $disk = $media->metadata['disk'] ?? config('filesystems.default', 'public');
        if (Storage::disk($disk)->exists($media->path)) {
            Storage::disk($disk)->delete($media->path);
        }

        $this->auditLogService->log('media.deleted', $workspace, $user, ['media_id' => $media->id]);

        return $media->delete();
    }
}

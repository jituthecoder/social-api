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
        $mimeType = $file->getMimeType() ?? 'application/octet-stream';
        $extension = $file->getClientOriginalExtension();
        $filename = Str::uuid() . '.' . $extension;

        $folder = 'workspaces/' . $workspace->id . '/' . (str_starts_with($mimeType, 'video/') ? 'videos' : 'images');
        $path = $file->storeAs($folder, $filename, 's3');

        // Generate public or temporary URL for S3 asset
        $url = config('filesystems.disks.s3.url') 
            ? config('filesystems.disks.s3.url') . '/' . $path 
            : Storage::disk('s3')->url($path);

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
                'extension' => $extension,
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
        if (Storage::disk('s3')->exists($media->path)) {
            Storage::disk('s3')->delete($media->path);
        }

        $this->auditLogService->log('media.deleted', $workspace, $user, ['media_id' => $media->id]);

        return $media->delete();
    }
}

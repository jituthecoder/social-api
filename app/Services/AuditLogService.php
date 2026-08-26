<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\User;
use App\Models\Workspace;

class AuditLogService
{
    public function log(string $action, ?Workspace $workspace = null, ?User $user = null, ?array $payload = []): AuditLog
    {
        $sanitizedPayload = $this->sanitizePayload($payload ?? []);

        return AuditLog::create([
            'workspace_id' => $workspace?->id,
            'user_id' => $user?->id ?? auth()->id(),
            'action' => $action,
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent(),
            'payload' => $sanitizedPayload,
            'created_at' => now(),
        ]);
    }

    protected function sanitizePayload(array $payload): array
    {
        $sensitiveKeys = ['password', 'password_confirmation', 'access_token', 'refresh_token', 'token', 'secret', 'api_key'];

        foreach ($payload as $key => $value) {
            if (in_array(strtolower($key), $sensitiveKeys)) {
                $payload[$key] = '[REDACTED]';
            } elseif (is_array($value)) {
                $payload[$key] = $this->sanitizePayload($value);
            }
        }

        return $payload;
    }
}

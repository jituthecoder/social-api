<?php

namespace App\Http\Requests\Post;

use Illuminate\Foundation\Http\FormRequest;

class CreatePostRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'title' => ['nullable', 'string', 'max:255'],
            'content' => ['nullable', 'string'],
            'scheduled_at' => ['nullable', 'date'],
            'is_scheduled' => ['nullable', 'boolean'],
            'publish_now' => ['nullable', 'boolean'],
            'social_account_ids' => ['nullable', 'array'],
            'social_account_ids.*' => ['integer', 'exists:social_accounts,id'],
            'variants' => ['nullable', 'array'],
            'variants.*.platform' => ['required_with:variants', 'string'],
            'variants.*.content' => ['nullable', 'string'],
            'variants.*.hashtags' => ['nullable', 'array'],
            'variants.*.metadata' => ['nullable', 'array'],
            'variants.*.social_account_id' => ['nullable', 'integer'],
            'media_ids' => ['nullable', 'array'],
            'media_ids.*' => ['integer', 'exists:media,id'],
        ];
    }
}

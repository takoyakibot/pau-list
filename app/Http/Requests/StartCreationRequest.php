<?php

namespace App\Http\Requests;

use App\Support\QuotaPolicy;
use App\Support\TextInput;
use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /p/{key}/creation (JSON): what the owner confirmed on S5. Text is normalized exactly like a proposal
 * save (TextInput) and the messages are the same; a failure is the FormRequest's 422 JSON.
 */
class StartCreationRequest extends FormRequest
{
    public const PRIVACY = ['private', 'unlisted', 'public'];

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $clean = [];
        if ($this->has('title')) {
            $clean['title'] = TextInput::singleLine($this->input('title'));
        }
        if ($this->has('description')) {
            $clean['description'] = TextInput::multiLine($this->input('description'));
        }
        $this->merge($clean);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:150'],
            'description' => ['nullable', 'string', 'max:1000'],
            'privacy' => ['required', 'string', 'in:'.implode(',', self::PRIVACY)],
            'video_ids' => ['required', 'array', 'min:1', 'max:'.QuotaPolicy::maxItemsPerPlaylist()],
            'video_ids.*' => ['required', 'string', 'distinct', 'regex:/^[A-Za-z0-9_-]{11}$/D'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'video_ids.required' => '動画を 1 本以上選んでください',
            'video_ids.array' => '動画を 1 本以上選んでください',
            'video_ids.min' => '動画を 1 本以上選んでください',
            'video_ids.max' => '1 つの再生リストに入れられるのは最大 :max 本です',
            'video_ids.*.required' => '動画 ID の形式が正しくありません',
            'video_ids.*.string' => '動画 ID の形式が正しくありません',
            'video_ids.*.distinct' => '同じ動画が重複しています',
            'video_ids.*.regex' => '動画 ID の形式が正しくありません',
            'title.required' => '再生リスト名を入力してください',
            // No default (D46): a missing choice is asked for, not filled in.
            'privacy.required' => '公開範囲を選んでください',
            'privacy.string' => '公開範囲の値が正しくありません',
            'privacy.in' => '公開範囲の値が正しくありません',
            'string' => ':attribute の形式が正しくありません',
            'max' => ':attribute は :max 文字以内で入力してください',
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'title' => '再生リスト名',
            'description' => '再生リストの説明',
        ];
    }
}

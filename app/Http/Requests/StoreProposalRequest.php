<?php

namespace App\Http\Requests;

use App\Support\QuotaPolicy;
use App\Support\TextInput;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

class StoreProposalRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $clean = [];
        foreach (['title', 'proposer_name'] as $field) {
            if ($this->has($field)) {
                $clean[$field] = TextInput::singleLine($this->input($field));
            }
        }
        foreach (['description', 'comment'] as $field) {
            if ($this->has($field)) {
                $clean[$field] = TextInput::multiLine($this->input($field));
            }
        }
        $this->merge($clean);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:150'],
            'description' => ['nullable', 'string', 'max:1000'],
            'comment' => ['nullable', 'string', 'max:500'],
            'proposer_name' => ['nullable', 'string', 'max:30'],
            'video_ids' => ['required', 'array', 'min:1', 'max:'.QuotaPolicy::maxItemsPerPlaylist()],
            'video_ids.*' => ['required', 'string', 'distinct', 'regex:/^[A-Za-z0-9_-]{11}$/D'],
            'website' => ['nullable', 'string'],
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
            'comment' => 'チャンネル主へのコメント',
            'proposer_name' => 'ニックネーム',
        ];
    }

    /**
     * Always return to the propose screen: with Referrer-Policy no-referrer the "previous URL"
     * can be a JSON lookup request.
     */
    protected function getRedirectUrl(): string
    {
        return route('channels.propose', (string) $this->route('channelId'));
    }

    /**
     * A filled honeypot gets the same silent redirect even when the rest is invalid.
     */
    protected function failedValidation(Validator $validator): void
    {
        if ($this->isHoneypotHit()) {
            throw new HttpResponseException(redirect()->route('home'));
        }

        parent::failedValidation($validator);
    }

    public function isHoneypotHit(): bool
    {
        $value = $this->input('website');

        return $value !== null && $value !== '';
    }
}

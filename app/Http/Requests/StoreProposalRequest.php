<?php

namespace App\Http\Requests;

use App\Support\QuotaPolicy;
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
                $clean[$field] = self::singleLine($this->input($field));
            }
        }
        foreach (['description', 'comment'] as $field) {
            if ($this->has($field)) {
                $clean[$field] = self::multiLine($this->input($field));
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
            'description' => '説明',
            'comment' => 'チャンネル主へのコメント',
            'proposer_name' => 'ニックネーム',
        ];
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

    private static function singleLine(mixed $value): mixed
    {
        if (! is_string($value)) {
            return $value;
        }

        return self::finish(preg_replace('/\p{Cc}/u', '', mb_scrub($value, 'UTF-8')));
    }

    private static function multiLine(mixed $value): mixed
    {
        if (! is_string($value)) {
            return $value;
        }
        $value = str_replace(["\r\n", "\r"], "\n", mb_scrub($value, 'UTF-8'));

        return self::finish(preg_replace('/[^\P{Cc}\n\t]/u', '', $value));
    }

    private static function finish(?string $value): ?string
    {
        $value = preg_replace('/^[\s\x{3000}]+|[\s\x{3000}]+$/u', '', (string) $value);

        return $value === null || $value === '' ? null : $value;
    }
}

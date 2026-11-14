<?php

namespace App\Http\Requests;

use App\Support\ContactLimits;
use App\Support\TextInput;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\ViewErrorBag;
use Illuminate\Validation\Rule;

/**
 * The contact form (S8, spec 8.2 / 8.7). Its input is never stored: a validation error renders the form
 * directly (no redirect, so nothing is flashed to the session).
 */
class ContactRequest extends FormRequest
{
    public const KINDS = ['general' => '一般', 'deletion' => '削除依頼', 'bug' => '不具合'];

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $clean = [];
        if ($this->has('body')) {
            $clean['body'] = TextInput::multiLine($this->input('body'));
        }
        // Only surrounding spaces: a line break must reach the validator and be rejected (header injection).
        $address = $this->input('reply_address');
        if (is_string($address)) {
            $address = preg_replace('/^[ \t\x{3000}]+|[ \t\x{3000}]+$/u', '', $address);
            $clean['reply_address'] = $address === '' ? null : $address;
        }
        $this->merge($clean);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'kind' => ['required', 'string', Rule::in(array_keys(self::KINDS))],
            'body' => ['required', 'string', 'max:2000'],
            'reply_address' => ['nullable', 'string', 'max:254', 'not_regex:/[\r\n]/', 'email:rfc'],
            'website' => ['nullable', 'string'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'kind.required' => '種別を選んでください',
            'kind.in' => '種別を選んでください',
            'body.required' => '本文を入力してください',
            'reply_address.not_regex' => '返信用メールアドレス の形式が正しくありません',
            'reply_address.email' => '返信用メールアドレス の形式が正しくありません',
            'string' => ':attribute の形式が正しくありません',
            'max' => ':attribute は :max 文字以内で入力してください',
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'kind' => '種別',
            'body' => '本文',
            'reply_address' => '返信用メールアドレス',
        ];
    }

    /**
     * A filled honeypot gets the same redirect as a successful send, even when the rest is invalid.
     * Any other error renders the form with the input (never flashed to the session).
     */
    protected function failedValidation(Validator $validator): void
    {
        if ($this->isHoneypotHit()) {
            app(ContactLimits::class)->hit($this);

            throw new HttpResponseException(redirect()->route('contact.show')->with('contact_sent', true));
        }

        throw new HttpResponseException(response()->view('contact.show', [
            'input' => $this->formInput(),
            'errors' => (new ViewErrorBag)->put('default', $validator->errors()),
        ], 422));
    }

    public function isHoneypotHit(): bool
    {
        $value = $this->input('website');

        return $value !== null && $value !== '';
    }

    /**
     * The values to put back into the form (strings only; the honeypot is never echoed).
     *
     * @return array{kind?: string, body?: string, reply_address?: string}
     */
    public function formInput(): array
    {
        return array_filter($this->only(['kind', 'body', 'reply_address']), 'is_string');
    }
}

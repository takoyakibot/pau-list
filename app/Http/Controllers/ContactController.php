<?php

namespace App\Http\Controllers;

use App\Http\Requests\ContactRequest;
use App\Mail\ContactMessage;
use App\Support\ContactLimits;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\View\View;
use Throwable;

/**
 * S8 (spec 8.7): the message is mailed to CONTACT_TO_ADDRESS and never stored (database, session or logs).
 */
class ContactController extends Controller
{
    public const SENT = 'お問い合わせを送信しました';

    public const FAILED = '送信できませんでした。時間をおいて再度お試しください';

    public const LIMITED = '送信が集中しています。しばらく待ってから再度お試しください';

    public function show(): View
    {
        return view('contact.show', ['input' => []]);
    }

    public function store(ContactRequest $request, ContactLimits $limits): RedirectResponse|Response
    {
        if ($request->isHoneypotHit()) {
            $limits->hit($request);

            return $this->sent();
        }

        $input = $request->formInput();
        $wait = $limits->retryAfter($request);
        if ($wait !== null) {
            Log::info('Rate limit exceeded', ['limiter' => 'contact']);

            return $this->form($input, self::LIMITED, 429)->header('Retry-After', (string) $wait);
        }

        $to = config('listmaker.contact_to_address');
        if (! filled($to)) {
            Log::error('Contact mail not configured');

            return $this->form($input, self::FAILED, 503);
        }

        $kind = (string) $request->validated('kind');
        $message = new ContactMessage(
            $kind,
            (string) $request->validated('body'),
            $request->validated('reply_address'),
            CarbonImmutable::now(),
        );

        try {
            Mail::to((string) $to)->send($message);
        } catch (Throwable $e) {
            $limits->hit($request);
            // The exception message can contain addresses or SMTP replies: log its class only (spec 8.8).
            Log::error('Contact mail failed', ['exception' => $e::class]);

            return $this->form($input, self::FAILED, 503);
        }

        $limits->hit($request);
        Log::info('Contact sent', ['kind' => $kind]);

        return $this->sent();
    }

    private function sent(): RedirectResponse
    {
        return redirect()->route('contact.show')->with('contact_sent', true);
    }

    /** @param array<string, string> $input */
    private function form(array $input, string $notice, int $status): Response
    {
        return response()->view('contact.show', ['input' => $input, 'notice' => $notice], $status);
    }
}

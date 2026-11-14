<?php

namespace App\Mail;

use App\Http\Requests\ContactRequest;
use Carbon\CarbonImmutable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Envelope;

/**
 * A contact form message to the operator (spec 8.7): plain text only, fixed subject, no Reply-To.
 * User input goes into the text body only, never into a header.
 */
class ContactMessage extends Mailable
{
    public function __construct(
        public readonly string $kind,
        public readonly string $body,
        public readonly ?string $replyAddress,
        public readonly CarbonImmutable $sentAt,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: '[List Maker] お問い合わせ（'.ContactRequest::KINDS[$this->kind].'）');
    }

    public function plainBody(): string
    {
        $text = '種別: '.ContactRequest::KINDS[$this->kind]."\n"
            .'送信日時: '.$this->sentAt->setTimezone('Asia/Tokyo')->format('Y年n月j日 H:i')."（日本時間）\n"
            ."\n"
            .$this->body."\n";
        if ($this->replyAddress !== null && $this->replyAddress !== '') {
            $text .= "\n返信用メールアドレス: ".$this->replyAddress."\n";
        }

        return $text;
    }

    /** A raw text part: the body never passes through Blade, so nothing is escaped or interpreted. */
    protected function buildView(): array
    {
        return ['raw' => $this->plainBody()];
    }
}

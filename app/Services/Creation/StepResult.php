<?php

namespace App\Services\Creation;

/**
 * The outcome of one CreationRunner::step(): how long the page waits before the next step (0 = stop),
 * an optional message, whether Google refused the owner's token (the caller logs the owner out), and an
 * optional relative redirect ('edit' after the creation was deleted because YouTube rejected the input).
 */
final readonly class StepResult
{
    public function __construct(
        public int $nextDelayMs,
        public ?string $message = null,
        public bool $authExpired = false,
        public ?string $redirect = null,
    ) {}
}

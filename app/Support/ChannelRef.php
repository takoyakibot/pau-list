<?php

namespace App\Support;

final class ChannelRef
{
    private function __construct(
        public readonly string $type,
        public readonly string $value,
    ) {}

    public static function id(string $id): self
    {
        return new self('id', $id);
    }

    public static function handle(string $handle): self
    {
        return new self('handle', mb_strtolower(ltrim($handle, '@')));
    }

    public function isId(): bool
    {
        return $this->type === 'id';
    }
}

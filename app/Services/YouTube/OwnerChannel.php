<?php

namespace App\Services\YouTube;

/** The logged-in owner's own channel (channels.list mine=true). */
final readonly class OwnerChannel
{
    public const MAX_TITLE = 200;

    public string $title;

    public function __construct(public string $id, string $title)
    {
        $this->title = mb_substr($title, 0, self::MAX_TITLE);
    }
}

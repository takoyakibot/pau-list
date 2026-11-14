<?php

namespace App\Services\YouTube;

enum YouTubeErrorKind: string
{
    case Transient = 'transient';
    case GoogleQuota = 'google_quota';
    case Auth = 'auth';
    case ItemPermanent = 'item_permanent';
    case InvalidInput = 'invalid_input';
    case Unknown = 'unknown';
}

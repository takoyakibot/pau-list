<?php

namespace App\Services;

use RuntimeException;

/** The channel no longer exists on YouTube (found while refreshing stale channel metadata at save). */
class ChannelUnavailable extends RuntimeException {}

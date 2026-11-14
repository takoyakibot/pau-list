<?php

namespace App\Session;

use Illuminate\Session\DatabaseSessionHandler;

/**
 * Database sessions without the IP address and user agent columns (spec: no IP in the DB).
 */
class PrivacyDatabaseSessionHandler extends DatabaseSessionHandler
{
    protected function addRequestInformation(&$payload)
    {
        return $this;
    }
}

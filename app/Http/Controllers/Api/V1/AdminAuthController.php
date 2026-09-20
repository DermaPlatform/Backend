<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\Portal;

/**
 * The platform back office.
 */
class AdminAuthController extends PortalAuthController
{
    protected function portal(): Portal
    {
        return Portal::Admin;
    }
}

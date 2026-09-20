<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\Portal;

/**
 * The clinic dashboard, shared by a doctor and their secretary.
 */
class ClinicAuthController extends PortalAuthController
{
    protected function portal(): Portal
    {
        return Portal::Clinic;
    }
}

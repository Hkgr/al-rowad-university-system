<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\OrganizationalUnitType\StoreOrganizationalUnitTypeRequest;
use App\Http\Requests\OrganizationalUnitType\UpdateOrganizationalUnitTypeRequest;
use App\Http\Resources\OrganizationalUnitTypeResource;
use App\Models\OrganizationalUnitType;

class OrganizationalUnitTypeController extends ApiController
{
    protected function modelClass(): string
    {
        return OrganizationalUnitType::class;
    }

    protected function resourceClass(): string
    {
        return OrganizationalUnitTypeResource::class;
    }

    protected function storeRequestClass(): string
    {
        return StoreOrganizationalUnitTypeRequest::class;
    }

    protected function updateRequestClass(): string
    {
        return UpdateOrganizationalUnitTypeRequest::class;
    }
}

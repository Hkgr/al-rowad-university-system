<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\OrganizationalUnit\StoreOrganizationalUnitRequest;
use App\Http\Requests\OrganizationalUnit\UpdateOrganizationalUnitRequest;
use App\Http\Resources\OrganizationalUnitResource;
use App\Models\OrganizationalUnit;

class OrganizationalUnitController extends ApiController
{
    protected function modelClass(): string
    {
        return OrganizationalUnit::class;
    }

    protected function resourceClass(): string
    {
        return OrganizationalUnitResource::class;
    }

    protected function storeRequestClass(): string
    {
        return StoreOrganizationalUnitRequest::class;
    }

    protected function updateRequestClass(): string
    {
        return UpdateOrganizationalUnitRequest::class;
    }
}

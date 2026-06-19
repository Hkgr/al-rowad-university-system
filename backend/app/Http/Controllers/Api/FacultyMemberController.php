<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\FacultyMember\StoreFacultyMemberRequest;
use App\Http\Requests\FacultyMember\UpdateFacultyMemberRequest;
use App\Http\Resources\FacultyMemberResource;
use App\Models\FacultyMember;

class FacultyMemberController extends ApiController
{
    protected function modelClass(): string
    {
        return FacultyMember::class;
    }

    protected function resourceClass(): string
    {
        return FacultyMemberResource::class;
    }

    protected function storeRequestClass(): string
    {
        return StoreFacultyMemberRequest::class;
    }

    protected function updateRequestClass(): string
    {
        return UpdateFacultyMemberRequest::class;
    }
}

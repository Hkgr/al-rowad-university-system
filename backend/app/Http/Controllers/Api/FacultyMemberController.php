<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\FacultyMember\StoreFacultyMemberRequest;
use App\Http\Requests\FacultyMember\UpdateFacultyMemberRequest;
use App\Http\Resources\FacultyMemberResource;
use App\Models\FacultyMember;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FacultyMemberController extends ApiController
{
    public function me(Request $request): JsonResponse
    {
        $facultyMember = FacultyMember::query()
            ->where('employee_id', $request->user()->employee_id)
            ->first();

        return $this->successResponse(
            $facultyMember ? new FacultyMemberResource($facultyMember) : null
        );
    }

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

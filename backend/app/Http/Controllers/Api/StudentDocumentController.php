<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\StudentDocument\StoreStudentDocumentRequest;
use App\Http\Requests\StudentDocument\UpdateStudentDocumentRequest;
use App\Http\Resources\StudentDocumentResource;
use App\Models\StudentDocument;

class StudentDocumentController extends ApiController
{
    protected function modelClass(): string
    {
        return StudentDocument::class;
    }

    protected function resourceClass(): string
    {
        return StudentDocumentResource::class;
    }

    protected function storeRequestClass(): string
    {
        return StoreStudentDocumentRequest::class;
    }

    protected function updateRequestClass(): string
    {
        return UpdateStudentDocumentRequest::class;
    }
}

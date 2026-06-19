<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\DocumentType\StoreDocumentTypeRequest;
use App\Http\Requests\DocumentType\UpdateDocumentTypeRequest;
use App\Http\Resources\DocumentTypeResource;
use App\Models\DocumentType;

class DocumentTypeController extends ApiController
{
    protected function modelClass(): string
    {
        return DocumentType::class;
    }

    protected function resourceClass(): string
    {
        return DocumentTypeResource::class;
    }

    protected function storeRequestClass(): string
    {
        return StoreDocumentTypeRequest::class;
    }

    protected function updateRequestClass(): string
    {
        return UpdateDocumentTypeRequest::class;
    }
}

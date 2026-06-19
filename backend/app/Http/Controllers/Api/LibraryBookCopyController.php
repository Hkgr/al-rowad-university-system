<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\LibraryBookCopy\StoreLibraryBookCopyRequest;
use App\Http\Requests\LibraryBookCopy\UpdateLibraryBookCopyRequest;
use App\Http\Resources\LibraryBookCopyResource;
use App\Models\LibraryBookCopy;

class LibraryBookCopyController extends ApiController
{
    protected function modelClass(): string
    {
        return LibraryBookCopy::class;
    }

    protected function resourceClass(): string
    {
        return LibraryBookCopyResource::class;
    }

    protected function storeRequestClass(): string
    {
        return StoreLibraryBookCopyRequest::class;
    }

    protected function updateRequestClass(): string
    {
        return UpdateLibraryBookCopyRequest::class;
    }
}

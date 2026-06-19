<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\LibraryAuthor\StoreLibraryAuthorRequest;
use App\Http\Requests\LibraryAuthor\UpdateLibraryAuthorRequest;
use App\Http\Resources\LibraryAuthorResource;
use App\Models\LibraryAuthor;

class LibraryAuthorController extends ApiController
{
    protected function modelClass(): string
    {
        return LibraryAuthor::class;
    }

    protected function resourceClass(): string
    {
        return LibraryAuthorResource::class;
    }

    protected function storeRequestClass(): string
    {
        return StoreLibraryAuthorRequest::class;
    }

    protected function updateRequestClass(): string
    {
        return UpdateLibraryAuthorRequest::class;
    }
}

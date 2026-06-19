<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\LibraryBookAuthor\StoreLibraryBookAuthorRequest;
use App\Http\Requests\LibraryBookAuthor\UpdateLibraryBookAuthorRequest;
use App\Http\Resources\LibraryBookAuthorResource;
use App\Models\LibraryBookAuthor;

class LibraryBookAuthorController extends ApiController
{
    protected function modelClass(): string
    {
        return LibraryBookAuthor::class;
    }

    protected function resourceClass(): string
    {
        return LibraryBookAuthorResource::class;
    }

    protected function storeRequestClass(): string
    {
        return StoreLibraryBookAuthorRequest::class;
    }

    protected function updateRequestClass(): string
    {
        return UpdateLibraryBookAuthorRequest::class;
    }
}

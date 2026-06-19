<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\LibraryBook\StoreLibraryBookRequest;
use App\Http\Requests\LibraryBook\UpdateLibraryBookRequest;
use App\Http\Resources\LibraryBookResource;
use App\Models\LibraryBook;

class LibraryBookController extends ApiController
{
    protected function modelClass(): string
    {
        return LibraryBook::class;
    }

    protected function resourceClass(): string
    {
        return LibraryBookResource::class;
    }

    protected function storeRequestClass(): string
    {
        return StoreLibraryBookRequest::class;
    }

    protected function updateRequestClass(): string
    {
        return UpdateLibraryBookRequest::class;
    }
}

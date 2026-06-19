<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\LibraryCategory\StoreLibraryCategoryRequest;
use App\Http\Requests\LibraryCategory\UpdateLibraryCategoryRequest;
use App\Http\Resources\LibraryCategoryResource;
use App\Models\LibraryCategory;

class LibraryCategoryController extends ApiController
{
    protected function modelClass(): string
    {
        return LibraryCategory::class;
    }

    protected function resourceClass(): string
    {
        return LibraryCategoryResource::class;
    }

    protected function storeRequestClass(): string
    {
        return StoreLibraryCategoryRequest::class;
    }

    protected function updateRequestClass(): string
    {
        return UpdateLibraryCategoryRequest::class;
    }
}

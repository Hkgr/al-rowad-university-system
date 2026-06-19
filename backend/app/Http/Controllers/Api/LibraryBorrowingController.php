<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\LibraryBorrowing\StoreLibraryBorrowingRequest;
use App\Http\Requests\LibraryBorrowing\UpdateLibraryBorrowingRequest;
use App\Http\Resources\LibraryBorrowingResource;
use App\Models\LibraryBorrowing;

class LibraryBorrowingController extends ApiController
{
    protected function modelClass(): string
    {
        return LibraryBorrowing::class;
    }

    protected function resourceClass(): string
    {
        return LibraryBorrowingResource::class;
    }

    protected function storeRequestClass(): string
    {
        return StoreLibraryBorrowingRequest::class;
    }

    protected function updateRequestClass(): string
    {
        return UpdateLibraryBorrowingRequest::class;
    }
}

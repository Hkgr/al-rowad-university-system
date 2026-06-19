<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\BoardDecisionAttachment\StoreBoardDecisionAttachmentRequest;
use App\Http\Requests\BoardDecisionAttachment\UpdateBoardDecisionAttachmentRequest;
use App\Http\Resources\BoardDecisionAttachmentResource;
use App\Models\BoardDecisionAttachment;

class BoardDecisionAttachmentController extends ApiController
{
    protected function modelClass(): string
    {
        return BoardDecisionAttachment::class;
    }

    protected function resourceClass(): string
    {
        return BoardDecisionAttachmentResource::class;
    }

    protected function storeRequestClass(): string
    {
        return StoreBoardDecisionAttachmentRequest::class;
    }

    protected function updateRequestClass(): string
    {
        return UpdateBoardDecisionAttachmentRequest::class;
    }
}

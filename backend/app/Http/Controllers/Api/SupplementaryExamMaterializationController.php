<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SupplementaryExamOffering;
use App\Services\SupplementaryExamMaterializationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SupplementaryExamMaterializationController extends Controller
{
    public function store(
        Request $request,
        SupplementaryExamOffering $offering,
        SupplementaryExamMaterializationService $service,
    ): JsonResponse {
        return response()->json([
            'success' => true,
            'data' => $service->materializeOffering($request->user(), $offering),
        ]);
    }
}

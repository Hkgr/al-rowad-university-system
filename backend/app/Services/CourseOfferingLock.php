<?php
namespace App\Services;
use App\Models\CourseOffering;
final class CourseOfferingLock { public static function lock(int $id): void { CourseOffering::query()->whereKey($id)->lockForUpdate()->firstOrFail(); } }

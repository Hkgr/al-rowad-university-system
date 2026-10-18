<?php

namespace Tests\Feature;

use App\Models\Student;
use App\Models\User;
use App\Policies\StudentPolicy;
use App\Services\DataScopeService;
use Mockery;
use Tests\TestCase;

class ExamStudentAcademicRecordPolicyTest extends TestCase
{
    public function test_students_view_absent_denies_without_consulting_data_scope(): void
    {
        $actor = $this->actorWithStudentsView(false);
        $student = new Student();
        $scope = Mockery::mock(DataScopeService::class);
        $scope->shouldNotReceive('canAccessStudent');
        $this->app->instance(DataScopeService::class, $scope);

        self::assertFalse((new StudentPolicy())->view($actor, $student));
    }

    public function test_students_view_with_denied_data_scope_is_denied(): void
    {
        $actor = $this->actorWithStudentsView(true);
        $student = new Student();
        $scope = Mockery::mock(DataScopeService::class);
        $scope->shouldReceive('canAccessStudent')->once()->with($actor, $student)->andReturnFalse();
        $this->app->instance(DataScopeService::class, $scope);

        self::assertFalse((new StudentPolicy())->view($actor, $student));
    }

    public function test_students_view_with_allowed_data_scope_is_allowed(): void
    {
        $actor = $this->actorWithStudentsView(true);
        $student = new Student();
        $scope = Mockery::mock(DataScopeService::class);
        $scope->shouldReceive('canAccessStudent')->once()->with($actor, $student)->andReturnTrue();
        $this->app->instance(DataScopeService::class, $scope);

        self::assertTrue((new StudentPolicy())->view($actor, $student));
    }

    private function actorWithStudentsView(bool $allowed): User
    {
        $actor = Mockery::mock(User::class)->makePartial();
        $actor->shouldReceive('hasPermission')->once()->with('students.view')->andReturn($allowed);

        return $actor;
    }
}

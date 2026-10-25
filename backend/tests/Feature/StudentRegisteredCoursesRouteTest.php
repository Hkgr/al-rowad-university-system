<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class StudentRegisteredCoursesRouteTest extends TestCase
{
    public function test_registered_courses_are_readable_from_the_student_profile(): void
    {
        $route = collect(Route::getRoutes())->first(
            fn ($route) => $route->uri() === 'api/v1/students/{student}/registered-courses'
                && in_array('GET', $route->methods(), true)
        );

        $this->assertNotNull($route);
        $this->assertSame('App\Http\Controllers\Api\StudentController@registeredCourses', $route->getActionName());
    }
}

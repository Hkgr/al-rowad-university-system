<?php

namespace App\Providers;

use App\Models\Student;
use App\Models\CourseOffering;
use App\Models\StudentCourseResult;
use App\Models\StudentDocument;
use App\Policies\StudentCourseResultPolicy;
use App\Policies\CourseOfferingPolicy;
use App\Policies\StudentPolicy;
use App\Policies\StudentDocumentPolicy;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Gate::policy(Student::class, StudentPolicy::class);
        Gate::policy(CourseOffering::class, CourseOfferingPolicy::class);
        Gate::policy(StudentCourseResult::class, StudentCourseResultPolicy::class);
        Gate::policy(StudentDocument::class, StudentDocumentPolicy::class);

        RateLimiter::for('login', function (Request $request) {
            $email = strtolower(trim((string) $request->input('email', '')));

            return Limit::perMinute(5)->by($email.'|'.$request->ip());
        });
    }
}

<?php

namespace App\Providers;

use App\Models\Student;
use App\Models\StudentCourseResult;
use App\Models\StudentDocument;
use App\Policies\StudentCourseResultPolicy;
use App\Policies\StudentPolicy;
use App\Policies\StudentDocumentPolicy;
use Illuminate\Support\Facades\Gate;
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
        Gate::policy(StudentCourseResult::class, StudentCourseResultPolicy::class);
        Gate::policy(StudentDocument::class, StudentDocumentPolicy::class);
    }
}

$ErrorActionPreference = "Stop"

$failed = $false

function Check-FileExists {
  param(
    [string]$Path,
    [string]$Label
  )

  if (Test-Path $Path) {
    Write-Host "PASS: $Label" -ForegroundColor Green
  } else {
    Write-Host "FAIL: $Label" -ForegroundColor Red
    Write-Host "      Missing file: $Path" -ForegroundColor Yellow
    $script:failed = $true
  }
}

function Check-FileContains {
  param(
    [string]$Path,
    [string]$Pattern,
    [string]$Label
  )

  if (-not (Test-Path $Path)) {
    Write-Host "FAIL: $Label" -ForegroundColor Red
    Write-Host "      Missing file: $Path" -ForegroundColor Yellow
    $script:failed = $true
    return
  }

  $content = Get-Content $Path -Raw

  if ($content -match $Pattern) {
    Write-Host "PASS: $Label" -ForegroundColor Green
  } else {
    Write-Host "FAIL: $Label" -ForegroundColor Red
    Write-Host "      Pattern not found: $Pattern" -ForegroundColor Yellow
    $script:failed = $true
  }
}

Write-Host ""
Write-Host "Checking Student Registration Backend API static readiness..." -ForegroundColor Cyan
Write-Host ""

# Main route file
Check-FileExists "backend\routes\api.php" "Laravel API routes file exists"

# Controllers
Check-FileExists "backend\app\Http\Controllers\Api\StudentController.php" "StudentController exists"
Check-FileExists "backend\app\Http\Controllers\Api\RegistrationController.php" "RegistrationController exists"
Check-FileExists "backend\app\Http\Controllers\Api\AcademicProgramController.php" "AcademicProgramController exists"
Check-FileExists "backend\app\Http\Controllers\Api\AcademicLevelController.php" "AcademicLevelController exists"
Check-FileExists "backend\app\Http\Controllers\Api\StudentStatusController.php" "StudentStatusController exists"
Check-FileExists "backend\app\Http\Controllers\Api\CourseOfferingController.php" "CourseOfferingController exists"

# CRUD trait
Check-FileExists "backend\app\Http\Controllers\Api\Concerns\HandlesApiCrud.php" "HandlesApiCrud trait exists"
Check-FileContains "backend\app\Http\Controllers\Api\Concerns\HandlesApiCrud.php" "function\s+store\s*\(" "CRUD trait has store method"

# Requests
Check-FileExists "backend\app\Http\Requests\Student\StoreStudentRequest.php" "StoreStudentRequest exists"
Check-FileExists "backend\app\Http\Requests\Student\UpdateStudentRequest.php" "UpdateStudentRequest exists"
Check-FileExists "backend\app\Http\Requests\Registration\RegisterStudentRequest.php" "RegisterStudentRequest exists"

# StudentController request binding
Check-FileContains "backend\app\Http\Controllers\Api\StudentController.php" "StoreStudentRequest" "StudentController uses StoreStudentRequest"
Check-FileContains "backend\app\Http\Controllers\Api\StudentController.php" "UpdateStudentRequest" "StudentController uses UpdateStudentRequest"
Check-FileContains "backend\app\Http\Controllers\Api\StudentController.php" "storeRequestClass" "StudentController has storeRequestClass"
Check-FileContains "backend\app\Http\Controllers\Api\StudentController.php" "updateRequestClass" "StudentController has updateRequestClass"

# Routes: Create Student
Check-FileContains "backend\routes\api.php" "apiResource\('students'" "Route exists: apiResource students"
Check-FileContains "backend\routes\api.php" "students/\{student\}/registration-summary" "Route exists: student registration summary"
Check-FileContains "backend\routes\api.php" "students/\{student\}/available-courses" "Route exists: student available courses"
Check-FileContains "backend\routes\api.php" "students/search" "Route exists: students search"

# Routes: Course Registration
Check-FileContains "backend\routes\api.php" "registrations/register-student" "Route exists: register student in course"
Check-FileContains "backend\routes\api.php" "registrations/\{id\}/drop" "Route exists: drop registration"
Check-FileContains "backend\routes\api.php" "registrations/\{id\}/withdraw" "Route exists: withdraw registration"

# Routes: Lookups
Check-FileContains "backend\routes\api.php" "apiResource\('academic-programs'" "Route exists: academic programs"
Check-FileContains "backend\routes\api.php" "apiResource\('academic-levels'" "Route exists: academic levels"
Check-FileContains "backend\routes\api.php" "apiResource\('student-statuses'" "Route exists: student statuses"
Check-FileContains "backend\routes\api.php" "course-offerings/open" "Route exists: open course offerings"

# StudentController methods
Check-FileContains "backend\app\Http\Controllers\Api\StudentController.php" "function\s+search\s*\(" "StudentController has search method"
Check-FileContains "backend\app\Http\Controllers\Api\StudentController.php" "function\s+availableCourses\s*\(" "StudentController has availableCourses method"
Check-FileContains "backend\app\Http\Controllers\Api\StudentController.php" "function\s+registrationSummary\s*\(" "StudentController has registrationSummary method"

# RegistrationController methods
Check-FileContains "backend\app\Http\Controllers\Api\RegistrationController.php" "RegisterStudentRequest" "RegistrationController uses RegisterStudentRequest"
Check-FileContains "backend\app\Http\Controllers\Api\RegistrationController.php" "function\s+registerStudent\s*\(" "RegistrationController has registerStudent method"
Check-FileContains "backend\app\Http\Controllers\Api\RegistrationController.php" "function\s+drop\s*\(" "RegistrationController has drop method"
Check-FileContains "backend\app\Http\Controllers\Api\RegistrationController.php" "function\s+withdraw\s*\(" "RegistrationController has withdraw method"

# StoreStudentRequest fields
Check-FileContains "backend\app\Http\Requests\Student\StoreStudentRequest.php" "student_number" "StoreStudentRequest validates student_number"
Check-FileContains "backend\app\Http\Requests\Student\StoreStudentRequest.php" "first_name" "StoreStudentRequest validates first_name"
Check-FileContains "backend\app\Http\Requests\Student\StoreStudentRequest.php" "last_name" "StoreStudentRequest validates last_name"
Check-FileContains "backend\app\Http\Requests\Student\StoreStudentRequest.php" "academic_program_id" "StoreStudentRequest validates academic_program_id"
Check-FileContains "backend\app\Http\Requests\Student\StoreStudentRequest.php" "current_academic_level_id" "StoreStudentRequest validates current_academic_level_id"
Check-FileContains "backend\app\Http\Requests\Student\StoreStudentRequest.php" "enrollment_date" "StoreStudentRequest validates enrollment_date"
Check-FileContains "backend\app\Http\Requests\Student\StoreStudentRequest.php" "student_status_id" "StoreStudentRequest validates student_status_id"

# RegisterStudentRequest fields
Check-FileContains "backend\app\Http\Requests\Registration\RegisterStudentRequest.php" "student_id" "RegisterStudentRequest validates student_id"
Check-FileContains "backend\app\Http\Requests\Registration\RegisterStudentRequest.php" "course_offering_id" "RegisterStudentRequest validates course_offering_id"

# Contracts
Check-FileExists "api\contracts\create-student.md" "Contract exists: create-student.md"
Check-FileExists "api\contracts\course-registration.md" "Contract exists: course-registration.md"
Check-FileExists "api\openapi.yaml" "OpenAPI file exists"

Write-Host ""

if ($failed) {
  Write-Host "STATIC API READINESS CHECK FAILED" -ForegroundColor Red
  exit 1
}

Write-Host "STATIC API READINESS CHECK PASSED" -ForegroundColor Green
exit 0

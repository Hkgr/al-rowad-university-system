# Create Student API Contract

## Purpose

إنشاء طالب جديد داخل النظام.

## Endpoint

POST /api/v1/students

## Auth

Required: Bearer Token

Headers:

`http
Authorization: Bearer {token}
Accept: application/json
Content-Type: application/json
`

## Required Fields

- student_number
- first_name
- last_name
- academic_program_id
- current_academic_level_id
- enrollment_date
- student_status_id

## Optional Fields

- admission_application_id
- father_name
- mother_name
- date_of_birth
- gender
- phone_number
- email
- address
- nationality

## Request Body

`json
{
  "student_number": "2026-0001",
  "admission_application_id": null,
  "first_name": "Ahmad",
  "last_name": "Ali",
  "father_name": "Mohammad",
  "mother_name": "Fatima",
  "date_of_birth": "2005-05-10",
  "gender": "male",
  "phone_number": "0999999999",
  "email": "student@example.com",
  "address": "Aleppo",
  "nationality": "Syrian",
  "academic_program_id": 1,
  "current_academic_level_id": 1,
  "enrollment_date": "2026-09-01",
  "student_status_id": 1
}
`

## Success Response

Status: 201

`json
{
  "success": true,
  "message": "Operation completed successfully",
  "data": {
    "student_id": 1,
    "student_number": "2026-0001",
    "admission_application_id": null,
    "first_name": "Ahmad",
    "last_name": "Ali",
    "father_name": "Mohammad",
    "mother_name": "Fatima",
    "date_of_birth": "2005-05-10",
    "gender": "male",
    "phone_number": "0999999999",
    "email": "student@example.com",
    "address": "Aleppo",
    "nationality": "Syrian",
    "academic_program_id": 1,
    "current_academic_level_id": 1,
    "enrollment_date": "2026-09-01",
    "student_status_id": 1
  }
}
`

## Validation Error Response

Status: 422

`json
{
  "message": "The given data was invalid.",
  "errors": {
    "student_number": [
      "The student number field is required."
    ],
    "first_name": [
      "The first name field is required."
    ]
  }
}
`

## Lookup APIs Needed By Frontend

- GET /api/v1/academic-programs
- GET /api/v1/academic-levels
- GET /api/v1/student-statuses

## Frontend Target Files

- frontend/src/features/students/pages/CreateStudentPage.jsx
- frontend/src/features/students/components/StudentForm.jsx
- frontend/src/features/students/services/studentsApi.js

## Frontend Service Function

`js
import { apiRequest } from '../../../services/apiClient';

export function createStudent(data) {
  return apiRequest('/v1/students', {
    method: 'POST',
    body: JSON.stringify(data),
  });
}
`

## Backend Owner

Mutaz

## Frontend Owner

Omar

## Project Manager Review

Rashad must approve this contract before frontend/backend implementation is considered complete.

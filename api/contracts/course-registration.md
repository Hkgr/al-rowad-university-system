# Course Registration API Contract

## Purpose

تسجيل طالب موجود مسبقاً على شعبة مقرر.

هذا العقد لا ينشئ طالباً جديداً، بل يسجل طالباً موجوداً على course_offering.

## Endpoint

POST /api/v1/registrations/register-student

## Auth

Required: Bearer Token

Headers:

`http
Authorization: Bearer {token}
Accept: application/json
Content-Type: application/json
`

## Required Fields

- student_id
- course_offering_id

## Optional Fields

- registered_by_user_id
- advisor_user_id
- registration_date

## Request Body

`json
{
  "student_id": 1,
  "course_offering_id": 5,
  "registered_by_user_id": 1,
  "advisor_user_id": null,
  "registration_date": "2026-09-01"
}
`

## Success Response

Status: 201

`json
{
  "success": true,
  "message": "Student registered successfully",
  "data": {}
}
`

## Validation Error Response

Status: 422

`json
{
  "message": "The given data was invalid.",
  "errors": {
    "student_id": [
      "The student id field is required."
    ],
    "course_offering_id": [
      "The course offering id field is required."
    ]
  }
}
`

## Lookup APIs Needed By Frontend

- GET /api/v1/students/search
- GET /api/v1/course-offerings/open
- GET /api/v1/students/{student}/available-courses
- GET /api/v1/students/{student}/registration-summary
- GET /api/v1/course-offerings/{id}/capacity

## Frontend Target Files

- frontend/src/features/course-registration/pages/CourseRegistrationPage.jsx
- frontend/src/features/course-registration/components/CourseRegistrationForm.jsx
- frontend/src/features/course-registration/services/courseRegistrationApi.js

## Frontend Service Function

`js
import { apiRequest } from '../../../services/apiClient';

export function registerStudentInCourse(data) {
  return apiRequest('/v1/registrations/register-student', {
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

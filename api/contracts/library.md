# Library API Contract

## Base URL

`http://127.0.0.1:8000/api/v1`

## Introduction

This module manages the university library catalog: authors, categories, books, physical copies, book–author links, and borrowing transactions for students and employees.

## Authentication Requirements

All endpoints require:

```http
Authorization: Bearer {token}
Accept: application/json
Content-Type: application/json
```

## Standard REST CRUD Pattern

All library resources support full CRUD:

| Method | URL | Purpose |
|--------|-----|---------|
| GET | `/api/v1/{resource}` | List (paginated, `?per_page=15`) |
| POST | `/api/v1/{resource}` | Create |
| GET | `/api/v1/{resource}/{id}` | Show |
| PUT/PATCH | `/api/v1/{resource}/{id}` | Update |
| DELETE | `/api/v1/{resource}/{id}` | Delete |

---

## Endpoint List

| Resource | Base path |
|----------|-----------|
| Library authors | `/api/v1/library-authors` |
| Library categories | `/api/v1/library-categories` |
| Library books | `/api/v1/library-books` |
| Library book copies | `/api/v1/library-book-copies` |
| Library book authors | `/api/v1/library-book-authors` |
| Library borrowings | `/api/v1/library-borrowings` |

---

## POST /api/v1/library-authors

### Request body

```json
{
  "author_name": "George Orwell",
  "biography": "English novelist and essayist."
}
```

### Validation rules

| Field | Rules |
|-------|-------|
| `author_name` | `required\|string\|max:200` — recommended name rule |
| `biography` | `nullable\|string` |

### Success response (201)

```json
{
  "success": true,
  "message": "Operation completed successfully",
  "data": {
    "library_author_id": 1,
    "author_name": "George Orwell",
    "biography": "English novelist and essayist."
  }
}
```

---

## POST /api/v1/library-categories

### Request body

```json
{
  "category_name": "Computer Science",
  "description": "CS and IT books",
  "is_active": 1
}
```

### Validation rules

| Field | Rules |
|-------|-------|
| `category_name` | `required\|string\|max:150` |
| `description` | `nullable\|string\|max:255` |
| `is_active` | `required\|integer` |

---

## POST /api/v1/library-books

### Request body

```json
{
  "isbn": "978-0134685991",
  "title": "Effective Java",
  "category_id": 1,
  "publisher": "Addison-Wesley",
  "publication_year": 2018,
  "language": "English"
}
```

### Validation rules

| Field | Rules |
|-------|-------|
| `isbn` | `nullable\|string\|max:50` |
| `title` | `required\|string\|max:250` |
| `category_id` | `nullable\|integer\|exists:library_categories,library_category_id` |
| `publisher` | `nullable\|string\|max:200` |
| `publication_year` | `nullable\|integer` |
| `language` | `nullable\|string\|max:80` |

### Success response (201)

```json
{
  "success": true,
  "message": "Operation completed successfully",
  "data": {
    "library_book_id": 1,
    "title": "Effective Java",
    "isbn": "978-0134685991",
    "category_id": 1
  }
}
```

### Error response (422)

```json
{
  "success": false,
  "message": "Validation failed",
  "errors": {
    "title": ["The title field is required."]
  }
}
```

---

## POST /api/v1/library-book-copies

**Purpose:** Add a physical copy of a book (for circulation).

### Request body

```json
{
  "library_book_id": 1,
  "copy_barcode": "LIB-0001",
  "copy_status": "available",
  "shelf_location": "A-12-3"
}
```

### Validation rules

| Field | Rules |
|-------|-------|
| `library_book_id` | `required\|integer\|exists:library_books,library_book_id` |
| `copy_barcode` | `required\|string\|max:80` |
| `copy_status` | `required\|string\|max:50` |
| `shelf_location` | `nullable\|string\|max:100` |

### Frontend notes

- Borrowing references `library_book_copy_id`, not book ID.
- Track copy status (`available`, `borrowed`, etc.) in UI.

---

## POST /api/v1/library-book-authors

**Purpose:** Link an author to a book (many-to-many).

### Request body

```json
{
  "library_book_id": 1,
  "library_author_id": 1
}
```

### Validation rules

| Field | Rules |
|-------|-------|
| `library_book_id` | `required\|integer\|exists:library_books,library_book_id` |
| `library_author_id` | `required\|integer\|exists:library_authors,library_author_id` |

---

## POST /api/v1/library-borrowings

**Purpose:** Record a book loan to a student or employee.

### Request body

```json
{
  "library_book_copy_id": 1,
  "student_id": 1,
  "employee_id": null,
  "borrowed_at": "2026-06-01",
  "due_at": "2026-06-15",
  "returned_at": null,
  "borrowing_status": "active",
  "created_by_user_id": 1
}
```

### Validation rules

| Field | Rules |
|-------|-------|
| `library_book_copy_id` | `required\|integer\|exists:library_book_copies,library_book_copy_id` |
| `student_id` | `nullable\|integer\|exists:students,student_id` |
| `employee_id` | `nullable\|integer\|exists:employees,employee_id` |
| `borrowed_at` | `required\|date` |
| `due_at` | `required\|date` |
| `returned_at` | `nullable\|date` |
| `borrowing_status` | `required\|string\|max:50` |
| `created_by_user_id` | `nullable\|integer\|exists:users,user_id` |

### Success response (201)

```json
{
  "success": true,
  "message": "Operation completed successfully",
  "data": {
    "library_borrowing_id": 1,
    "library_book_copy_id": 1,
    "student_id": 1,
    "borrowed_at": "2026-06-01",
    "due_at": "2026-06-15",
    "borrowing_status": "active"
  }
}
```

### Error response (422)

```json
{
  "success": false,
  "message": "Validation failed",
  "errors": {
    "library_book_copy_id": ["The library book copy id field is required."],
    "due_at": ["The due at field is required."]
  }
}
```

### Frontend notes

- Require either `student_id` or `employee_id` for borrower (business rule in UI even if both nullable in API).
- Validate `due_at >= borrowed_at` client-side.
- Set `returned_at` and update `borrowing_status` on return via PUT.
- Search students via `GET /api/v1/students/search` for borrower picker.

---

## GET /api/v1/library-books (list)

**Query:** `?per_page=15`

**Success response (200):** Paginated book list with standard envelope.

### Frontend notes

- Typical flow: create category → create author(s) → create book → add copies → link authors → create borrowing.
- Filter available copies by `copy_status` before loan creation.

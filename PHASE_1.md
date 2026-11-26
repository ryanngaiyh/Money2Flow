# Money2Flow Phase 1 — Project Foundation

## Scope completed

- Reusable PHP configuration and layout components
- PDO connection to MySQL with prepared statements
- Registration with backend validation and password hashing
- Login with `password_verify()` and session ID regeneration
- Logout and protected-route redirection
- CSRF protection on authentication forms
- Responsive sidebar, top navigation, and dashboard shell
- Standalone Phase 1 database schema

## Folder structure

```text
config/          Application and database configuration
includes/        Authentication helpers and reusable layouts
assets/          Shared CSS and JavaScript
auth/            Register, login, logout, and profile pages
dashboard/       Protected dashboard entry page
database/        Phase schema and complete development schema
```

The existing income, expense, category, budget, savings, reports, and IPO files have been preserved because they were already working before this phased plan began. They were not extended during Phase 1.

## Phase 1 database

For a clean Phase 1-only installation, import `database/phase1_schema.sql`.

The existing `database/database.sql` remains the complete development database used by the current local application.

## Manual test

1. Start Apache and MySQL in XAMPP.
2. Open `http://localhost/money-flow/`.
3. Register with a new email and an 8-character-or-longer password.
4. Log in with the new account.
5. Confirm the protected dashboard loads.
6. Click Logout and confirm the login page appears.
7. Open `http://localhost/money-flow/dashboard/index.php` while logged out and confirm it redirects to login.
8. Try an invalid email, short password, mismatched confirmation, duplicate email, and incorrect login password.

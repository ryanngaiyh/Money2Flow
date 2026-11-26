# Money2Flow — Student Personal Finance Management

## Installation

1. Copy this folder into `C:\xampp\htdocs\money-flow`.
2. Start Apache and MySQL in XAMPP.
3. Open phpMyAdmin, choose **Import**, and import `database/database.sql`.
4. Open `http://localhost/money-flow/`.

If MySQL has a password or non-default port, update `config/database.php`.

## Sample account

- Email: `student@test.com`
- Password: `password`

## Main files

- `config/database.php` — PDO database connection.
- `includes/auth.php` — sessions, CSRF, user authorization, validation helpers.
- `transactions`, `categories`, `budgets`, `savings` — protected finance modules.
- `split-bills/index.php` — equal/custom shared expenses and settlement tracking.
- `transactions/suggest-category.php` — user-owned rule-based merchant suggestions.
- `receipts` — protected receipt upload, OCR review, and transaction confirmation workflow.
- `dashboard/index.php` — financial summary, budget/savings/shared-expense progress and Chart.js charts.
- `reports/index.php` — unified monthly analytics, comparisons, budget/goal performance, and rule-based insights.
- `notifications/index.php` — in-app alerts, unread inbox, and notification preferences; see `NEW_FEATURES_PHASE_1.md`.
- `savings/index.php` — active, completed, and archived goals with monthly history; see `NEW_FEATURES_PHASE_2.md`.
- `allowance/index.php` — daily spending planner linked to Income Sources and recorded transactions; see `NEW_FEATURES_PHASE_3.md`.
- `simulator/index.php` — read-only purchase impact simulator; see `NEW_FEATURES_PHASE_4.md`.
- `dashboard/index.php` — integrated finance overview and alerts; see `NEW_FEATURES_PHASE_5.md` for the final checklist.
- `database/database.sql` — complete relational schema plus development sample data.

## Database relationships

Each user owns categories, transactions, budgets, savings goals, and split bills. Transactions and budgets can reference a user-owned category, while split-bill participants belong to one split bill. Foreign keys preserve these relationships and cascade participant deletion with its bill. All read/update/delete queries enforce the logged-in user, preventing access to another user's data.

## Test checklist

Register with a new email, log in/out, manage transactions, budgets, and goals, then test equal/custom split bills and settlement controls. Confirm dashboard/report figures update. Test invalid amounts, mismatched passwords, duplicate email, blank required fields, and cross-account access attempts.

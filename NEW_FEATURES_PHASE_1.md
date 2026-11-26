# Money2Flow New Features — Phase 1: Smart Notification Centre

## Architecture and reuse

Money2Flow remains a PHP 8 / MariaDB application running on XAMPP. The feature uses the existing authenticated session, CSRF helper, PDO connection, shared navigation, Bootstrap layout, unified `transactions` ledger, `budgets`, recurring `incomes`, `savings_goals`, and `split_bills` tables. It does not change any finance totals or post income/expense transactions.

## Implemented

- A bell in the top navigation shows the authenticated user's unread count and five recent alerts. Its menu supports individual and bulk read actions and links to the full inbox.
- The Notifications page supports all/unread views, pagination, related feature links, Mark as Read, Mark All as Read, and five on/off preferences.
- Budget alerts are checked after expense and budget writes. The 80% and 100% thresholds use the budget's own month and category, with a unique event key to suppress repeats. A jump directly to 100% produces one 100% alert.
- Savings achievement alerts are checked when a goal is created, edited, or funded across its target amount.
- Recurring income sources whose name contains `allowance` can produce one reminder per scheduled occurrence when the next date is within three days. The reminder includes the recorded balance through the current day, clearly before future commitments and savings. It does not create an income transaction.
- Target goals with a deadline within seven days produce one reminder for that deadline while still below target.
- Split bills with unsettled participants produce at most one reminder per seven-day age interval, starting after the first week.
- All notification dates are calculated using `Asia/Kuala_Lumpur`. Scheduled reminders are created on the next authenticated page visit; XAMPP does not run a background scheduler for this phase.

## Database changes

The additive migration `database/notifications_phase1_migration.sql` creates:

- `notifications`: user ownership, type, text, related record, unread/read timestamps, and a per-user unique event key.
- `notification_preferences`: one row per user with five on/off switches.

Both tables use `users` foreign keys with cascade cleanup. Existing data and tables are unchanged. `database/database.sql` also includes the new tables for fresh installations.

## Test results

`tests/notifications_phase1.php` runs isolated database checks inside one transaction and rolls back every test row. It covers 80%/100% budget thresholds, deduplication, allowance/deadline/split reminders with a controlled date, goal achievement, disabled preferences, cross-user separation, and end-of-month allowance dates.

`tests/notifications_http_phase1.php` checks the deployed XAMPP login, bell, inbox, Mark as Read, Mark All as Read, and preference settings with a temporary account. It deletes the account when finished.

## Browser test checklist

1. Log in and look for the bell at the top right. Open it, then select **View All Notifications**.
2. On the Notifications page, switch between **All** and **Unread** and save the five notification settings.
3. Create a current-month category budget of RM 400. Add expenses in that category totaling RM 320; expect one 80% notification. Add RM 80 more; expect one 100% notification. Refresh several times and check that the count does not grow.
4. Use **Mark as Read** on one alert, then **Mark All as Read**. The bell's unread number should update.
5. Create a target savings goal just below its target, then add a contribution to reach it. Expect one achievement alert.
6. For an allowance reminder, configure an active recurring income source with `Allowance` in its name and a cycle date within three days. Return to the dashboard and check the bell.
7. Set an unfinished target goal deadline within seven days. Create a split bill dated at least seven days ago with a pending share. Return to the dashboard and check for reminders.
8. Disable a type in Settings, cause a new event of that type, and confirm no new alert appears.
9. Log into a different account. The first user's alerts and settings must not appear.
10. Check the bell, dropdown, and inbox at mobile width.

## Limits for this phase

- Reminders appear on the next signed-in visit, not at an exact background time. There is no email or push delivery.
- Until Phase 3 introduces an explicit main allowance selection, recurring income sources with `allowance` in the name are eligible. The earliest scheduled source is used.
- The reminder's recorded balance is income minus expenses through today. It is not the future daily spending estimate planned for Phase 3.
- Savings completion status, the 30-day archive, allowance planner, and purchase simulator belong to later phases.

## Files changed

- `database/notifications_phase1_migration.sql` (new)
- `database/database.sql`
- `includes/notifications.php` (new)
- `notifications/index.php` (new)
- `includes/topbar.php`
- `includes/sidebar.php`
- `assets/css/style.css`
- `transactions/index.php`
- `budgets/index.php`
- `savings/index.php`
- `receipts/review.php`
- `tests/notifications_phase1.php` (new)
- `tests/notifications_http_phase1.php` (new)
- `README.md`

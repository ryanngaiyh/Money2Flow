# Money2Flow New Features — Phase 2: Savings Completion and History

## Reused architecture

The existing PHP 8.2, MariaDB, XAMPP, session/CSRF, savings goals, notifications, dashboard, and reports remain in place. Savings entries do not create expense or income transactions. `current_amount` remains the lifetime amount saved, so existing dashboard totals do not lose earlier deposits.

## Behaviour

- Target goals become **Completed** as soon as saved amount reaches the target. The completion time is recorded, a congratulations message is shown, and the Phase 1 goal notification is generated once.
- Completed target goals stay in **Completed Goals** for 30 calendar days. On the 30th date, they become **Archived**, retain their record and savings, and appear in **Goal History** with deadline and on-time result.
- Monthly goals remain **Active** after reaching the month's target. Their current-month progress is stored separately from lifetime savings. At the next month, current-month progress resets to zero while lifetime savings stays unchanged. Each finished month gets an **Achieved** or **Missed** history row.
- New deposits and manual adjustments are recorded in `savings_goal_contributions`. Existing balances were imported as **Opening** entries on migration day because their original deposit dates are unknown.
- Goals with savings entries or achievements cannot be permanently deleted in the UI. Empty, untouched active goals can still be deleted.
- Goal History can be filtered by goal type and sorted newest or oldest.

## Database change

Run once: `database/savings_phase2_migration.sql`. It adds status, completion/archive dates, monthly cycle fields, a contributions table, and a monthly history table. Existing target goals already at 100% are recognized as completed on the migration date. This preserves a full 30-day display period without inventing an earlier completion date.

`database/database.sql` includes the new structure for fresh installations.

## Automatic maintenance

`scripts/savings_maintenance.php` is a CLI-only, idempotent job intended to run every day just after midnight Malaysia time. It completes eligible goals, closes monthly cycles, and archives targets whose completion date was 30 calendar days earlier. The shared navigation also performs the same safe maintenance for the signed-in user on their next visit, so missed scheduled runs do not lose records.

On this Windows/XAMPP installation, the Windows Task Scheduler entry **Money2Flow Savings Maintenance** is installed for **00:05 daily**. A manual task run returned success (`0`). It is configured to catch up after a missed run and to operate on battery. Windows registered it in **Interactive** mode, so the user account must be signed in for a scheduled run. If the laptop is off, the user is signed out, or MySQL is stopped, maintenance catches up on the next signed-in app visit once the database is running. The recorded archive date is the due date, even if the maintenance check happens later.

## Verification

`tests/savings_phase2.php` uses controlled dates and rolls back all test rows. It checks target completion, single achievement notification, cross-user ownership, day-29/day-30 archive behaviour, idempotence, monthly achievement, extra deposits, month rollover, missed months, lifetime savings preservation, and no financial transaction side effects.

`tests/savings_http_phase2.php` checks the deployed XAMPP savings page, target creation/completion, monthly achievement, and dashboard/report integration with a temporary account that it deletes afterward.

## Browser test checklist

1. Open **Savings Goals**. The existing completed New Laptop goal should appear in **Completed Goals** and show its archive date. Its past amount remains RM 3,000.
2. Create a small target goal, then add enough savings to reach 100%. Confirm the congratulations message, completed card, date, and one bell notification. Refresh; no duplicate should appear.
3. Create a monthly goal and add enough to meet this month's target. Confirm it remains in **Active Goals** and also appears as a monthly achievement in **Completed Goals**.
4. Add more to that monthly goal. Its lifetime and monthly amounts should rise, while its completion notification stays single.
5. Compare dashboard and report savings totals and progress with Savings Goals. Monthly progress should use this month's amount, while total saved includes prior months.
6. In **Goal History**, try the goal-type filter and oldest/newest sort. Archived target goals show the original deadline, completion and archive dates, and on-time result.
7. Confirm a goal with savings cannot be deleted and another account cannot see or modify your goals.
8. At mobile width, check the active/completed cards, history table, and contribution list.

## Limits

- Exact original completion dates for goals completed before this migration are unavailable; the migration date is their recognition date.
- The app records goal savings separately from the transaction ledger. It does not transfer bank funds or alter the income-minus-expense balance.
- A computer that is off cannot execute a scheduled task at that time; the next signed-in visit catches up and records the intended archive date.
- The archived target remains read-only to preserve its achievement snapshot.

## Files changed

- `database/savings_phase2_migration.sql` (new)
- `database/database.sql`
- `includes/savings_lifecycle.php` (new)
- `includes/notifications.php`
- `includes/topbar.php`
- `savings/index.php`
- `dashboard/index.php`
- `reports/index.php`
- `scripts/savings_maintenance.php` (new)
- `tests/savings_phase2.php` (new)
- `tests/savings_http_phase2.php` (new)
- `README.md`

# New Features Phase 3 — Until Next Allowance

Phase 3 adds a daily-spending estimate to the existing PHP/XAMPP Money2Flow app. It does not post income, expenses, or savings transfers automatically.

## Database update for an existing installation

Import `database/allowance_phase3_migration.sql` into the existing `student_finance` database. It only adds:

- `allowance_settings`: one planner configuration per user, optionally linked to an existing recurring Income Source.
- `allowance_commitments`: user-owned payments with due dates and settled state.

No existing rows are renamed, deleted, or modified. Fresh installs use the same tables in `database/database.sql`.

## Calculation

Recorded funds = starting cash adjustment + all recorded Income transactions through today − all recorded Expense transactions through today. Future-dated transactions and Income Source schedule amounts are not counted as cash. The starting cash adjustment defaults to zero and should only correct incomplete transaction history.

Available to spend = recorded funds − unpaid commitments due before the next allowance − savings still intended to be reserved. Commitments remain deducted if overdue and unpaid. Settled commitments are not deducted; if actually paid, the user must record the expense as a transaction. Savings already transferred or otherwise included in the recorded balance should not also be entered as a reserve.

Suggested daily spending = max(0, available to spend) / full calendar days until the next allowance, rounded down to sen. On the allowance date itself, the daily estimate is withheld until the payment is recorded. Negative available money is displayed without a negative daily recommendation. Irregular allowances with a past date request a new date. Weekly, monthly, and yearly dates roll forward from the configured anchor, including month-end dates.

The dashboard's monthly filter does not change this planner: it always uses all recorded transactions through the current Malaysia date. The progress indicator shows the discretionary share of recorded funds, not a promise or forecast.

## Browser test

1. Open `http://localhost/money-flow/allowance/index.php` while logged in.
2. Choose an active recurring Income Source or enter a manual/irregular allowance; set its next date, then save.
3. Add an unpaid commitment due before that date and a savings reserve. Check the breakdown and dashboard card.
4. Add, edit, or delete an Expense transaction; refresh the dashboard and confirm the estimate changes.
5. Record an actual payment as an Expense and mark its commitment settled. Confirm it stops being reserved, without subtracting the same payment twice.
6. Check missing/inactive sources, past irregular dates, due-today dates, and an overdrawn balance.

## Limitations

- This is an estimate from the app's ledger, not a bank balance. Missing cash, accounts, or transactions require a starting cash adjustment.
- Income Source schedules do not prove a payment arrived. The user must record the actual income transaction.
- Commitments are manually managed and are not automatically matched to Expense transactions. Users must mark a paid item settled to prevent double counting.
- Savings reserves are manually entered prospective amounts, not a transfer or a deduction from savings-goal balances.
- No purchase simulator was added; that belongs to Phase 4.

## Verification

Run `C:\xampp\php\php.exe tests/allowance_phase3.php`. Controlled dates and temporary database rows check recurrence, date and balance edge cases, future income, user separation, settled commitments, and dynamic expenses. Temporary rows are rolled back.

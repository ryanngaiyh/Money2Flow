# Money2Flow Phase 6 — Savings Goals

## Scope completed

- Monthly saving goals for recurring monthly targets
- Target goals with target amount, current amount, and deadline
- Create, read, update, and delete goals
- Quick **Add Savings** contributions
- Remaining amount and progress percentage calculations
- Required monthly saving for dated target goals
- Overdue and completed goal states
- Progress bars, goal filters, and savings summary cards
- User ownership checks for every edit, contribution, and delete operation

## Database changes

Existing installations should run `database/phase6_migration.sql` once.

Fresh installations through Phase 6 can import `database/phase6_schema.sql`.

The migration adds `goal_type` to `savings_goals`. Existing goals are retained and become target goals.

## Calculation rules

- Remaining = maximum of RM 0.00 and Target − Current
- Progress % = Current ÷ Target × 100
- The visible progress bar is capped at 100%, while the displayed percentage may exceed 100%
- Months remaining = the number of 30.4375-day average months from today to the deadline, rounded up, with a minimum of one month
- Required monthly saving = Remaining ÷ Months remaining
- For monthly saving goals, **Still needed this month** = Target − Current
- A target with a past deadline and money remaining is marked overdue

## Manual test

1. Open **Savings Goals** and create a monthly saving goal for RM 200 with RM 50 saved.
2. Confirm it shows RM 150 remaining and 25% complete.
3. Create a target goal for RM 3,000 with RM 1,200 saved and a future deadline.
4. Confirm it shows RM 1,800 remaining, 40% complete, and a monthly saving amount.
5. Add RM 300 with **Add Savings** and confirm all figures update immediately.
6. Edit the name, amounts, type, and deadline.
7. Use the All, Monthly Saving, and Target Goals filters.
8. Test a completed goal and a target with a past deadline.
9. Delete a test goal.
10. Log in with another user and confirm savings goals are isolated.

## Current limitation

Adding savings updates the goal balance only; it does not create a bank transaction. Monthly goal balances are not automatically reset at the start of a new month. Notifications are intentionally deferred to a later phase.

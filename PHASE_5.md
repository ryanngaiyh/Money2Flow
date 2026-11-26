# Money2Flow Phase 5 — Budget Management

## Scope completed

- One overall monthly budget per user and month
- One budget per category and month
- Budget create, read, update, and delete
- Actual spending calculated from unified expense transactions
- Remaining amount and usage percentage
- Green on-track status below 80%
- Amber approaching-limit warning from 80% to below 100%
- Red exceeded warning from 100%
- Dashboard support for overall and category budgets
- Database uniqueness for each monthly budget scope

## Database changes

Existing installations should run `database/phase5_migration.sql` once.

Fresh installations through Phase 5 can import `database/phase5_schema.sql`.

The migration allows `category_id` to be null for an overall budget and adds a generated `category_key` used by the unique index. This prevents duplicate overall or category budgets for the same month.

## Calculation rules

- Overall remaining = Overall budget − all expense transactions in the month
- Category remaining = Category budget − expense transactions in that category and month
- Usage % = Actual spending ÷ Budget amount × 100

## Manual test

1. Open **Budgets** and select the current month.
2. Create an overall budget of RM 1,200.
3. Create category budgets such as Food RM 400 and Transportation RM 200.
4. Confirm a duplicate overall/category budget is rejected.
5. Add expenses through Transactions and confirm progress changes.
6. Test spending below 80%, at least 80%, and at least 100%.
7. Edit a budget and confirm the remaining amount recalculates.
8. Delete a test budget.
9. Confirm the dashboard remaining-budget card uses the overall budget when available.
10. Log in with another user and confirm budget data is isolated.

## Current limitation

Budgets are monthly only. Notifications and reports are intentionally deferred to later phases.

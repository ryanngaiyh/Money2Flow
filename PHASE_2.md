# Money2Flow Phase 2 — Income and Categories

## Scope completed

- Income source CRUD for recurring and one-time income
- Weekly, monthly, and yearly recurring frequencies
- Active and inactive income status
- Monthly recurring-income estimate
- Income search and type/status filtering
- Category CRUD with optional Need/Want classification
- Nine student-friendly default categories
- Category filtering and safe deletion checks
- Prepared statements, CSRF protection, output escaping, and user ownership checks

## Database changes

Existing installations should run `database/phase2_migration.sql` once.

Fresh Phase 2 installations can import `database/phase2_schema.sql`.

The migration adds these income columns:

- `income_type`
- `frequency`
- `start_date`
- `status`

It also adds `need_want` to categories and safely adds missing defaults.

## Manual test

1. Log in and open **Income**.
2. Add `Monthly Allowance`, RM 1,500, Recurring, Monthly, Active.
3. Add `Part-Time Job`, RM 300, One-time, Active.
4. Edit each record and confirm the values persist.
5. Filter by type and status, then search by name.
6. Delete a test income source and confirm it disappears.
7. Open **Categories** and confirm the nine defaults are present.
8. Add a custom category with Need or Want classification.
9. Edit and delete the custom category.
10. Confirm a category used by an expense or budget cannot be deleted.
11. Register another account and confirm it receives its own categories and cannot see the first account's records.

## Current limitation

Recurring income is stored as an income source and included in the monthly estimate. Automatic generation of monthly transaction records is intentionally not part of Phase 2. Transaction processing begins in Phase 3.

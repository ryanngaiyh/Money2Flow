# Money2Flow Phase 3 — Transactions

## Scope completed

- One unified transaction table for actual income and expenses
- Manual transaction creation, reading, editing, and deletion
- Required category validation for expenses
- Optional merchant/payer and description fields
- Search by merchant or description
- Filter by type, category, and date range
- Total income, total expenses, and current balance calculations
- RM currency formatting
- Existing income/expense data migration with duplicate protection
- CSRF protection, prepared statements, escaped output, and user ownership checks

## Architecture decision

`incomes` remains the Phase 2 income-source table. It describes expected sources such as monthly allowance.

`transactions` stores money that was actually received or spent. This separation prevents a recurring plan from being counted as received money before a transaction exists.

The legacy `expenses` table is preserved temporarily because the older dashboard and budget modules still read it. Those modules are switched to `transactions` during their assigned phases.

## Database changes

- Existing installation: run `database/phase3_migration.sql` once.
- Fresh Phase 3 installation: import `database/phase3_schema.sql`.

The migration copies existing records using unique migration references, so running it again does not duplicate transactions.

## Manual test

1. Open **Transactions** from the sidebar.
2. Add an Income transaction and confirm category is hidden.
3. Add an Expense transaction and confirm category is required.
4. Check that totals follow `Income - Expenses = Balance`.
5. Edit both transactions and confirm totals update.
6. Search by merchant and description.
7. Filter by Income/Expense, category, and date range.
8. Delete test transactions and confirm totals update.
9. Log in with a second account and confirm it cannot see or edit the first account's transactions.

## Current limitations

- Transaction entry is manual only. Receipt and import sources are reserved for later phases.
- The dashboard remains on its earlier data source until Phase 4.
- Budget calculations move to unified transactions in Phase 5.

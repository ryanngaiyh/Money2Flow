# Money2Flow Phase 4 — Functional Dashboard

## Scope completed

- Dashboard calculations sourced entirely from unified transactions
- Week, month, and three-month viewing periods
- Period-aware income, expenses, and balance
- Remaining monthly budget summary
- Multi-point income-versus-expense chart
- Spending-by-category doughnut chart
- Five recent transactions for the selected period
- Category budget progress with 80% and 100% warnings
- Savings goal summaries and total savings
- Clear empty states and direct links to relevant modules

## Input → Process → Output

- **Input:** Transaction records, selected period, category budgets, and savings goals
- **Process:** Prepared SQL queries filter by user/date/type, aggregate amounts, calculate balance and budget usage, and group spending by category
- **Output:** Summary cards, charts, recent activity, budget warnings, and savings progress

## Database changes

None. Phase 4 uses the existing Phase 3 transaction schema.

## Manual test

1. Open the dashboard and select **This Month**.
2. Compare income, expenses, and balance with the Transaction page's current-month cards.
3. Add an Income transaction and refresh the dashboard; income and balance should increase.
4. Add an Expense transaction with a category; expenses, balance, recent transactions, and category chart should update.
5. Test **This Week**, **This Month**, and **3 Months**.
6. Change the month picker and confirm monthly figures change.
7. Confirm budget progress reflects expenses for the selected month.
8. Log in with a different account and confirm no first-user data appears.

## Current limitation

The dashboard displays existing budgets and savings goals but their management logic remains assigned to Phases 5 and 6. Reports remain assigned to Phase 10.

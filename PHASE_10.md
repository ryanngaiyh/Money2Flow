# Money2Flow Phase 10 — Analytics, Reports, and Final Polish

## Scope completed

- Monthly income, expenses, balance, and previous-month balance
- Six-month income-versus-expense comparison
- Daily spending trend for the selected month
- Spending breakdown by category
- Category month-over-month comparison
- Overall and category budget performance
- Savings progress and required monthly contributions
- Needs-versus-wants spending chart
- Rule-based financial insights with no AI claims
- Responsive chart, insight, table, and empty-state layouts
- Chart loading and library-failure states
- Updated academic Input-Process-Output explanation
- Final ownership and action validation hardening
- Calculation, CRUD, relationship, and cross-user review

## Data source correction

The previous Reports page still read the legacy `incomes` and `expenses` tables. Phase 10 replaces those queries with the unified `transactions` table used by the Dashboard and transaction module. Reports and Dashboard therefore use the same source of truth.

## Report calculations

- Balance = Income − Expenses
- Category share = Category expenses ÷ Total expenses × 100
- Category change = Selected-month spending − Previous-month spending
- Budget usage = Actual expense spending ÷ Budget amount × 100
- Budget remaining = Budget amount − Actual expense spending
- Savings progress = Current amount ÷ Target amount × 100
- Required monthly saving = Remaining amount ÷ Remaining average months
- Needs-versus-wants totals are based on the classification of each expense category

Uncategorized expense transactions remain included in total expenses and appear as **Uncategorized** in category reporting.

## Rule-based insights

The report can produce messages such as:

- Largest spending category and its share of monthly expenses
- Budget usage warnings from 80% and exceeded warnings from 100%
- Category spending increases or decreases from the previous month
- Required savings per month for target goals
- Overdue savings-goal warnings

These are deterministic calculations, not AI-generated advice.

## Security review

- Authenticated-route protection remains active through the shared layout
- All state-changing forms use CSRF tokens
- SQL writes and report filters use prepared statements
- Report month input is validated as `YYYY-MM`
- HTML output is escaped before rendering
- User-owned records are filtered by the current `user_id`
- Transaction, income, category, budget, and savings update actions now reject invalid actions and foreign record IDs before processing
- Receipt images remain private and ownership-protected
- Receipt confirmation remains one transaction per receipt
- Passwords remain stored with PHP password hashing

## Database review

No Phase 10 migration is required. Existing foreign keys cover:

- User → categories, transactions, budgets, savings goals, split bills, rules, and receipts
- Split bill → participants
- Category → transactions, budgets, and merchant rules
- Receipt → confirmed transaction

Cascade and restrict behavior was tested so one user’s cleanup or edits do not affect another user.

## Manual test

1. Open **Reports** and select a month containing transactions.
2. Compare income, expenses, and balance with Dashboard for the same month.
3. Verify the six-month comparison and daily spending charts.
4. Confirm category totals add up to monthly expenses, including Uncategorized when present.
5. Compare category values with the previous month.
6. Create an 80% or exceeded budget and verify its warning and progress bar.
7. Confirm savings progress and required monthly saving match Savings Goals.
8. Check Needs vs Wants against category classifications.
9. Verify rule-based insights change when transactions, budgets, or goals change.
10. Select a month with no records and check every empty state.
11. Test the report on a mobile-width browser.
12. Log in as another user and verify no amounts or labels from the first account appear.

## Files created or modified

- `reports/index.php`
- `ipo/index.php`
- `assets/css/style.css`
- `transactions/index.php`
- `income/index.php`
- `categories/index.php`
- `budgets/index.php`
- `savings/index.php`
- `README.md`
- `PHASE_10.md`

## Current limitations

- Reports are displayed in the application and do not export PDF or CSV.
- Chart.js is loaded from a CDN; if it is unavailable, report tables, totals, insights, and empty states remain usable.
- Insights describe recorded data and are not professional financial advice.

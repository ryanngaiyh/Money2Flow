# New Features Phase 5 — Dashboard Integration and Final Testing

Money2Flow remains the existing PHP 8.2 / MariaDB / XAMPP application. Phase 5 integrates the notification centre, savings completion/history, allowance planner, and purchase simulator into the existing dashboard. It does not introduce new database tables or change historical financial rows.

## Dashboard changes

- Keeps the existing income, expenses, remaining budget, charts, category breakdown, recent transactions, shared expenses, and savings progress.
- Labels **Current balance** as all recorded transactions through today plus any configured starting cash adjustment. The selected-period net remains visible beneath it; it is not confused with money currently available.
- Keeps the **Until Next Allowance** card and quick access to **Can I Afford It?**.
- Adds **Recent achievements** from completed/archived target goals and achieved monthly cycles, with status and a working **Goal History** link. Completed goals remain recognizable even when several active goals fill the ordinary goal preview.
- Adds **Recent alerts** with unread count, event date, and links. Alerts are historical events; the budget card shows current spending.
- Sets PHP's default date zone to `Asia/Kuala_Lumpur`, matching existing date-aware notification, savings, and allowance functions. The local MariaDB server reports `Asia/Singapore`, which has the same UTC+8 civil time.
- Moves Income Source, Budget, and Transaction request handling before page output, so successful edits and invalid-record redirects do not emit “headers already sent” warnings.

## Automated verification

Run `C:\xampp\php\php.exe tests/final_phase5.php`. It uses disposable accounts and removes them in `finally`. It checks login protection; user-owned data; all major pages loading; Income Source schedules not counting as received cash; Budget create/edit and invalid values; transaction add/edit/delete; 80% and 100% alert deduplication; current balance, budget and allowance recalculation; purchase simulation without a transaction; goal completion, notification, dashboard achievement and history link; and cross-user edit/delete rejection.

The earlier Phase 1–4 arithmetic and HTTP tests were also rerun and passed. Their controlled-date tests cover day-29/day-30 archiving, month rollover, allowance schedule edge cases, and due-today/negative purchase calculations without waiting for real dates. The Windows **Money2Flow Savings Maintenance** task is enabled, runs daily at 00:05, and its last checked result was 0 (success). The app-visit fallback remains in place when the computer is off at schedule time.

The desktop dashboard and a 390px mobile viewport were visually checked, including the allowance card, charts, achievements, alerts, and simulator. Neither mobile page had horizontal document overflow.

## Browser acceptance checklist

1. Log in and open **Dashboard**. Confirm Current balance, period income/expenses, remaining budget, charts, recent transactions, and savings goals are visible.
2. Open **Until Next Allowance**. Choose a recurring Income Source or manual allowance, add a savings reserve and unpaid commitment, and check the dashboard daily estimate.
3. Add or edit an Expense in a budgeted category. Return to Dashboard: current balance, budget progress, allowance amount, and any threshold alert should update. Refresh to confirm alerts are not duplicated.
4. Open **Can I Afford It?**. Simulate an amount greater than zero. Confirm before/after available money and daily spending, then check Transactions to confirm nothing was saved.
5. Reach 100% on a target Savings Goal. Confirm its Completed badge, one notification, Recent achievements on Dashboard, and the **Goal History** link. Monthly goals should remain active after a month's achievement.
6. Test a second account to confirm it cannot see or edit the first account's finance entries.
7. Narrow the browser window or use a phone-sized screen. Check the menu, dashboard cards, charts, achievements, alerts, and simulator for readable stacking without side-to-side scrolling.

## Known limits

- Current balance is based on recorded transactions and an optional starting cash adjustment, not a bank connection. The allowance estimate also depends on manually maintained commitments and prospective savings reserves.
- Income Source schedules and purchase simulations do not create transactions automatically. Students must record actual income or expenses when they happen.
- Old alerts remain as dated history even if spending later falls. Current budget progress should be used for today's position.
- The scheduled savings task cannot run while the computer is powered off; the next logged-in visit performs the idempotent catch-up.
- External Bootstrap and Chart.js assets are delivered by CDN, so chart/styling display needs internet access even though the finance data stays in the local database.

## Files updated in Phase 5

- `config/app.php`
- `includes/allowance.php`
- `dashboard/index.php`
- `savings/index.php`
- `transactions/index.php`
- `income/index.php`
- `budgets/index.php`
- `assets/css/style.css`
- `tests/final_phase5.php` (new)
- `NEW_FEATURES_PHASE_5.md` (new)
- `README.md`

No database schema or existing data was modified in Phase 5. Temporary test rows were removed.

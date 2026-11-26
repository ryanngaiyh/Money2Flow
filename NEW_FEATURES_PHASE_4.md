# New Features Phase 4 — Can I Afford It?

The purchase simulator is an authenticated, read-only page at `http://localhost/money-flow/simulator/index.php`. It reuses the Phase 3 allowance calculation and the current user's categories. A category is optional context; it does not alter the arithmetic.

## Input → process → output

- Input: optional purchase name and category; required positive price in MYR with at most two decimal places.
- Process: load the latest allowance plan from recorded transactions, unpaid commitments and reserved savings; subtract the proposed price in memory only; recompute the rounded-down daily spending estimate for the same remaining days.
- Output: available money before/after, daily estimate before/after, and a rule-based warning or explanation.

Zero, negative, scientific notation, more-than-two-decimal, and excessive amounts are rejected. A missing or stale allowance setup shows a link to configure it. When the allowance is due today, available money is compared but no daily figure is invented. A purchase beyond currently available discretionary funds shows the negative result and a warning.

## Database and safety

No schema migration, purchase table, transaction, or expense is created. The submitted form uses the existing CSRF protection. Categories are checked against the logged-in user's own category list. Simulation does **not** save the purchase or change any balance. If the student later buys the item, they must record it manually as an Expense transaction.

## Browser test

1. Set up a next allowance date in `Until Next Allowance` and visit `Can I Afford It?` from the sidebar, allowance page, or dashboard card.
2. Try a purchase name, amount, and optional category. Check the before/after values and message.
3. Try an amount greater than available funds, then invalid or zero amounts. Check that no purchase is saved in Transactions.
4. Change a real transaction or savings reserve, then simulate again; the figures should use the current data.

Run `C:\xampp\php\php.exe tests/purchase_phase4.php` for arithmetic and input edge cases, and `C:\xampp\php\php.exe tests/purchase_http_phase4.php` for an end-to-end local website test. The HTTP test creates and removes its own disposable user.

## Limitation

The simulator is guidance based on recorded money, not a bank balance or guarantee that a purchase is affordable. It does not automatically categorize or record the purchase, and the optional category does not check a separate budget limit.

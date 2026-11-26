# Money2Flow Phase 7 — Split Bills and Shared Expenses

## Scope completed

- Create student-focused shared bills with a description, total, and date
- Add between 2 and 20 participants, including **You**
- Select which participant paid the full bill
- Equal split with exact cent allocation
- Custom split with an exact-total validation rule
- Paid and pending participant states
- Mark individual shares as settled or pending again
- Delete a split bill and its participant records
- User-level access protection for bills and settlement actions
- **You are owed** and **You owe** summaries on Split Bills and Dashboard
- Responsive split-bill cards and participant form

## Database changes

Existing installations should run `database/phase7_migration.sql` once.

Fresh installations through Phase 7 can import `database/phase7_schema.sql`.

The two new tables are:

- `split_bills` — the bill owner, total, description, date, and split type
- `split_bill_participants` — participant name, share, settlement status, payer, and current-user flags

Deleting a split bill automatically deletes its participant records.

## Calculation rules

### Equal split

The total is converted into cents and divided by the number of participants. Any remainder cents are assigned one at a time from the first participant, so the stored shares always equal the bill total exactly.

Example: RM 10.00 split among 3 people becomes RM 3.34, RM 3.33, and RM 3.33.

### Custom split

Each share is converted into cents. The bill is accepted only when the sum equals the total exactly.

### Balances

- **You are owed** = pending shares of the other participants on bills where You are the payer
- **You owe** = your pending share on bills where another participant is the payer
- The payer is automatically recorded as Paid
- No real money is moved by the application

## Manual test

1. Open **Split Bills** and create an equal RM 80 Dinner bill for You, John, Sarah, and Alex.
2. Confirm all four shares are RM 20 and the sum is RM 80.
3. Select You as the payer and confirm **You are owed** is RM 60.
4. Mark John settled and confirm **You are owed** becomes RM 40.
5. Mark John pending again and confirm it returns to RM 60.
6. Create a custom RM 100 bill with shares RM 25, RM 30, and RM 45.
7. Confirm an incorrect custom total is rejected.
8. Select someone else as payer and confirm your pending share appears under **You owe**.
9. Confirm the same owed/owe totals appear on Dashboard.
10. Delete the test bills and verify their participant records are removed.
11. Log in as another user and confirm they cannot access or settle your bills.

## Current limitation

Split bills are private records managed by the account owner. Participants do not receive invitations or notifications, and no real payment service is connected.

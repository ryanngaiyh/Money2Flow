# Money2Flow Phase 8 — Automatic Categorization

## Scope completed

- Rule-based merchant categorization with no AI or machine-learning model
- Live category suggestions while entering an expense merchant
- Suggestions remain editable before the transaction is saved
- Server-side rule fallback when a known merchant is submitted without a category
- Optional **Remember this merchant and category** checkbox
- Personal rules are isolated to the logged-in user
- Saved merchant rules are visible and removable from Categories
- Longer keywords take priority, so `GrabFood` matches Food & Drinks before `Grab` matches Transportation

## Built-in rules

- McDonald's, Starbucks, GrabFood → Food & Drinks
- Grab, Shell, Petron → Transportation
- Popular Bookstore → Education
- Netflix, Steam → Entertainment
- Shopee → Shopping

These rules are installed for existing accounts by the migration and automatically created for newly registered accounts.

## Database changes

Existing installations should run `database/phase8_migration.sql` once.

Fresh installations through Phase 8 can import `database/phase8_schema.sql`.

The `category_rules` table stores:

- Rule owner
- Merchant keyword
- Suggested category
- Creation time

Merchant keywords are unique per user. Remembering the same merchant again updates its suggested category instead of creating a duplicate.

## Matching rules

1. Convert the merchant comparison to a case-insensitive search.
2. Find the user's rules whose keyword appears anywhere in the merchant text.
3. Prefer the longest matching keyword.
4. Suggest that rule's category.
5. Allow the user to select a different category manually.

Example: `GrabFood KLCC` matches `GrabFood`, not the shorter `Grab` rule.

## Manual test

1. Open **Transactions** and choose Expense.
2. Enter `Starbucks KLCC` and confirm Food & Drinks is suggested.
3. Enter `Grab ride` and confirm Transportation is suggested.
4. Enter `GrabFood delivery` and confirm Food & Drinks is suggested.
5. Change a suggested category manually and save; confirm the manual category is stored.
6. Enter an unknown merchant, choose a category, and enable **Remember this merchant**.
7. Start another transaction with that merchant and confirm the personal suggestion appears.
8. Open Categories and confirm the merchant rule is listed.
9. Delete that rule and confirm the merchant no longer receives a suggestion.
10. Log in as another user and confirm personal rules are not shared.

## Current limitation

Matching is intentionally based on simple merchant keywords. It does not use OCR, AI, fuzzy spelling correction, external merchant databases, or payment integrations.

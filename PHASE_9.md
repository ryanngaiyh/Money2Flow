# Money2Flow Phase 9 — Receipt Scanning and OCR

## Scope completed

- JPG, PNG, and WebP receipt upload with a 5 MB limit
- Mobile camera capture hint through the file input
- MIME-type and real-image validation
- Randomized private receipt filenames
- Local Tesseract OCR integration when the Windows executable is available
- Free Tesseract.js browser fallback when local Tesseract is unavailable
- Basic merchant, total amount, and date extraction
- Phase 8 automatic category suggestion from the detected merchant
- Mandatory editable review screen
- Explicit **Confirm & Save Transaction** action
- Receipt transaction source and duplicate-confirmation protection
- Receipt history, private image access, and draft deletion
- User ownership checks for uploads, images, OCR updates, and confirmations

## OCR choice and dependencies

The preferred server-side option is local Tesseract OCR because it is free, offline, and easy to call from PHP.

Local dependency:

1. Install Tesseract OCR for Windows with English language data.
2. Install it at `C:\Program Files\Tesseract-OCR\tesseract.exe`, or set the `TESSERACT_PATH` environment variable to the executable.
3. Ensure PHP is allowed to use `proc_open`.

Tesseract is not currently installed on this computer, so the application also loads Tesseract.js v5 from jsDelivr. It performs OCR inside the browser and requires internet access for its initial script, WebAssembly, and language-data download. If both OCR methods are unavailable, the review screen remains fully usable for manual entry.

No paid OCR service is used.

## Database changes

Existing installations should run `database/phase9_migration.sql` once.

Fresh installations through Phase 9 can import `database/phase9_schema.sql`.

The `receipt_uploads` table stores the protected file reference, OCR state and text, detected fields, confidence, and optional confirmed transaction link.

## Safety rules

- OCR never creates a transaction automatically.
- The user must review and confirm merchant, amount, date, and category.
- OCR confidence is informational and never replaces validation.
- Uploaded filenames are randomized and files are blocked from direct public directory access.
- Receipt images are served only after checking the logged-in owner.
- A receipt can create at most one transaction.

## Manual test

1. Open **Receipt Scanner** and upload a clear JPG, PNG, or WebP receipt.
2. Wait for browser OCR if local Tesseract is unavailable.
3. Confirm the review page shows detected merchant, amount, and date when readable.
4. Confirm a known merchant receives a category suggestion.
5. Correct at least one field manually.
6. Click **Confirm & Save Transaction**.
7. Verify the receipt is marked saved and the transaction source says Receipt OCR.
8. Reopen the receipt and confirm a duplicate transaction cannot be created.
9. Upload a blurry image and verify manual correction is still available.
10. Delete an unconfirmed draft.
11. Log in as another user and verify the receipt image and record are inaccessible.

## Current limitation

Extraction is intentionally limited to merchant, total, and date. Item-level extraction, PDF receipts, advanced image preprocessing, cloud OCR, and automatic transaction saving are not included.

# Guided academic cycle setup

The Dean's Office now has one cycle setup wizard at **/setup** (or use **Cycle Setup** in the left navigation). For the current cycle, it presents six numbered steps and lets staff jump between them without navigating through separate administrative pages.

1. **Academic year**: confirm the current year and save program recommendation and thank-you deadlines.
2. **Load awards**: upload the standardized scholarship/allocations file (CSV/Excel) or the original FY27 authority workbook (Excel). Uploads are validated and committed through the existing import services.
3. **Assign reviewers**: see all fund allocations and designate a primary program reviewer, optionally adding or updating an award pool on the same page. A reviewer needs an appropriate assignment in the user access configuration.
4. **Applicants**: upload applicants and map columns. The required mapping panel temporarily opens, then returns to the guided setup.
5. **Eligibility**: upload a term-specific enrollment report; new successful snapshots are used in approval verification.
6. **Open review**: confirm fund, allocation and reviewer prerequisites before opening faculty recommendations. Applicant and enrollment import may occur later, but eligibility must be verified before an individual award is approved.

Existing granular administrative pages remain available under Administration for exceptions and detailed settings.

## Clear test data before the new file

The first wizard step offers **Clear demo cycle records** only when **APP_ENV=testing**. It displays record counts and requires typing **DELETE {academic-year label}** before removal. The action does not run automatically after deployment or on opening the wizard.

The equivalent command is:

    php bin/console reset:demo 2027-28

This shows a **dry run**. To perform the cleanup in a verified nonproduction test environment:

    php bin/console reset:demo 2027-28 --apply=DELETE-2027-28

Safeguards:
- **Only APP_ENV=testing** can execute cleanup. Production mode is blocked.
- The cycle label must be explicitly named; the confirmation must match.
- A cycle containing student records with an email outside **@example.test** is **refused**, including recruitment nominations. This prevents mistaking real FERPA data for demo data.
- Any award referenced by a renewal in another cycle blocks cleanup.
- All changes use one database transaction. Foreign keys stay enabled.
- Clears cycle-linked scholarship authority, allocations, applications, recommendations, enrollment imports, awards, notifications, thank-you records, historical imports, and eligible unused scholarship catalog entries.
- Removes orphan synthetic student records and disables the three known example.test demo accounts.
- **Preserves configuration**: College/departments/programs/offerings, program mapping aliases, academic cycles and terms, saved review units, user configuration, rubrics, templates, and audit logs.
- Uploaded file objects and their protected physical files are retained for review under applicable records retention requirements. This is not secure file erasure.

Before any destructive action, verify the cycle/year and preview counts, and ensure a database backup or recoverable snapshot exists. Do not set APP_ENV=testing on a production database to bypass the guard. All real student records must remain in University-approved infrastructure.

## After the reset

The **Available Awards** inventory for the selected demo cycle will be empty. Begin in **Cycle Setup → Load awards**, import the new allocation file, and continue to reviewer assignments. It is normal to import applicant and enrollment data later as they become available.

The MAUI preparation export remains a separate feature of Award Tracker; its columns must be validated against the real MAUI bulk-upload format before production use.

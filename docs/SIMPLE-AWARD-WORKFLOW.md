# Simplified award workspace

The app now begins at **Available Awards**, with three primary views:

1. **Available Awards** — annual fund inventory, program/department allocations, committed recommendations, and amounts still available. Only the Dean's Office sees unmatched/unassigned authority and UICA account details.
2. **Awards & Recipients** — a single list of applicant-based recommendations and faculty recruitment nominations for students who did not apply. Dean's Office staff can move proposed recipients into existing eligibility verification.
3. **Award Tracker** — recipient awards from verification through MAUI-file export, personalized notification, and thank-you submission.

The advanced legacy pages remain in the collapsed **Administration** menu, so already-developed applicant mapping, enrollment uploads, donor restrictions, academic-year rollover, recipient PDF letters, and secure student thank-you upload continue to work.

## Standard annual allocation import

On **Available Awards**, select **Import standardized allocations** and download the blank template. Supported formats: CSV, TSV, XLS, XLSX. The importer requires these exact column names:

| Column | Required? | Description |
| --- | --- | --- |
| academic_year | Yes | Exact current cycle label displayed in the app, such as 2027-28 |
| uica_account_number | Yes | Fund/account identifier, handled as text |
| scholarship_name | Yes | Exact scholarship catalog name |
| total_amount | Yes | Entire annual authorized amount for this scholarship |
| department | Yes if program blank | Canonical College department name |
| program | Optional | Canonical College program name, scoped within the supplied department |
| recruiting_pool | Optional | Descriptive recruiting pool, restricted to one pool per fund and unit |
| allocation_amount | Yes | Amount assigned to this department/program/pool |
| number_of_awards | Optional | Planned recipient count, positive integer |
| suggested_award_amount | Optional | Suggested amount per award |

Repeat a scholarship/account across different department/program rows, keeping its total_amount identical in every row. Total allocations may be below the authorized amount but cannot exceed it. Imports update the same fund/unit allocation rather than creating duplicate records; imports are transactional and preserve existing student recommendations and awards. If a fund already contains donor-intent criteria, the import does not overwrite them. For newly created fund records, the Dean's Office must review and enter donor criteria before approval.

The existing **FY27 authority workbook** importer remains available for the more complex original financial file. Its data stays administrator-only.

## Recipient identification

For students who applied, use **Select from applicants** (the existing program review screens). For a recruitment candidate who did not apply, use **Nominate a recruitment student** on **Awards & Recipients**. The coordinator must have the current cycle's review assignment; department chairs remain view-only. The nominee needs a verified university ID and email, and the nomination cannot exceed the available allocation.

Recruitment nominations are initially tagged insufficient_information; they **do not bypass eligibility verification**. A Dean's Office reviewer moves a recommendation to verification and approves it only after an eligible enrollment snapshot is uploaded. Existing PDF award-letter and secure recipient-activation/thank-you workflows are unchanged.

## MAUI export

On **Award Tracker**, the Dean's Office can generate a **MAUI preparation CSV** for newly approved, enrollment-verified awards. It produces one row per term distribution with these columns:

University ID, UICA Account Number, Academic Term, Amount, Award ID, Scholarship

The exporter validates that the distribution total equals each recipient's approved award, excludes already exported awards, records a batch and timestamp, and never treats generating a file as proof that MAUI processed the award.

**Do not upload to production MAUI until the real MAUI bulk-upload template and field requirements have been confirmed.** This is a preparation export, not an integration that posts awards to MAUI.

## Deployment and data safety

Target branch: feature/simple-awards-workspace, based on ux/saas-shell.

Run database migrations as part of the normal deployment before serving updated pages:

    php bin/console migrate

These are additive migrations; no old awards, applications, scholarship funds, or assignments are deleted. Before migrating real data, perform a verified database backup or recovery snapshot and rehearse the migration in a separate test environment.

For nonproduction testing, set APP_ENV=testing and MAIL_DRIVER=log to avoid accidentally emailing real students. Only University-authorized systems/environments should handle student records and restricted data. Keep private spreadsheets and exports out of GitHub.

Run the repository's CI and php tests/simple_workspace.php. The tests cover initial allocation import, idempotent re-import, authority-overrun rejection, and wrong-cycle rejection.

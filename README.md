# College of Education Scholarship Manager

Internal scholarship-management application for the University of Iowa College of Education.

## Status

The non-University-specific application workflow is implemented and ready for deployment/testing.

University-specific production integrations intentionally remain separate:

- HawkID / SSO
- University production email provider
- final production branding assets

## Stack

- PHP 8.5+
- MariaDB 10.11+
- Nginx + PHP-FPM
- Composer-managed dependencies
- AWS/Linux hosting

## Implemented workflow

- academic cycle setup and rollover
- scholarship planning and donor-intent criteria
- renewable awards
- program allocations
- reviewers, rubrics, and ranked recommendations
- applicant import and column mapping
- canonical College program mapping
- explainable eligibility analysis
- MAUI enrollment snapshot import
- Dean's Office recommendation review
- unused-dollar reallocation
- enrollment verification
- award distributions
- new and renewal award letters
- Ready to Notify queue
- safe log-mail provider for testing
- recipient activation and award portal
- distribution-change requests
- thank-you submission, reminders, scoped reviewer access, and UICA ZIP downloads
- structured historical award imports
- cycle reports/CSV exports and closeout
- audit logging
- fake end-to-end test data
- FY27 scholarship workbook import/seed workflow
- deployment doctor and health endpoint

## Local setup

```bash
cp .env.example .env
composer install
php bin/console migrate
php bin/console seed:base
php bin/console seed:admin
APP_ENV=testing php bin/console seed:test
APP_ENV=testing php bin/console seed:fy27 "/path/to/FY27 Scholarship Amounts.xlsx"
composer test
```

For a deployable testing environment, use:

```dotenv
APP_ENV=testing
AUTH_DRIVER=local
MAIL_DRIVER=log
SESSION_SECURE=true
```

The log mail provider writes rendered messages to:

```
storage/logs/mail.log
```

This allows award and reminder notification workflows to be tested without sending real email.

## Deployment

See `deploy/DEPLOYMENT.md`.

Useful commands:

```bash
php bin/console migrate
php bin/console seed:base
php bin/console seed:admin
APP_ENV=testing php bin/console seed:test
APP_ENV=testing php bin/console seed:fy27 "/path/to/FY27 Scholarship Amounts.xlsx"
php bin/console doctor
php bin/console jobs:work
```

Health check:

```
GET /healthz
```

Expected response:

```
ok
```

## Architecture

The application separates:

- permanent scholarship/fund identity
- immutable/versioned donor intent
- annual scholarship planning
- program allocations
- recipient awards
- term distributions
- structured historical awards

UICA/Fund ID identifies the underlying accounting fund, but one fund can support multiple distinct award pools. Scholarship records therefore use a separate source-record key while retaining the shared UICA/Fund ID. MFK remains a secondary identifier.

Imported program names are never allowed to create arbitrary College programs. They are resolved into the canonical Department → Program → official program-offering architecture.

## FY27 workbook test data

In non-production environments, the real FY27 scholarship workbook can be loaded as repeatable test data:

```bash
APP_ENV=testing php bin/console seed:fy27 "/path/to/FY27 Scholarship Amounts.xlsx"
```

The importer reads the scholarship and spring-award simplified sheets, both detailed All data sheets, and the UICA account sheet. It creates the 2026-27 cycle, imports annual award authority, preserves the original source rows, and stores detailed fund/account information for administrators only. Re-running the command updates the cycle rather than creating duplicate award-pool records.

The importer also supports multiple scholarships/award pools on one UICA fund. This is required for FY27 fund 30-350-022, which contains separate Stucker, Chung, and general Student Aid Fund award pools.

## Testing

GitHub Actions validates:

- Composer metadata
- PHP syntax
- every MariaDB migration
- official College program seed data
- fake reviewer/applicant/recipient workflow data
- seeded workflow integration assertions
- FY27 workbook import behavior, including multiple award pools sharing one UICA fund
- deployment readiness checks
- domain behavior tests

No real student data or production credentials are committed.

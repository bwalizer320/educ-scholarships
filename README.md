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
- deployment doctor and health endpoint

## Local setup

```bash
cp .env.example .env
composer install
php bin/console migrate
php bin/console seed:base
php bin/console seed:admin
APP_ENV=testing php bin/console seed:test
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

UICA Account Number is the primary scholarship business/accounting identifier. MFK is stored as a secondary identifier.

Imported program names are never allowed to create arbitrary College programs. They are resolved into the canonical Department → Program → official program-offering architecture.

## Testing

GitHub Actions validates:

- Composer metadata
- PHP syntax
- every MariaDB migration
- official College program seed data
- fake reviewer/applicant/recipient workflow data
- seeded workflow integration assertions
- deployment readiness checks
- domain behavior tests

No real student data or production credentials are committed.

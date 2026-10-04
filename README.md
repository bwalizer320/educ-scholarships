# College of Education Scholarship Manager

Internal scholarship-management application for the University of Iowa College of Education.

## Status

Phase 1 foundation implementation is in progress.

## Stack

- PHP 8.5+
- MariaDB 10.11+
- Apache 2.4+
- AWS hosting
- Composer-managed dependencies

## Local setup

```bash
cp .env.example .env
composer install
php bin/console migrate
php bin/console seed:base
composer serve
```

The base seed creates the College of Education organizational hierarchy from the official program relationship data currently available in the repository.

## Architecture

See `docs/PHASE-1.md` for the foundation currently implemented. The application keeps permanent scholarship/donor-intent data separate from annual-cycle activity, uses UICA Account Number as the scholarship business identifier, and maps imported program values into canonical College program records.

## Development

The initial implementation is being developed on the `phase-1-foundation` branch before merge to `main`.

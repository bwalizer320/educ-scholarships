# Phase 1 — Foundation

This branch begins the implementation of the College of Education Scholarship Manager.

## Included

- PHP/Composer project scaffold
- Environment configuration
- PDO/MariaDB connection
- SQL migration runner
- Initial accessible University of Iowa-themed landing page
- Four staff roles
- Staff/student distinction
- Organizational unit hierarchy
- Official MAUI program-offering model
- Source-value mapping layer
- Academic cycles and terms
- New-cycle checklist storage
- Scholarship master keyed by UICA Account Number
- Immutable/versioned donor intent
- Structured scholarship criteria
- Renewable/amount/distribution rule storage
- Annual scholarship planning records
- Staff unit assignments and scoped permissions
- Immutable audit log
- Initial official graduate program source data

## Deliberate constraints

- Donor intent has no normal edit workflow.
- Student-facing access is distinct from staff roles.
- Summer scholarship workflow is not modeled.
- Spendable cash is not modeled.
- Program imports map into canonical program records rather than creating programs from arbitrary strings.
- UICA Account Number is the primary scholarship business identifier; MFK is secondary.

## Next migration

The next database migration should add:

- annual allocations and review units
- rubrics and scores
- students/applications and import staging
- eligibility assessments and rule results
- recommendations
- renewal candidates

Then the MAUI enrollment/award/notification/thank-you tables should follow in a subsequent migration.

CREATE TABLE users (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    public_id CHAR(36) NOT NULL UNIQUE,
    person_type ENUM('staff','student') NOT NULL,
    staff_role ENUM('system_admin','deans_office_admin','department_chair','program_coordinator') NULL,
    first_name VARCHAR(120) NOT NULL,
    last_name VARCHAR(120) NOT NULL,
    display_name VARCHAR(255) NOT NULL,
    email VARCHAR(320) NOT NULL,
    hawkid VARCHAR(100) NULL,
    active TINYINT(1) NOT NULL DEFAULT 1,
    last_login_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_users_email (email),
    UNIQUE KEY uq_users_hawkid (hawkid)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE auth_identities (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    provider VARCHAR(50) NOT NULL,
    provider_subject VARCHAR(255) NOT NULL,
    password_hash VARCHAR(255) NULL,
    activated_at DATETIME NULL,
    last_authenticated_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_auth_provider_subject (provider, provider_subject),
    CONSTRAINT fk_auth_user FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE org_units (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    public_id CHAR(36) NOT NULL UNIQUE,
    unit_type ENUM('college','department','program','center') NOT NULL,
    parent_id BIGINT UNSIGNED NULL,
    code VARCHAR(64) NULL,
    name VARCHAR(255) NOT NULL,
    active TINYINT(1) NOT NULL DEFAULT 1,
    sort_order INT NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_org_parent FOREIGN KEY (parent_id) REFERENCES org_units(id),
    KEY idx_org_parent (parent_id),
    KEY idx_org_type_active (unit_type, active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE program_offerings (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    org_unit_id BIGINT UNSIGNED NOT NULL,
    academic_level_code VARCHAR(20) NULL,
    pgms_program_descr VARCHAR(255) NOT NULL,
    pgms_objective_key VARCHAR(50) NOT NULL,
    pgms_sub_program_descr VARCHAR(255) NULL,
    pgms_sub_program_id VARCHAR(50) NULL,
    active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_offering_org FOREIGN KEY (org_unit_id) REFERENCES org_units(id),
    UNIQUE KEY uq_offering_source (
        pgms_program_descr,
        pgms_objective_key,
        pgms_sub_program_descr,
        pgms_sub_program_id
    )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE program_mapping_aliases (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    source_system VARCHAR(50) NOT NULL,
    source_program VARCHAR(255) NOT NULL,
    source_objective VARCHAR(50) NULL,
    source_subprogram VARCHAR(255) NULL,
    source_subprogram_id VARCHAR(50) NULL,
    program_offering_id BIGINT UNSIGNED NULL,
    org_unit_id BIGINT UNSIGNED NOT NULL,
    active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_map_offering FOREIGN KEY (program_offering_id) REFERENCES program_offerings(id),
    CONSTRAINT fk_map_org FOREIGN KEY (org_unit_id) REFERENCES org_units(id),
    KEY idx_mapping_lookup (source_system, source_program)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE academic_cycles (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    public_id CHAR(36) NOT NULL UNIQUE,
    label VARCHAR(20) NOT NULL UNIQUE,
    start_year SMALLINT UNSIGNED NOT NULL,
    end_year SMALLINT UNSIGNED NOT NULL,
    status ENUM('setup','ready_for_applicants','review_open','review_closed','award_processing','complete','archived') NOT NULL DEFAULT 'setup',
    program_review_opens_at DATETIME NULL,
    program_review_due_at DATETIME NULL,
    renewal_review_due_at DATETIME NULL,
    thank_you_due_at DATETIME NULL,
    distribution_change_due_at DATETIME NULL,
    next_application_due_at DATETIME NULL,
    created_from_cycle_id BIGINT UNSIGNED NULL,
    is_current TINYINT(1) NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_cycle_source FOREIGN KEY (created_from_cycle_id) REFERENCES academic_cycles(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE academic_terms (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    cycle_id BIGINT UNSIGNED NOT NULL,
    term_key VARCHAR(30) NOT NULL,
    display_name VARCHAR(100) NOT NULL,
    season ENUM('fall','spring') NOT NULL,
    calendar_year SMALLINT UNSIGNED NOT NULL,
    starts_on DATE NULL,
    ends_on DATE NULL,
    is_primary_enrollment_term TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_term_cycle FOREIGN KEY (cycle_id) REFERENCES academic_cycles(id),
    UNIQUE KEY uq_cycle_term (cycle_id, term_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE cycle_checklist_items (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    cycle_id BIGINT UNSIGNED NOT NULL,
    item_key VARCHAR(100) NOT NULL,
    label VARCHAR(255) NOT NULL,
    status ENUM('not_started','in_progress','confirmed','not_applicable') NOT NULL DEFAULT 'not_started',
    confirmed_by_user_id BIGINT UNSIGNED NULL,
    confirmed_at DATETIME NULL,
    notes TEXT NULL,
    sort_order INT NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_checklist_cycle FOREIGN KEY (cycle_id) REFERENCES academic_cycles(id),
    CONSTRAINT fk_checklist_user FOREIGN KEY (confirmed_by_user_id) REFERENCES users(id),
    UNIQUE KEY uq_cycle_checklist (cycle_id, item_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE scholarships (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    public_id CHAR(36) NOT NULL UNIQUE,
    uica_account_number VARCHAR(100) NOT NULL,
    mfk VARCHAR(150) NULL,
    name VARCHAR(255) NOT NULL,
    award_type ENUM('scholarship','fellowship','award','student_aid') NOT NULL DEFAULT 'scholarship',
    active TINYINT(1) NOT NULL DEFAULT 1,
    current_intent_version_id BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_scholarship_uica (uica_account_number)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE scholarship_intent_versions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    scholarship_id BIGINT UNSIGNED NOT NULL,
    version_number INT UNSIGNED NOT NULL,
    effective_cycle_id BIGINT UNSIGNED NULL,
    original_intent_text LONGTEXT NOT NULL,
    structured_summary TEXT NULL,
    source_reference VARCHAR(500) NULL,
    approved_at DATETIME NULL,
    active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_intent_scholarship FOREIGN KEY (scholarship_id) REFERENCES scholarships(id),
    CONSTRAINT fk_intent_cycle FOREIGN KEY (effective_cycle_id) REFERENCES academic_cycles(id),
    UNIQUE KEY uq_intent_version (scholarship_id, version_number)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE scholarships
    ADD CONSTRAINT fk_scholarship_current_intent
    FOREIGN KEY (current_intent_version_id) REFERENCES scholarship_intent_versions(id);

CREATE TABLE scholarship_criteria (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    intent_version_id BIGINT UNSIGNED NOT NULL,
    criterion_kind ENUM('required','preferred','preference_fallback','display_only') NOT NULL,
    source_stage ENUM('application','enrollment','either','derived') NOT NULL DEFAULT 'application',
    field_key VARCHAR(150) NULL,
    operator VARCHAR(50) NULL,
    comparison_value_json JSON NULL,
    priority_rank INT NULL,
    auto_evaluable TINYINT(1) NOT NULL DEFAULT 1,
    display_label VARCHAR(255) NOT NULL,
    display_requirement TEXT NOT NULL,
    sort_order INT NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_criterion_intent FOREIGN KEY (intent_version_id) REFERENCES scholarship_intent_versions(id),
    KEY idx_criteria_intent (intent_version_id, sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE scholarship_award_rules (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    intent_version_id BIGINT UNSIGNED NOT NULL UNIQUE,
    renewable TINYINT(1) NOT NULL DEFAULT 0,
    max_total_award_years INT UNSIGNED NULL,
    renewal_requires_current_criteria TINYINT(1) NOT NULL DEFAULT 1,
    amount_mode ENUM('fixed_per_award','flexible_within_total','equal_among_recipients','manual_rule') NOT NULL DEFAULT 'fixed_per_award',
    student_teaching_required TINYINT(1) NOT NULL DEFAULT 0,
    single_semester_allowed_if_graduating TINYINT(1) NOT NULL DEFAULT 1,
    manual_amount_rule_text TEXT NULL,
    manual_distribution_rule_text TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_award_rule_intent FOREIGN KEY (intent_version_id) REFERENCES scholarship_intent_versions(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE cycle_scholarships (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    cycle_id BIGINT UNSIGNED NOT NULL,
    scholarship_id BIGINT UNSIGNED NOT NULL,
    intent_version_id BIGINT UNSIGNED NOT NULL,
    total_authorized_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    planned_new_award_count INT UNSIGNED NULL,
    suggested_new_award_amount DECIMAL(12,2) NULL,
    planning_status ENUM('draft','confirmed','closed') NOT NULL DEFAULT 'draft',
    confirmed_by_user_id BIGINT UNSIGNED NULL,
    confirmed_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_cycle_sch_cycle FOREIGN KEY (cycle_id) REFERENCES academic_cycles(id),
    CONSTRAINT fk_cycle_sch_sch FOREIGN KEY (scholarship_id) REFERENCES scholarships(id),
    CONSTRAINT fk_cycle_sch_intent FOREIGN KEY (intent_version_id) REFERENCES scholarship_intent_versions(id),
    CONSTRAINT fk_cycle_sch_user FOREIGN KEY (confirmed_by_user_id) REFERENCES users(id),
    UNIQUE KEY uq_cycle_scholarship (cycle_id, scholarship_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE user_unit_assignments (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    org_unit_id BIGINT UNSIGNED NOT NULL,
    assignment_type ENUM('chair','coordinator','reviewer') NOT NULL,
    cycle_id BIGINT UNSIGNED NULL,
    active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_assignment_user FOREIGN KEY (user_id) REFERENCES users(id),
    CONSTRAINT fk_assignment_org FOREIGN KEY (org_unit_id) REFERENCES org_units(id),
    CONSTRAINT fk_assignment_cycle FOREIGN KEY (cycle_id) REFERENCES academic_cycles(id),
    KEY idx_assignment_scope (org_unit_id, cycle_id, active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE user_permissions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    permission_key VARCHAR(100) NOT NULL,
    org_unit_id BIGINT UNSIGNED NULL,
    cycle_id BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_permission_user FOREIGN KEY (user_id) REFERENCES users(id),
    CONSTRAINT fk_permission_org FOREIGN KEY (org_unit_id) REFERENCES org_units(id),
    CONSTRAINT fk_permission_cycle FOREIGN KEY (cycle_id) REFERENCES academic_cycles(id),
    KEY idx_permission_user_key (user_id, permission_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE audit_log (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    actor_user_id BIGINT UNSIGNED NULL,
    event_type VARCHAR(120) NOT NULL,
    entity_type VARCHAR(120) NOT NULL,
    entity_id BIGINT UNSIGNED NOT NULL,
    cycle_id BIGINT UNSIGNED NULL,
    before_json JSON NULL,
    after_json JSON NULL,
    ip_address VARCHAR(64) NULL,
    user_agent VARCHAR(500) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_audit_actor FOREIGN KEY (actor_user_id) REFERENCES users(id),
    CONSTRAINT fk_audit_cycle FOREIGN KEY (cycle_id) REFERENCES academic_cycles(id),
    KEY idx_audit_entity (entity_type, entity_id),
    KEY idx_audit_cycle (cycle_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

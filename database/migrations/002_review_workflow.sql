CREATE TABLE cycle_review_units (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    cycle_id BIGINT UNSIGNED NOT NULL,
    org_unit_id BIGINT UNSIGNED NOT NULL,
    primary_reviewer_user_id BIGINT UNSIGNED NOT NULL,
    review_method ENUM('selection','rubric') NOT NULL DEFAULT 'selection',
    due_at DATETIME NOT NULL,
    status ENUM('not_started','draft','submitted','closed','reopened','resubmitted') NOT NULL DEFAULT 'not_started',
    submitted_at DATETIME NULL,
    closed_at DATETIME NULL,
    reopened_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_review_unit_cycle FOREIGN KEY (cycle_id) REFERENCES academic_cycles(id),
    CONSTRAINT fk_review_unit_org FOREIGN KEY (org_unit_id) REFERENCES org_units(id),
    CONSTRAINT fk_review_unit_reviewer FOREIGN KEY (primary_reviewer_user_id) REFERENCES users(id),
    UNIQUE KEY uq_review_unit_cycle_org (cycle_id, org_unit_id),
    KEY idx_review_unit_status (cycle_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE cycle_allocations (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    cycle_scholarship_id BIGINT UNSIGNED NOT NULL,
    org_unit_id BIGINT UNSIGNED NOT NULL,
    primary_reviewer_user_id BIGINT UNSIGNED NULL,
    authorized_new_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    planned_new_award_count INT UNSIGNED NULL,
    suggested_award_amount DECIMAL(12,2) NULL,
    status ENUM('draft','confirmed','review_open','submitted','closed') NOT NULL DEFAULT 'draft',
    review_due_at DATETIME NULL,
    no_candidate TINYINT(1) NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_allocation_cycle_sch FOREIGN KEY (cycle_scholarship_id) REFERENCES cycle_scholarships(id),
    CONSTRAINT fk_allocation_org FOREIGN KEY (org_unit_id) REFERENCES org_units(id),
    CONSTRAINT fk_allocation_reviewer FOREIGN KEY (primary_reviewer_user_id) REFERENCES users(id),
    UNIQUE KEY uq_allocation_sch_org (cycle_scholarship_id, org_unit_id),
    KEY idx_allocation_org_status (org_unit_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE rubrics (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    cycle_id BIGINT UNSIGNED NOT NULL,
    org_unit_id BIGINT UNSIGNED NOT NULL,
    name VARCHAR(255) NOT NULL,
    status ENUM('draft','confirmed') NOT NULL DEFAULT 'draft',
    copied_from_rubric_id BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_rubric_cycle FOREIGN KEY (cycle_id) REFERENCES academic_cycles(id),
    CONSTRAINT fk_rubric_org FOREIGN KEY (org_unit_id) REFERENCES org_units(id),
    CONSTRAINT fk_rubric_source FOREIGN KEY (copied_from_rubric_id) REFERENCES rubrics(id),
    UNIQUE KEY uq_rubric_cycle_org (cycle_id, org_unit_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE rubric_items (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    rubric_id BIGINT UNSIGNED NOT NULL,
    label VARCHAR(255) NOT NULL,
    description TEXT NULL,
    max_points DECIMAL(8,2) NOT NULL,
    sort_order INT NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_rubric_item_rubric FOREIGN KEY (rubric_id) REFERENCES rubrics(id) ON DELETE CASCADE,
    KEY idx_rubric_item_sort (rubric_id, sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE students (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    public_id CHAR(36) NOT NULL UNIQUE,
    university_id VARCHAR(50) NOT NULL,
    hawkid VARCHAR(100) NULL,
    first_name VARCHAR(120) NOT NULL,
    last_name VARCHAR(120) NOT NULL,
    display_name VARCHAR(255) NOT NULL,
    email VARCHAR(320) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_student_university_id (university_id),
    KEY idx_student_hawkid (hawkid),
    KEY idx_student_name (last_name, first_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE application_imports (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    cycle_id BIGINT UNSIGNED NOT NULL,
    filename VARCHAR(255) NOT NULL,
    mapping_profile VARCHAR(100) NULL,
    status ENUM('uploaded','validated','needs_mapping','ready','processing','completed','failed') NOT NULL DEFAULT 'uploaded',
    row_count INT UNSIGNED NOT NULL DEFAULT 0,
    inserted_count INT UNSIGNED NOT NULL DEFAULT 0,
    updated_count INT UNSIGNED NOT NULL DEFAULT 0,
    error_count INT UNSIGNED NOT NULL DEFAULT 0,
    imported_by_user_id BIGINT UNSIGNED NOT NULL,
    completed_at DATETIME NULL,
    error_summary TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_app_import_cycle FOREIGN KEY (cycle_id) REFERENCES academic_cycles(id),
    CONSTRAINT fk_app_import_user FOREIGN KEY (imported_by_user_id) REFERENCES users(id),
    KEY idx_app_import_status (cycle_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE application_import_rows (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    application_import_id BIGINT UNSIGNED NOT NULL,
    source_row_number INT UNSIGNED NOT NULL,
    university_id VARCHAR(50) NULL,
    mapped_student_id BIGINT UNSIGNED NULL,
    mapped_org_unit_id BIGINT UNSIGNED NULL,
    status VARCHAR(50) NOT NULL,
    raw_json JSON NOT NULL,
    error_json JSON NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_app_row_import FOREIGN KEY (application_import_id) REFERENCES application_imports(id) ON DELETE CASCADE,
    CONSTRAINT fk_app_row_student FOREIGN KEY (mapped_student_id) REFERENCES students(id),
    CONSTRAINT fk_app_row_org FOREIGN KEY (mapped_org_unit_id) REFERENCES org_units(id),
    KEY idx_app_row_status (application_import_id, status),
    KEY idx_app_row_student (mapped_student_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE applications (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    cycle_id BIGINT UNSIGNED NOT NULL,
    student_id BIGINT UNSIGNED NOT NULL,
    latest_import_id BIGINT UNSIGNED NOT NULL,
    org_unit_id BIGINT UNSIGNED NULL,
    program_offering_id BIGINT UNSIGNED NULL,
    academic_level VARCHAR(50) NULL,
    degree_objective VARCHAR(50) NULL,
    classification VARCHAR(100) NULL,
    gpa DECIMAL(5,3) NULL,
    residency_state VARCHAR(100) NULL,
    residency_county VARCHAR(100) NULL,
    citizenship_country VARCHAR(100) NULL,
    first_generation TINYINT(1) NULL,
    financial_need TINYINT(1) NULL,
    student_teaching_term_id BIGINT UNSIGNED NULL,
    application_values_json JSON NULL,
    application_responses_json JSON NULL,
    active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_application_cycle FOREIGN KEY (cycle_id) REFERENCES academic_cycles(id),
    CONSTRAINT fk_application_student FOREIGN KEY (student_id) REFERENCES students(id),
    CONSTRAINT fk_application_import FOREIGN KEY (latest_import_id) REFERENCES application_imports(id),
    CONSTRAINT fk_application_org FOREIGN KEY (org_unit_id) REFERENCES org_units(id),
    CONSTRAINT fk_application_offering FOREIGN KEY (program_offering_id) REFERENCES program_offerings(id),
    CONSTRAINT fk_application_teaching_term FOREIGN KEY (student_teaching_term_id) REFERENCES academic_terms(id),
    UNIQUE KEY uq_application_cycle_student (cycle_id, student_id),
    KEY idx_application_org (cycle_id, org_unit_id, active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE rubric_scores (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    rubric_id BIGINT UNSIGNED NOT NULL,
    student_id BIGINT UNSIGNED NOT NULL,
    reviewer_user_id BIGINT UNSIGNED NOT NULL,
    total_points DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    completed_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_rubric_score_rubric FOREIGN KEY (rubric_id) REFERENCES rubrics(id) ON DELETE CASCADE,
    CONSTRAINT fk_rubric_score_student FOREIGN KEY (student_id) REFERENCES students(id),
    CONSTRAINT fk_rubric_score_reviewer FOREIGN KEY (reviewer_user_id) REFERENCES users(id),
    UNIQUE KEY uq_rubric_score_student (rubric_id, student_id, reviewer_user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE rubric_score_items (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    rubric_score_id BIGINT UNSIGNED NOT NULL,
    rubric_item_id BIGINT UNSIGNED NOT NULL,
    points DECIMAL(8,2) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_score_item_score FOREIGN KEY (rubric_score_id) REFERENCES rubric_scores(id) ON DELETE CASCADE,
    CONSTRAINT fk_score_item_item FOREIGN KEY (rubric_item_id) REFERENCES rubric_items(id) ON DELETE CASCADE,
    UNIQUE KEY uq_score_item (rubric_score_id, rubric_item_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE eligibility_assessments (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    cycle_scholarship_id BIGINT UNSIGNED NOT NULL,
    student_id BIGINT UNSIGNED NOT NULL,
    application_id BIGINT UNSIGNED NULL,
    status ENUM('eligible','eligible_unmet_preference','potentially_ineligible','insufficient_information') NOT NULL,
    evaluated_at DATETIME NOT NULL,
    source_signature CHAR(64) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_elig_cycle_sch FOREIGN KEY (cycle_scholarship_id) REFERENCES cycle_scholarships(id),
    CONSTRAINT fk_elig_student FOREIGN KEY (student_id) REFERENCES students(id),
    CONSTRAINT fk_elig_application FOREIGN KEY (application_id) REFERENCES applications(id),
    KEY idx_elig_latest (cycle_scholarship_id, student_id, evaluated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE eligibility_rule_results (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    eligibility_assessment_id BIGINT UNSIGNED NOT NULL,
    scholarship_criterion_id BIGINT UNSIGNED NOT NULL,
    result ENUM('met','not_met','unknown','not_evaluated') NOT NULL,
    source_name VARCHAR(150) NOT NULL,
    source_value_display VARCHAR(500) NULL,
    source_value_json JSON NULL,
    message VARCHAR(1000) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_elig_result_assessment FOREIGN KEY (eligibility_assessment_id) REFERENCES eligibility_assessments(id) ON DELETE CASCADE,
    CONSTRAINT fk_elig_result_criterion FOREIGN KEY (scholarship_criterion_id) REFERENCES scholarship_criteria(id),
    KEY idx_elig_result_assessment (eligibility_assessment_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE recommendations (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    cycle_allocation_id BIGINT UNSIGNED NOT NULL,
    student_id BIGINT UNSIGNED NOT NULL,
    rank_position INT UNSIGNED NOT NULL,
    recommended_amount DECIMAL(12,2) NOT NULL,
    eligibility_assessment_id BIGINT UNSIGNED NULL,
    eligibility_status_at_submission ENUM('eligible','eligible_unmet_preference','potentially_ineligible','insufficient_information') NULL,
    submitted_snapshot_json JSON NULL,
    created_by_user_id BIGINT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_recommendation_allocation FOREIGN KEY (cycle_allocation_id) REFERENCES cycle_allocations(id),
    CONSTRAINT fk_recommendation_student FOREIGN KEY (student_id) REFERENCES students(id),
    CONSTRAINT fk_recommendation_elig FOREIGN KEY (eligibility_assessment_id) REFERENCES eligibility_assessments(id),
    CONSTRAINT fk_recommendation_user FOREIGN KEY (created_by_user_id) REFERENCES users(id),
    UNIQUE KEY uq_recommendation_student (cycle_allocation_id, student_id),
    UNIQUE KEY uq_recommendation_rank (cycle_allocation_id, rank_position),
    KEY idx_recommendation_student (student_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE renewal_candidates (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    cycle_id BIGINT UNSIGNED NOT NULL,
    scholarship_id BIGINT UNSIGNED NOT NULL,
    cycle_scholarship_id BIGINT UNSIGNED NOT NULL,
    student_id BIGINT UNSIGNED NOT NULL,
    prior_award_id BIGINT UNSIGNED NULL,
    org_unit_id BIGINT UNSIGNED NOT NULL,
    prior_award_amount DECIMAL(12,2) NOT NULL,
    renewal_year_number INT UNSIGNED NOT NULL,
    proposed_current_amount DECIMAL(12,2) NULL,
    eligibility_assessment_id BIGINT UNSIGNED NULL,
    status ENUM('pending_amount','pending_review','hold','confirmed','declined') NOT NULL DEFAULT 'pending_amount',
    reviewed_by_user_id BIGINT UNSIGNED NULL,
    reviewed_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_renewal_cycle FOREIGN KEY (cycle_id) REFERENCES academic_cycles(id),
    CONSTRAINT fk_renewal_scholarship FOREIGN KEY (scholarship_id) REFERENCES scholarships(id),
    CONSTRAINT fk_renewal_cycle_sch FOREIGN KEY (cycle_scholarship_id) REFERENCES cycle_scholarships(id),
    CONSTRAINT fk_renewal_student FOREIGN KEY (student_id) REFERENCES students(id),
    CONSTRAINT fk_renewal_org FOREIGN KEY (org_unit_id) REFERENCES org_units(id),
    CONSTRAINT fk_renewal_elig FOREIGN KEY (eligibility_assessment_id) REFERENCES eligibility_assessments(id),
    CONSTRAINT fk_renewal_reviewer FOREIGN KEY (reviewed_by_user_id) REFERENCES users(id),
    UNIQUE KEY uq_renewal_cycle_sch_student (cycle_id, scholarship_id, student_id),
    KEY idx_renewal_status (cycle_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

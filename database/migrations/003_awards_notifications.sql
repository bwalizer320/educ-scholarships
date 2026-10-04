CREATE TABLE enrollment_imports (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    cycle_id BIGINT UNSIGNED NOT NULL,
    academic_term_id BIGINT UNSIGNED NOT NULL,
    filename VARCHAR(255) NOT NULL,
    status ENUM('uploaded','validated','needs_mapping','processing','completed','failed') NOT NULL DEFAULT 'uploaded',
    is_complete_snapshot TINYINT(1) NOT NULL DEFAULT 1,
    row_count INT UNSIGNED NOT NULL DEFAULT 0,
    student_count INT UNSIGNED NOT NULL DEFAULT 0,
    imported_by_user_id BIGINT UNSIGNED NOT NULL,
    completed_at DATETIME NULL,
    error_summary TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_enroll_import_cycle FOREIGN KEY (cycle_id) REFERENCES academic_cycles(id),
    CONSTRAINT fk_enroll_import_term FOREIGN KEY (academic_term_id) REFERENCES academic_terms(id),
    CONSTRAINT fk_enroll_import_user FOREIGN KEY (imported_by_user_id) REFERENCES users(id),
    KEY idx_enroll_import_term_status (academic_term_id, status, completed_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE enrollment_records (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    enrollment_import_id BIGINT UNSIGNED NOT NULL,
    student_id BIGINT UNSIGNED NOT NULL,
    session_descr VARCHAR(100) NULL,
    university_id VARCHAR(50) NOT NULL,
    standard_full_name VARCHAR(255) NULL,
    email VARCHAR(320) NULL,
    program_college_acad_key VARCHAR(50) NULL,
    stud_classification_descr VARCHAR(100) NULL,
    pgms_program_descr VARCHAR(255) NULL,
    pgms_objective_key VARCHAR(50) NULL,
    is_primary TINYINT(1) NULL,
    enrollment_status VARCHAR(100) NULL,
    citizenship_country_descr VARCHAR(100) NULL,
    cum_ui_graded_gpa DECIMAL(5,3) NULL,
    enrolled_credit_hours DECIMAL(8,3) NULL,
    home_country VARCHAR(100) NULL,
    home_county VARCHAR(100) NULL,
    home_state_descr VARCHAR(100) NULL,
    is_parent_higher_ed_grad TINYINT(1) NULL,
    pgms_sub_program_descr VARCHAR(255) NULL,
    pos_overall_graded_gpa DECIMAL(5,3) NULL,
    pos_ui_graded_gpa DECIMAL(5,3) NULL,
    pos_ui_graded_hours DECIMAL(8,3) NULL,
    residency_county_descr VARCHAR(100) NULL,
    residency_state_descr VARCHAR(100) NULL,
    true_residency_descr VARCHAR(100) NULL,
    veteran_status VARCHAR(100) NULL,
    program_offering_id BIGINT UNSIGNED NULL,
    org_unit_id BIGINT UNSIGNED NULL,
    raw_json JSON NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_enroll_record_import FOREIGN KEY (enrollment_import_id) REFERENCES enrollment_imports(id) ON DELETE CASCADE,
    CONSTRAINT fk_enroll_record_student FOREIGN KEY (student_id) REFERENCES students(id),
    CONSTRAINT fk_enroll_record_offering FOREIGN KEY (program_offering_id) REFERENCES program_offerings(id),
    CONSTRAINT fk_enroll_record_org FOREIGN KEY (org_unit_id) REFERENCES org_units(id),
    KEY idx_enroll_record_student (student_id, enrollment_import_id),
    KEY idx_enroll_record_org (org_unit_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE awards (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    public_id CHAR(36) NOT NULL UNIQUE,
    cycle_id BIGINT UNSIGNED NOT NULL,
    cycle_scholarship_id BIGINT UNSIGNED NOT NULL,
    cycle_allocation_id BIGINT UNSIGNED NULL,
    student_id BIGINT UNSIGNED NOT NULL,
    award_origin ENUM('new','renewal','deans_office_reallocation') NOT NULL,
    recommendation_id BIGINT UNSIGNED NULL,
    renewal_candidate_id BIGINT UNSIGNED NULL,
    total_amount DECIMAL(12,2) NOT NULL,
    award_period ENUM('academic_year','fall','spring') NOT NULL DEFAULT 'academic_year',
    eligibility_status ENUM('eligible','eligible_unmet_preference','potentially_ineligible','insufficient_information') NOT NULL,
    enrollment_status ENUM('not_checked','verified_enrolled','not_enrolled','no_successful_snapshot','warning') NOT NULL DEFAULT 'not_checked',
    status ENUM('draft','pending_verification','approved','ready_to_notify','notified','cancelled') NOT NULL DEFAULT 'draft',
    approved_by_user_id BIGINT UNSIGNED NULL,
    approved_at DATETIME NULL,
    notified_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_award_cycle FOREIGN KEY (cycle_id) REFERENCES academic_cycles(id),
    CONSTRAINT fk_award_cycle_sch FOREIGN KEY (cycle_scholarship_id) REFERENCES cycle_scholarships(id),
    CONSTRAINT fk_award_allocation FOREIGN KEY (cycle_allocation_id) REFERENCES cycle_allocations(id),
    CONSTRAINT fk_award_student FOREIGN KEY (student_id) REFERENCES students(id),
    CONSTRAINT fk_award_recommendation FOREIGN KEY (recommendation_id) REFERENCES recommendations(id),
    CONSTRAINT fk_award_renewal FOREIGN KEY (renewal_candidate_id) REFERENCES renewal_candidates(id),
    CONSTRAINT fk_award_approver FOREIGN KEY (approved_by_user_id) REFERENCES users(id),
    UNIQUE KEY uq_award_cycle_sch_student (cycle_scholarship_id, student_id),
    KEY idx_award_status (cycle_id, status),
    KEY idx_award_student (student_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE renewal_candidates
    ADD CONSTRAINT fk_renewal_prior_award
    FOREIGN KEY (prior_award_id) REFERENCES awards(id);

CREATE TABLE award_distributions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    award_id BIGINT UNSIGNED NOT NULL,
    academic_term_id BIGINT UNSIGNED NOT NULL,
    amount DECIMAL(12,2) NOT NULL,
    source ENUM('default','student_request','deans_office') NOT NULL DEFAULT 'default',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_distribution_award FOREIGN KEY (award_id) REFERENCES awards(id) ON DELETE CASCADE,
    CONSTRAINT fk_distribution_term FOREIGN KEY (academic_term_id) REFERENCES academic_terms(id),
    UNIQUE KEY uq_distribution_award_term (award_id, academic_term_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE distribution_requests (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    award_id BIGINT UNSIGNED NOT NULL,
    student_id BIGINT UNSIGNED NOT NULL,
    requested_term_id BIGINT UNSIGNED NOT NULL,
    reason ENUM('graduating','student_teaching') NOT NULL,
    status ENUM('pending','approved','declined') NOT NULL DEFAULT 'pending',
    requested_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    reviewed_by_user_id BIGINT UNSIGNED NULL,
    reviewed_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_distribution_request_award FOREIGN KEY (award_id) REFERENCES awards(id),
    CONSTRAINT fk_distribution_request_student FOREIGN KEY (student_id) REFERENCES students(id),
    CONSTRAINT fk_distribution_request_term FOREIGN KEY (requested_term_id) REFERENCES academic_terms(id),
    CONSTRAINT fk_distribution_request_reviewer FOREIGN KEY (reviewed_by_user_id) REFERENCES users(id),
    KEY idx_distribution_request_status (status, requested_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE file_objects (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    public_id CHAR(36) NOT NULL UNIQUE,
    storage_driver VARCHAR(50) NOT NULL,
    storage_key VARCHAR(500) NOT NULL,
    original_filename VARCHAR(255) NOT NULL,
    mime_type VARCHAR(150) NOT NULL,
    size_bytes BIGINT UNSIGNED NOT NULL,
    sha256 CHAR(64) NOT NULL,
    uploaded_by_user_id BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_file_user FOREIGN KEY (uploaded_by_user_id) REFERENCES users(id),
    UNIQUE KEY uq_file_storage (storage_driver, storage_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE letter_templates (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    template_type ENUM('new_award','renewal') NOT NULL,
    name VARCHAR(255) NOT NULL,
    html_template LONGTEXT NOT NULL,
    active TINYINT(1) NOT NULL DEFAULT 1,
    version_number INT UNSIGNED NOT NULL,
    source_file_id BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_letter_source_file FOREIGN KEY (source_file_id) REFERENCES file_objects(id),
    UNIQUE KEY uq_letter_type_version (template_type, version_number)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE email_templates (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    template_type ENUM('award_new','award_renewal','thank_you_reminder') NOT NULL,
    subject_template VARCHAR(500) NOT NULL,
    html_template LONGTEXT NOT NULL,
    active TINYINT(1) NOT NULL DEFAULT 1,
    version_number INT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_email_type_version (template_type, version_number)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE notification_batches (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    cycle_id BIGINT UNSIGNED NOT NULL,
    notification_type VARCHAR(50) NOT NULL,
    created_by_user_id BIGINT UNSIGNED NOT NULL,
    status ENUM('queued','processing','completed','partial_failure','failed') NOT NULL DEFAULT 'queued',
    queued_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    completed_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_notification_batch_cycle FOREIGN KEY (cycle_id) REFERENCES academic_cycles(id),
    CONSTRAINT fk_notification_batch_user FOREIGN KEY (created_by_user_id) REFERENCES users(id),
    KEY idx_notification_batch_status (cycle_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE award_notifications (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    award_id BIGINT UNSIGNED NOT NULL,
    notification_batch_id BIGINT UNSIGNED NULL,
    recipient_email VARCHAR(320) NOT NULL,
    subject_snapshot VARCHAR(500) NOT NULL,
    html_body_snapshot LONGTEXT NOT NULL,
    letter_file_id BIGINT UNSIGNED NOT NULL,
    portal_url_snapshot VARCHAR(1000) NOT NULL,
    status ENUM('draft','queued','sending','sent','failed') NOT NULL DEFAULT 'draft',
    provider VARCHAR(100) NULL,
    provider_message_id VARCHAR(255) NULL,
    sent_at DATETIME NULL,
    failed_at DATETIME NULL,
    error_message TEXT NULL,
    idempotency_key CHAR(64) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_award_notification_award FOREIGN KEY (award_id) REFERENCES awards(id),
    CONSTRAINT fk_award_notification_batch FOREIGN KEY (notification_batch_id) REFERENCES notification_batches(id),
    CONSTRAINT fk_award_notification_letter FOREIGN KEY (letter_file_id) REFERENCES file_objects(id),
    UNIQUE KEY uq_notification_idempotency (idempotency_key),
    KEY idx_notification_status (status, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE thank_you_submissions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    award_id BIGINT UNSIGNED NOT NULL,
    student_id BIGINT UNSIGNED NOT NULL,
    submission_method ENUM('rich_text','upload') NOT NULL,
    rich_text_html LONGTEXT NULL,
    uploaded_file_id BIGINT UNSIGNED NULL,
    submitted_at DATETIME NOT NULL,
    deadline_at_snapshot DATETIME NOT NULL,
    is_late TINYINT(1) NOT NULL DEFAULT 0,
    rendered_file_id BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_thanks_award FOREIGN KEY (award_id) REFERENCES awards(id),
    CONSTRAINT fk_thanks_student FOREIGN KEY (student_id) REFERENCES students(id),
    CONSTRAINT fk_thanks_upload FOREIGN KEY (uploaded_file_id) REFERENCES file_objects(id),
    CONSTRAINT fk_thanks_rendered FOREIGN KEY (rendered_file_id) REFERENCES file_objects(id),
    UNIQUE KEY uq_thanks_award (award_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE thank_you_downloads (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    thank_you_submission_id BIGINT UNSIGNED NOT NULL,
    downloaded_by_user_id BIGINT UNSIGNED NOT NULL,
    download_type ENUM('single','zip') NOT NULL,
    downloaded_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_thanks_download_submission FOREIGN KEY (thank_you_submission_id) REFERENCES thank_you_submissions(id),
    CONSTRAINT fk_thanks_download_user FOREIGN KEY (downloaded_by_user_id) REFERENCES users(id),
    KEY idx_thanks_download_user (downloaded_by_user_id, downloaded_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE jobs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    queue VARCHAR(50) NOT NULL DEFAULT 'default',
    job_type VARCHAR(100) NOT NULL,
    payload_json JSON NOT NULL,
    status ENUM('queued','processing','completed','failed') NOT NULL DEFAULT 'queued',
    attempts INT UNSIGNED NOT NULL DEFAULT 0,
    available_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    reserved_at DATETIME NULL,
    completed_at DATETIME NULL,
    last_error TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_jobs_queue (queue, status, available_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

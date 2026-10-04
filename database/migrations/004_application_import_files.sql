ALTER TABLE application_imports
    ADD COLUMN file_id BIGINT UNSIGNED NULL AFTER cycle_id,
    ADD COLUMN column_mapping_json JSON NULL AFTER mapping_profile,
    ADD CONSTRAINT fk_application_import_file
        FOREIGN KEY (file_id) REFERENCES file_objects(id);

CREATE TABLE program_mapping_queue (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    source_system VARCHAR(50) NOT NULL,
    source_program VARCHAR(255) NOT NULL,
    source_objective VARCHAR(100) NULL,
    source_subprogram VARCHAR(255) NULL,
    source_subprogram_id VARCHAR(100) NULL,
    first_seen_import_type ENUM('application','enrollment','legacy') NOT NULL,
    first_seen_import_id BIGINT UNSIGNED NULL,
    occurrence_count INT UNSIGNED NOT NULL DEFAULT 1,
    status ENUM('unresolved','resolved','ignored') NOT NULL DEFAULT 'unresolved',
    resolved_org_unit_id BIGINT UNSIGNED NULL,
    resolved_program_offering_id BIGINT UNSIGNED NULL,
    resolved_by_user_id BIGINT UNSIGNED NULL,
    resolved_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_mapping_queue_org FOREIGN KEY (resolved_org_unit_id) REFERENCES org_units(id),
    CONSTRAINT fk_mapping_queue_offering FOREIGN KEY (resolved_program_offering_id) REFERENCES program_offerings(id),
    CONSTRAINT fk_mapping_queue_user FOREIGN KEY (resolved_by_user_id) REFERENCES users(id),
    UNIQUE KEY uq_mapping_queue_source (
        source_system,
        source_program,
        source_objective,
        source_subprogram,
        source_subprogram_id
    ),
    KEY idx_mapping_queue_status (status, source_system)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

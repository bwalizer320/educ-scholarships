CREATE TABLE thank_you_reminder_notifications (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    award_id BIGINT UNSIGNED NOT NULL,
    notification_batch_id BIGINT UNSIGNED NULL,
    recipient_email VARCHAR(320) NOT NULL,
    subject_snapshot VARCHAR(500) NOT NULL,
    html_body_snapshot LONGTEXT NOT NULL,
    status ENUM('queued','sending','sent','failed') NOT NULL DEFAULT 'queued',
    provider VARCHAR(100) NULL,
    provider_message_id VARCHAR(255) NULL,
    sent_at DATETIME NULL,
    failed_at DATETIME NULL,
    error_message TEXT NULL,
    idempotency_key CHAR(64) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_thankyou_reminder_award FOREIGN KEY (award_id) REFERENCES awards(id),
    CONSTRAINT fk_thankyou_reminder_batch FOREIGN KEY (notification_batch_id) REFERENCES notification_batches(id),
    UNIQUE KEY uq_thankyou_reminder_idempotency (idempotency_key),
    KEY idx_thankyou_reminder_status (status, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE user_uica_access (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    scholarship_id BIGINT UNSIGNED NOT NULL,
    cycle_id BIGINT UNSIGNED NULL,
    can_download TINYINT(1) NOT NULL DEFAULT 1,
    active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_user_uica_user FOREIGN KEY (user_id) REFERENCES users(id),
    CONSTRAINT fk_user_uica_scholarship FOREIGN KEY (scholarship_id) REFERENCES scholarships(id),
    CONSTRAINT fk_user_uica_cycle FOREIGN KEY (cycle_id) REFERENCES academic_cycles(id),
    UNIQUE KEY uq_user_uica_cycle (user_id, scholarship_id, cycle_id),
    KEY idx_user_uica_lookup (user_id, cycle_id, active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE cycle_exports (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    cycle_id BIGINT UNSIGNED NOT NULL,
    export_type ENUM('awards','allocations','recipients','thank_yous','audit') NOT NULL,
    file_id BIGINT UNSIGNED NOT NULL,
    generated_by_user_id BIGINT UNSIGNED NOT NULL,
    generated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_cycle_export_cycle FOREIGN KEY (cycle_id) REFERENCES academic_cycles(id),
    CONSTRAINT fk_cycle_export_file FOREIGN KEY (file_id) REFERENCES file_objects(id),
    CONSTRAINT fk_cycle_export_user FOREIGN KEY (generated_by_user_id) REFERENCES users(id),
    KEY idx_cycle_export_cycle (cycle_id, export_type, generated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE academic_cycles
    ADD COLUMN completed_at DATETIME NULL AFTER is_current,
    ADD COLUMN completed_by_user_id BIGINT UNSIGNED NULL AFTER completed_at,
    ADD CONSTRAINT fk_cycle_completed_by
        FOREIGN KEY (completed_by_user_id) REFERENCES users(id);

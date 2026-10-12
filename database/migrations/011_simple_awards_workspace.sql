-- Keep existing award and recommendation records intact. These fields expose the
-- simpler workflow without replacing the established approval/notification model.
ALTER TABLE cycle_allocations
    ADD COLUMN recruiting_pool_name VARCHAR(255) NULL AFTER org_unit_id;

ALTER TABLE recommendations
    ADD COLUMN is_recruitment TINYINT(1) NOT NULL DEFAULT 0 AFTER student_id;

CREATE TABLE maui_export_batches (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    cycle_id BIGINT UNSIGNED NOT NULL,
    exported_by_user_id BIGINT UNSIGNED NOT NULL,
    award_count INT UNSIGNED NOT NULL,
    distribution_count INT UNSIGNED NOT NULL,
    total_amount DECIMAL(14,2) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_maui_export_cycle FOREIGN KEY (cycle_id) REFERENCES academic_cycles(id),
    CONSTRAINT fk_maui_export_user FOREIGN KEY (exported_by_user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE awards
    ADD COLUMN maui_export_batch_id BIGINT UNSIGNED NULL AFTER notified_at,
    ADD COLUMN maui_exported_at DATETIME NULL AFTER maui_export_batch_id,
    ADD CONSTRAINT fk_awards_maui_batch FOREIGN KEY (maui_export_batch_id) REFERENCES maui_export_batches(id),
    ADD KEY idx_awards_maui_export (cycle_id, maui_export_batch_id);

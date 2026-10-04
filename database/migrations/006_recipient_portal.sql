ALTER TABLE users
    ADD COLUMN student_id BIGINT UNSIGNED NULL AFTER staff_role,
    ADD CONSTRAINT fk_user_student
        FOREIGN KEY (student_id) REFERENCES students(id),
    ADD UNIQUE KEY uq_user_student (student_id);

CREATE TABLE recipient_activation_tokens (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    award_id BIGINT UNSIGNED NULL,
    token_hash CHAR(64) NOT NULL,
    expires_at DATETIME NOT NULL,
    used_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_activation_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_activation_award FOREIGN KEY (award_id) REFERENCES awards(id) ON DELETE CASCADE,
    UNIQUE KEY uq_activation_token_hash (token_hash),
    KEY idx_activation_user (user_id, expires_at, used_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE distribution_requests
    ADD COLUMN student_note TEXT NULL AFTER reason;

ALTER TABLE thank_you_submissions
    ADD COLUMN donor_name_snapshot VARCHAR(255) NULL AFTER student_id,
    ADD COLUMN reviewer_notes TEXT NULL AFTER is_late;

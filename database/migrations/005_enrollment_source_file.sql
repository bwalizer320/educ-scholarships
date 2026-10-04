ALTER TABLE enrollment_imports
    ADD COLUMN file_id BIGINT UNSIGNED NULL AFTER academic_term_id,
    ADD CONSTRAINT fk_enrollment_import_file
        FOREIGN KEY (file_id) REFERENCES file_objects(id);

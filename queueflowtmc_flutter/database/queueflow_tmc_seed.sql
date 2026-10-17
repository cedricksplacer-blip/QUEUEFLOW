-- ============================================================
-- QUEUEFLOW TMC - campus load
--
-- Generated from lib/services/demo_campus.dart by
-- tool/load_into_mysql.dart, so it matches what the app ships.
--
-- Differences from the app that are deliberate:
--   * passwords are bcrypt, not the plaintext the app compares
--   * accounts.student_id is renamed college_id, because staff have
--     one too and "student_id" on a staff row is misleading
--   * tickets.student_id is renamed account_id, because there it is
--     an integer user id, not a college ID
--
-- Load with:  mysql -u root -p < database/queueflow_tmc_seed.sql
-- ============================================================

CREATE DATABASE IF NOT EXISTS `queueflow_tmc`
  DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `queueflow_tmc`;

SET FOREIGN_KEY_CHECKS = 0;
DROP TABLE IF EXISTS `tickets`, `batches`, `announcements`;
DROP TABLE IF EXISTS `password_reset_codes`, `office_sequences`;
DROP TABLE IF EXISTS `accounts`, `services`, `stations`, `courses`;
DROP TABLE IF EXISTS `offices`, `settings`;
SET FOREIGN_KEY_CHECKS = 1;

CREATE TABLE `settings` (
  `id` TINYINT UNSIGNED NOT NULL DEFAULT 1,
  `site_name` VARCHAR(120) NOT NULL,
  `college_name` VARCHAR(160) NOT NULL,
  `system_description` TEXT NULL,
  `contact_email` VARCHAR(160) NULL,
  `contact_number` VARCHAR(40) NULL,
  `default_waiting_time` SMALLINT UNSIGNED NOT NULL DEFAULT 5,
  `default_opening_time` TIME NULL,
  `default_closing_time` TIME NULL,
  `operational_start_time` TIME NULL,
  `operational_end_time` TIME NULL,
  `max_active_tickets_per_student` TINYINT UNSIGNED NOT NULL DEFAULT 1,
  `max_slots_per_station` SMALLINT UNSIGNED NOT NULL DEFAULT 100,
  `minimum_students_per_station` SMALLINT UNSIGNED NOT NULL DEFAULT 40,
  `target_students_per_station` SMALLINT UNSIGNED NOT NULL DEFAULT 60,
  `supported_courses` JSON NULL,
  `supported_year_levels` JSON NULL,
  `maintenance_mode` TINYINT(1) NOT NULL DEFAULT 0,
  `maintenance_message` TEXT NULL,
  `ticket_created_notification` TINYINT(1) NOT NULL DEFAULT 1,
  `queue_almost_ready_notification` TINYINT(1) NOT NULL DEFAULT 1,
  `now_serving_notification` TINYINT(1) NOT NULL DEFAULT 1,
  `ticket_completed_notification` TINYINT(1) NOT NULL DEFAULT 1,
  `ticket_cancelled_notification` TINYINT(1) NOT NULL DEFAULT 1,
  `announcement_notification` TINYINT(1) NOT NULL DEFAULT 1,
  `show_system_logo` TINYINT(1) NOT NULL DEFAULT 1,
  `primary_color` CHAR(7) NULL,
  `secondary_color` CHAR(7) NULL,
  `accent_color` CHAR(7) NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB;

CREATE TABLE `offices` (
  `id` INT UNSIGNED NOT NULL,
  `name` VARCHAR(120) NOT NULL,
  `code` VARCHAR(20) NOT NULL,
  `description` TEXT NULL,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `opening_time` TIME NULL,
  `closing_time` TIME NULL,
  `max_slots` SMALLINT UNSIGNED NOT NULL DEFAULT 100,
  `course` VARCHAR(80) NULL,
  `course_code` VARCHAR(20) NULL,
  `window_open` TINYINT(1) NOT NULL DEFAULT 0,
  `accepting` TINYINT(1) NOT NULL DEFAULT 0,
  `open_batch` INT UNSIGNED NULL,
  `active_batch` INT UNSIGNED NULL,
  `waiting_count` INT UNSIGNED NOT NULL DEFAULT 0,
  `serving_count` INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `offices_code_unique` (`code`)
) ENGINE=InnoDB;

CREATE TABLE `courses` (
  `id` INT UNSIGNED NOT NULL,
  `code` VARCHAR(20) NOT NULL,
  `name` VARCHAR(160) NOT NULL,
  `office_id` INT UNSIGNED NULL,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  UNIQUE KEY `courses_code_unique` (`code`),
  KEY `courses_office_id_foreign` (`office_id`),
  CONSTRAINT `courses_office_id_foreign` FOREIGN KEY (`office_id`)
    REFERENCES `offices` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE `stations` (
  `id` INT UNSIGNED NOT NULL,
  `office_id` INT UNSIGNED NOT NULL,
  `name` VARCHAR(80) NOT NULL,
  `location` VARCHAR(160) NULL,
  `course_codes` JSON NULL,
  `min_daily_students` SMALLINT UNSIGNED NOT NULL DEFAULT 40,
  `target_daily_students` SMALLINT UNSIGNED NOT NULL DEFAULT 60,
  `max_daily_slots` SMALLINT UNSIGNED NOT NULL DEFAULT 100,
  `opening_time` TIME NULL,
  `closing_time` TIME NULL,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `accepts_course` TINYINT(1) NULL,
  PRIMARY KEY (`id`),
  KEY `stations_office_id_foreign` (`office_id`),
  CONSTRAINT `stations_office_id_foreign` FOREIGN KEY (`office_id`)
    REFERENCES `offices` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE `services` (
  `id` INT UNSIGNED NOT NULL,
  `office_id` INT UNSIGNED NOT NULL,
  `station_id` INT UNSIGNED NULL,
  `name` VARCHAR(120) NOT NULL,
  `description` TEXT NULL,
  `requires_course` TINYINT(1) NOT NULL DEFAULT 0,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  KEY `services_office_id_foreign` (`office_id`),
  KEY `services_station_id_foreign` (`station_id`),
  CONSTRAINT `services_office_id_foreign` FOREIGN KEY (`office_id`)
    REFERENCES `offices` (`id`) ON DELETE CASCADE,
  CONSTRAINT `services_station_id_foreign` FOREIGN KEY (`station_id`)
    REFERENCES `stations` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE `accounts` (
  `id` INT UNSIGNED NOT NULL,
  `name` VARCHAR(160) NOT NULL,
  `email` VARCHAR(160) NOT NULL,
  `role` ENUM('admin','staff','student') NOT NULL,
  -- bcrypt, not the plaintext the app compares against
  `password` VARCHAR(255) NOT NULL,
  -- the college ID; the app calls this student_id
  `college_id` VARCHAR(20) NOT NULL,
  `course` VARCHAR(80) NULL,
  `block` VARCHAR(20) NULL,
  `year_level` VARCHAR(20) NULL,
  `phone` VARCHAR(30) NULL,
  -- asset key or absolute URL of the account's photograph. NULL means the
  -- account has never uploaded one, and the app falls back to the bundled
  -- portrait rather than to a blank avatar.
  `avatar` VARCHAR(255) NULL,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` DATETIME NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `accounts_email_unique` (`email`),
  UNIQUE KEY `accounts_college_id_unique` (`college_id`),
  KEY `accounts_role_index` (`role`)
) ENGINE=InnoDB;

CREATE TABLE `batches` (
  `id` INT UNSIGNED NOT NULL,
  `office_id` INT UNSIGNED NOT NULL,
  `batch_no` SMALLINT UNSIGNED NOT NULL,
  -- 'full' is a real app state: set when the batch hits max_slots
  `status` ENUM('open','full','closed','completed') NOT NULL DEFAULT 'open',
  `max_slots` SMALLINT UNSIGNED NOT NULL DEFAULT 100,
  `submitted_count` INT UNSIGNED NOT NULL DEFAULT 0,
  `served_count` INT UNSIGNED NOT NULL DEFAULT 0,
  `opened_at` DATETIME NULL,
  `closed_at` DATETIME NULL,
  `completed_at` DATETIME NULL,
  PRIMARY KEY (`id`),
  KEY `batches_office_id_foreign` (`office_id`),
  CONSTRAINT `batches_office_id_foreign` FOREIGN KEY (`office_id`)
    REFERENCES `offices` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE `tickets` (
  `id` INT UNSIGNED NOT NULL,
  `queue_number` VARCHAR(20) NOT NULL,
  `status` ENUM('waiting','serving','served','cancelled') NOT NULL,
  -- the app only defines regular and pwd (AppConstants lines 31-32),
  -- so no speculative members are added here
  `priority` ENUM('regular','pwd') NOT NULL DEFAULT 'regular',
  `purpose` VARCHAR(160) NULL,
  `year_level` VARCHAR(20) NULL,
  `has_evidence` TINYINT(1) NOT NULL DEFAULT 0,
  -- the owning user; the app calls this student_id but it is a user id
  `account_id` INT UNSIGNED NOT NULL,
  `office_id` INT UNSIGNED NOT NULL,
  `service_id` INT UNSIGNED NULL,
  `station_id` INT UNSIGNED NULL,
  `course_id` INT UNSIGNED NULL,
  `batch_id` INT UNSIGNED NULL,
  `served_by` INT UNSIGNED NULL,
  `called_at` DATETIME NULL,
  `served_at` DATETIME NULL,
  `created_at` DATETIME NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `tickets_queue_number_unique` (`queue_number`),
  KEY `tickets_account_id_foreign` (`account_id`),
  KEY `tickets_office_id_foreign` (`office_id`),
  KEY `tickets_status_index` (`status`),
  CONSTRAINT `tickets_account_id_foreign` FOREIGN KEY (`account_id`)
    REFERENCES `accounts` (`id`) ON DELETE CASCADE,
  CONSTRAINT `tickets_office_id_foreign` FOREIGN KEY (`office_id`)
    REFERENCES `offices` (`id`) ON DELETE CASCADE,
  CONSTRAINT `tickets_served_by_foreign` FOREIGN KEY (`served_by`)
    REFERENCES `accounts` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE `announcements` (
  `id` INT UNSIGNED NOT NULL,
  `title` VARCHAR(200) NOT NULL,
  `body` TEXT NOT NULL,
  -- Announcement.status defaults to 'published' and nothing writes
  -- another value yet, so this is the only member the app produces
  `status` ENUM('published') NOT NULL DEFAULT 'published',
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `author` VARCHAR(160) NULL,
  `office` VARCHAR(120) NULL,
  `created_at` DATETIME NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB;

CREATE TABLE `office_sequences` (
  `code` VARCHAR(20) NOT NULL,
  `last_number` INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (`code`)
) ENGINE=InnoDB;

CREATE TABLE `password_reset_codes` (
  `college_id` VARCHAR(20) NOT NULL,
  `code` CHAR(6) NOT NULL,
  `issued_at` DATETIME NOT NULL,
  `expires_at` DATETIME NOT NULL,
  PRIMARY KEY (`college_id`),
  KEY `password_reset_codes_expires_at_index` (`expires_at`)
) ENGINE=InnoDB;

-- ---------------- data ----------------

INSERT INTO `settings` (`id`, `site_name`, `college_name`, `system_description`, `contact_email`, `contact_number`, `default_waiting_time`, `default_opening_time`, `default_closing_time`, `operational_start_time`, `operational_end_time`, `max_active_tickets_per_station`, `max_slots_per_station`, `minimum_students_per_station`, `target_students_per_station`, `supported_courses`, `supported_year_levels`, `maintenance_mode`, `maintenance_message`, `ticket_created_notification`, `queue_almost_ready_notification`, `now_serving_notification`, `ticket_completed_notification`, `ticket_cancelled_notification`, `announcement_notification`, `show_system_logo`, `primary_color`, `secondary_color`, `accent_color`) VALUES
  (1, 'Q-FlowTMC', 'Trinidad Municipal College', 'Campus queue desk for student clearances, payments and records.', 'registrar@tmc.edu.ph', '(038) 555-0142', 5, '07:00', '17:00', '07:00', '17:00', 1, 100, 40, 60, '["BSIT","BSED","BSN","BSCS","BSA"]', '["1st Year","2nd Year","3rd Year","4th Year"]', 0, '', 1, 1, 1, 1, 1, 1, 1, '#4F46E5', '#7C3AED', '#22D3EE');

INSERT INTO `offices` (`id`, `name`, `code`, `description`, `is_active`, `opening_time`, `closing_time`, `max_slots`, `course`, `course_code`, `window_open`, `accepting`, `open_batch`, `active_batch`, `waiting_count`, `serving_count`) VALUES
  (1, 'Cashier', 'CASH', 'Payments, accountabilities and student fees.', 1, '07:00', '17:00', 100, NULL, NULL, 0, 0, NULL, NULL, 0, 0),
  (2, 'Registrar', 'REG', 'Enrolment, transcripts and student records.', 1, '07:00', '17:00', 80, NULL, NULL, 0, 0, NULL, NULL, 0, 0),
  (3, 'Library', 'LIB', 'Borrowing, returns and study room reservations.', 1, '07:00', '17:00', 80, NULL, NULL, 0, 0, NULL, NULL, 0, 0),
  (4, 'Guidance Office', 'GDL', 'Career counselling and student wellbeing. Closed for renovation.', 0, '08:00', '12:00', 40, NULL, NULL, 0, 0, NULL, NULL, 0, 0),
  (5, 'Registrar - BSIT', 'REG-BSIT', 'BSIT enrolment, curriculum assessment and major subject tracking.', 1, '07:00', '17:00', 50, '{id: 1, code: BSIT, name: BS Information Technology, office_id: 5, is_active: true}', 'BSIT', 0, 0, NULL, NULL, 0, 0),
  (6, 'Registrar - BSED', 'REG-BSED', 'BSED enrolment, curriculum assessment and major subject tracking.', 1, '07:00', '17:00', 20, '{id: 2, code: BSED, name: BS Education, office_id: 6, is_active: true}', 'BSED', 0, 0, NULL, NULL, 0, 0);

INSERT INTO `courses` (`id`, `code`, `name`, `office_id`, `is_active`) VALUES
  (1, 'BSIT', 'BS Information Technology', 5, 1),
  (2, 'BSED', 'BS Education', 6, 1),
  (3, 'BSN', 'BS Nursing', NULL, 1),
  (4, 'BSCS', 'BS Computer Science', NULL, 1),
  (5, 'BSA', 'BS Accountancy', NULL, 1);

INSERT INTO `stations` (`id`, `office_id`, `name`, `location`, `course_codes`, `min_daily_students`, `target_daily_students`, `max_daily_slots`, `opening_time`, `closing_time`, `is_active`, `accepts_course`) VALUES
  (1, 1, 'Window A', 'Ground Floor, Administration Building', NULL, 40, 60, 100, NULL, NULL, 1, NULL),
  (2, 2, 'Registrar Window 1', 'Main Building, 2nd Floor', NULL, 40, 60, 100, NULL, NULL, 1, NULL),
  (3, 3, 'Lending Desk', 'Library, Ground Floor', NULL, 40, 60, 100, NULL, NULL, 1, NULL),
  (4, 4, 'Guidance Desk', 'Guidance Building, Ground Floor', NULL, 40, 60, 100, NULL, NULL, 1, NULL),
  (5, 5, 'BSIT Registrar Window', 'Main Building, 3rd Floor', NULL, 40, 60, 100, NULL, NULL, 1, NULL),
  (6, 6, 'BSED Registrar Window', 'Main Building, 3rd Floor', NULL, 40, 60, 100, NULL, NULL, 1, NULL);

INSERT INTO `services` (`id`, `office_id`, `station_id`, `name`, `description`, `requires_course`, `is_active`) VALUES
  (1, 1, 1, 'Tuition payment', 'Final term and instalment fees.', 0, 1),
  (2, 1, 1, 'Accountability clearance', NULL, 0, 1),
  (3, 1, 1, 'ID replacement', 'For a lost or damaged school ID.', 0, 1),
  (4, 2, 2, 'Certificate of Registration', 'Your COR for this term.', 0, 1),
  (5, 2, 2, 'Transcript request', NULL, 0, 1),
  (6, 2, 2, 'Certificate of Good Moral', NULL, 0, 1),
  (7, 3, 3, 'Borrow a book', NULL, 0, 1),
  (8, 3, 3, 'Return a book', NULL, 0, 1),
  (9, 3, 3, 'Reserve a study room', NULL, 0, 1),
  (10, 4, 4, 'Career guidance', NULL, 0, 1),
  (11, 5, 5, 'BSIT enrolment', 'For retakers and late enrollees.', 0, 1),
  (12, 5, 5, 'BSIT curriculum assessment', NULL, 0, 1),
  (13, 6, 6, 'BSED enrolment', 'For retakers and late enrollees.', 0, 1),
  (14, 6, 6, 'BSED curriculum assessment', NULL, 0, 1);

INSERT INTO `accounts` (`id`, `name`, `email`, `role`, `password`, `college_id`, `course`, `block`, `year_level`, `phone`, `is_active`, `created_at`) VALUES
  (1, 'Maria Santos', 'admin@tmc.edu.ph', 'admin', '$2y$10$QIDSf14N1G5.kDmgqYsaFuJbIzKooVrhpvJ7sLmtPUeu8P4Biejh6', '2025-00001', NULL, NULL, NULL, '09171234567', 1, '2026-03-04 22:21:07'),
  (2, 'Ramon Cruz', 'staff@tmc.edu.ph', 'staff', '$2y$10$NnTkeSiC1BgRKDjnSCtRbeER4UZ9O8ATyBKbiXko24VF0fQxoqXs.', '2025-00002', NULL, NULL, NULL, '09181234567', 1, '2026-03-04 22:21:07'),
  (3, 'Liza Mendoza', 'liza@tmc.edu.ph', 'staff', '$2y$10$NnTkeSiC1BgRKDjnSCtRbeER4UZ9O8ATyBKbiXko24VF0fQxoqXs.', '2025-00003', NULL, NULL, NULL, '09191234567', 0, '2026-03-04 22:21:07'),
  (4, 'Juan Dela Cruz', 'juan.dela@tmc.edu.ph', 'student', '$2y$10$jrpY6fMdXUTOYgBqYAkZxO8CxSQ6udMqk/eyFqnOmLEH7mS5VRmFy', '2025-00145', 'BSIT', '1', '2nd Year', NULL, 1, '2026-05-08 22:21:07'),
  (5, 'Ana Reyes', 'ana.reyes@tmc.edu.ph', 'student', '$2y$10$jrpY6fMdXUTOYgBqYAkZxO8CxSQ6udMqk/eyFqnOmLEH7mS5VRmFy', '2025-00146', 'BSED', '2', '2nd Year', NULL, 1, '2026-05-07 22:21:07'),
  (6, 'Carlo Mendoza', 'carlo.mendoza@tmc.edu.ph', 'student', '$2y$10$jrpY6fMdXUTOYgBqYAkZxO8CxSQ6udMqk/eyFqnOmLEH7mS5VRmFy', '2025-00147', 'BSN', '1', '3rd Year', NULL, 1, '2026-05-06 22:21:07'),
  (7, 'Debbie Lim', 'debbie.lim@tmc.edu.ph', 'student', '$2y$10$jrpY6fMdXUTOYgBqYAkZxO8CxSQ6udMqk/eyFqnOmLEH7mS5VRmFy', '2025-00148', 'BSCS', '3', '1st Year', NULL, 1, '2026-05-05 22:21:07'),
  (8, 'Ethan Abella', 'ethan.abella@tmc.edu.ph', 'student', '$2y$10$jrpY6fMdXUTOYgBqYAkZxO8CxSQ6udMqk/eyFqnOmLEH7mS5VRmFy', '2025-00149', 'BSA', '2', '3rd Year', NULL, 1, '2026-05-04 22:21:07'),
  (9, 'Bea Fernandez', 'bea.fernandez@tmc.edu.ph', 'student', '$2y$10$jrpY6fMdXUTOYgBqYAkZxO8CxSQ6udMqk/eyFqnOmLEH7mS5VRmFy', '2025-00150', 'BSIT', '2', '2nd Year', NULL, 1, '2026-05-03 22:21:07'),
  (10, 'Frank.Ordinalez', 'frankordinalez@tmc.edu.ph', 'student', '$2y$10$jrpY6fMdXUTOYgBqYAkZxO8CxSQ6udMqk/eyFqnOmLEH7mS5VRmFy', '2025-00151', 'BSN', '3', '2nd Year', NULL, 1, '2026-05-02 22:21:07'),
  (11, 'Grace Yu', 'grace.yu@tmc.edu.ph', 'student', '$2y$10$jrpY6fMdXUTOYgBqYAkZxO8CxSQ6udMqk/eyFqnOmLEH7mS5VRmFy', '2025-00152', 'BSED', '1', '3rd Year', NULL, 1, '2026-05-01 22:21:07'),
  (12, 'Hector Villegas', 'hector.villegas@tmc.edu.ph', 'student', '$2y$10$jrpY6fMdXUTOYgBqYAkZxO8CxSQ6udMqk/eyFqnOmLEH7mS5VRmFy', '2025-00153', 'BSCS', '2', '2nd Year', NULL, 1, '2026-04-30 22:21:07'),
  (13, 'Ivy plantilla', 'ivy.plantilla@tmc.edu.ph', 'student', '$2y$10$jrpY6fMdXUTOYgBqYAkZxO8CxSQ6udMqk/eyFqnOmLEH7mS5VRmFy', '2025-00154', 'BSIT', '3', '3rd Year', NULL, 1, '2026-04-29 22:21:07'),
  (14, 'Joel Barongan', 'joel.barongan@tmc.edu.ph', 'student', '$2y$10$jrpY6fMdXUTOYgBqYAkZxO8CxSQ6udMqk/eyFqnOmLEH7mS5VRmFy', '2025-00155', 'BSA', '1', '2nd Year', NULL, 1, '2026-04-28 22:21:07'),
  (15, 'Kara Dizon', 'kara.dizon@tmc.edu.ph', 'student', '$2y$10$jrpY6fMdXUTOYgBqYAkZxO8CxSQ6udMqk/eyFqnOmLEH7mS5VRmFy', '2025-00156', 'BSN', '2', '1st Year', NULL, 1, '2026-04-27 22:21:07'),
  (16, 'Loren Almeda', 'loren.almeda@tmc.edu.ph', 'student', '$2y$10$jrpY6fMdXUTOYgBqYAkZxO8CxSQ6udMqk/eyFqnOmLEH7mS5VRmFy', '2025-00157', 'BSED', '3', '2nd Year', NULL, 1, '2026-04-26 22:21:07'),
  (17, 'Mona Toledo', 'mona.toledo@tmc.edu.ph', 'student', '$2y$10$jrpY6fMdXUTOYgBqYAkZxO8CxSQ6udMqk/eyFqnOmLEH7mS5VRmFy', '2025-00158', 'BSCS', '1', '3rd Year', NULL, 1, '2026-04-25 22:21:07'),
  (18, 'Nico Borja', 'nico.borja@tmc.edu.ph', 'student', '$2y$10$jrpY6fMdXUTOYgBqYAkZxO8CxSQ6udMqk/eyFqnOmLEH7mS5VRmFy', '2025-00159', 'BSIT', '2', '1st Year', NULL, 1, '2026-04-24 22:21:07'),
  (19, 'Oscar Uy', 'oscar.uy@tmc.edu.ph', 'student', '$2y$10$jrpY6fMdXUTOYgBqYAkZxO8CxSQ6udMqk/eyFqnOmLEH7mS5VRmFy', '2025-00160', 'BSA', '3', '4th Year', NULL, 1, '2026-04-23 22:21:07');

INSERT INTO `batches` (`id`, `office_id`, `batch_no`, `status`, `max_slots`, `submitted_count`, `served_count`, `opened_at`, `closed_at`, `completed_at`) VALUES
  (1, 1, 1, 'open', 100, 0, 0, '2026-09-30 07:00:00', NULL, NULL),
  (2, 2, 1, 'open', 80, 0, 0, '2026-09-30 07:00:00', NULL, NULL),
  (3, 3, 1, 'open', 80, 0, 0, '2026-09-30 07:00:00', NULL, NULL),
  (4, 5, 1, 'open', 50, 0, 0, '2026-09-30 07:00:00', NULL, NULL),
  (5, 6, 1, 'full', 20, 20, 20, '2026-09-30 07:00:00', NULL, NULL);

INSERT INTO `tickets` (`id`, `queue_number`, `status`, `priority`, `purpose`, `year_level`, `has_evidence`, `account_id`, `office_id`, `service_id`, `station_id`, `course_id`, `batch_id`, `served_by`, `called_at`, `served_at`, `created_at`) VALUES
  (1, 'CASH-001', 'served', 'regular', NULL, NULL, 0, 4, 1, 1, 1, 3, 1, 2, '2026-09-30 19:56:07', '2026-09-30 20:00:07', '2026-09-30 19:51:07'),
  (2, 'CASH-002', 'served', 'regular', NULL, NULL, 0, 5, 1, 1, 1, 3, 1, 2, '2026-09-30 20:04:07', '2026-09-30 20:08:07', '2026-09-30 19:59:07'),
  (3, 'CASH-003', 'served', 'regular', NULL, NULL, 0, 6, 1, 1, 1, 3, 1, 2, '2026-09-30 20:12:07', '2026-09-30 20:16:07', '2026-09-30 20:07:07'),
  (4, 'CASH-004', 'served', 'regular', NULL, NULL, 0, 7, 1, 1, 1, 3, 1, 2, '2026-09-30 20:20:07', '2026-09-30 20:24:07', '2026-09-30 20:15:07'),
  (5, 'CASH-005', 'served', 'regular', NULL, NULL, 0, 8, 1, 1, 1, 3, 1, 2, '2026-09-30 20:28:07', '2026-09-30 20:32:07', '2026-09-30 20:23:07'),
  (6, 'CASH-006', 'served', 'regular', NULL, NULL, 0, 9, 1, 1, 1, 3, 1, 2, '2026-09-30 20:36:07', '2026-09-30 20:40:07', '2026-09-30 20:31:07'),
  (7, 'CASH-007', 'served', 'regular', NULL, NULL, 0, 10, 1, 1, 1, 3, 1, 2, '2026-09-30 20:44:07', '2026-09-30 20:48:07', '2026-09-30 20:39:07'),
  (8, 'CASH-008', 'served', 'regular', NULL, NULL, 0, 11, 1, 1, 1, 3, 1, 2, '2026-09-30 20:52:07', '2026-09-30 20:56:07', '2026-09-30 20:47:07'),
  (9, 'CASH-009', 'served', 'regular', NULL, NULL, 0, 12, 1, 1, 1, 3, 1, 2, '2026-09-30 21:00:07', '2026-09-30 21:04:07', '2026-09-30 20:55:07'),
  (10, 'CASH-010', 'served', 'regular', NULL, NULL, 0, 13, 1, 1, 1, 3, 1, 2, '2026-09-30 21:08:07', '2026-09-30 21:12:07', '2026-09-30 21:03:07'),
  (11, 'CASH-011', 'served', 'regular', NULL, NULL, 0, 14, 1, 1, 1, 3, 1, 2, '2026-09-30 21:16:07', '2026-09-30 21:20:07', '2026-09-30 21:11:07'),
  (12, 'CASH-012', 'serving', 'regular', NULL, NULL, 0, 15, 1, 1, 1, 3, 1, 2, '2026-09-30 22:18:07', NULL, '2026-09-30 21:55:07'),
  (13, 'CASH-013', 'waiting', 'pwd', NULL, NULL, 1, 16, 1, 1, 1, 3, 1, NULL, NULL, NULL, '2026-09-30 21:57:07'),
  (14, 'CASH-014', 'waiting', 'regular', NULL, NULL, 0, 17, 1, 1, 1, 3, 1, NULL, NULL, NULL, '2026-09-30 22:03:07'),
  (15, 'CASH-015', 'waiting', 'regular', NULL, NULL, 0, 18, 1, 1, 1, 3, 1, NULL, NULL, NULL, '2026-09-30 22:09:07'),
  (16, 'REG-001', 'served', 'regular', NULL, NULL, 0, 19, 2, 4, 2, 2, 2, 2, '2026-09-30 19:56:07', '2026-09-30 20:00:07', '2026-09-30 19:51:07'),
  (17, 'REG-002', 'served', 'regular', NULL, NULL, 0, 4, 2, 4, 2, 2, 2, 2, '2026-09-30 20:04:07', '2026-09-30 20:08:07', '2026-09-30 19:59:07'),
  (18, 'REG-003', 'served', 'regular', NULL, NULL, 0, 5, 2, 4, 2, 2, 2, 2, '2026-09-30 20:12:07', '2026-09-30 20:16:07', '2026-09-30 20:07:07'),
  (19, 'REG-004', 'served', 'regular', NULL, NULL, 0, 6, 2, 4, 2, 2, 2, 2, '2026-09-30 20:20:07', '2026-09-30 20:24:07', '2026-09-30 20:15:07'),
  (20, 'REG-005', 'served', 'regular', NULL, NULL, 0, 7, 2, 4, 2, 2, 2, 2, '2026-09-30 20:28:07', '2026-09-30 20:32:07', '2026-09-30 20:23:07'),
  (21, 'REG-006', 'served', 'regular', NULL, NULL, 0, 8, 2, 4, 2, 2, 2, 2, '2026-09-30 20:36:07', '2026-09-30 20:40:07', '2026-09-30 20:31:07'),
  (22, 'REG-007', 'served', 'regular', NULL, NULL, 0, 9, 2, 4, 2, 2, 2, 2, '2026-09-30 20:44:07', '2026-09-30 20:48:07', '2026-09-30 20:39:07'),
  (23, 'REG-008', 'serving', 'regular', NULL, NULL, 0, 10, 2, 4, 2, 2, 2, 2, '2026-09-30 22:18:07', NULL, '2026-09-30 21:55:07'),
  (24, 'REG-009', 'waiting', 'pwd', NULL, NULL, 1, 11, 2, 4, 2, 2, 2, NULL, NULL, NULL, '2026-09-30 21:57:07'),
  (25, 'REG-010', 'waiting', 'regular', NULL, NULL, 0, 12, 2, 4, 2, 2, 2, NULL, NULL, NULL, '2026-09-30 22:03:07'),
  (26, 'LIB-001', 'served', 'regular', NULL, NULL, 0, 13, 3, 7, 3, 1, 3, 2, '2026-09-30 19:56:07', '2026-09-30 20:00:07', '2026-09-30 19:51:07'),
  (27, 'LIB-002', 'served', 'regular', NULL, NULL, 0, 14, 3, 7, 3, 1, 3, 2, '2026-09-30 20:04:07', '2026-09-30 20:08:07', '2026-09-30 19:59:07'),
  (28, 'LIB-003', 'served', 'regular', NULL, NULL, 0, 15, 3, 7, 3, 1, 3, 2, '2026-09-30 20:12:07', '2026-09-30 20:16:07', '2026-09-30 20:07:07'),
  (29, 'LIB-004', 'served', 'regular', NULL, NULL, 0, 16, 3, 7, 3, 1, 3, 2, '2026-09-30 20:20:07', '2026-09-30 20:24:07', '2026-09-30 20:15:07'),
  (30, 'LIB-005', 'served', 'regular', NULL, NULL, 0, 17, 3, 7, 3, 1, 3, 2, '2026-09-30 20:28:07', '2026-09-30 20:32:07', '2026-09-30 20:23:07'),
  (31, 'LIB-006', 'served', 'regular', NULL, NULL, 0, 18, 3, 7, 3, 1, 3, 2, '2026-09-30 20:36:07', '2026-09-30 20:40:07', '2026-09-30 20:31:07'),
  (32, 'LIB-007', 'serving', 'regular', NULL, NULL, 0, 19, 3, 7, 3, 1, 3, 2, '2026-09-30 22:18:07', NULL, '2026-09-30 21:55:07'),
  (33, 'LIB-008', 'waiting', 'pwd', NULL, NULL, 1, 4, 3, 7, 3, 1, 3, NULL, NULL, NULL, '2026-09-30 21:57:07'),
  (34, 'LIB-009', 'waiting', 'regular', NULL, NULL, 0, 5, 3, 7, 3, 1, 3, NULL, NULL, NULL, '2026-09-30 22:03:07'),
  (35, 'LIB-010', 'waiting', 'regular', NULL, NULL, 0, 6, 3, 7, 3, 1, 3, NULL, NULL, NULL, '2026-09-30 22:09:07'),
  (36, 'REG-BSIT-001', 'served', 'regular', NULL, NULL, 0, 7, 5, 11, 5, 1, 4, 2, '2026-09-30 19:56:07', '2026-09-30 20:00:07', '2026-09-30 19:51:07'),
  (37, 'REG-BSIT-002', 'served', 'regular', NULL, NULL, 0, 8, 5, 11, 5, 1, 4, 2, '2026-09-30 20:04:07', '2026-09-30 20:08:07', '2026-09-30 19:59:07'),
  (38, 'REG-BSIT-003', 'served', 'regular', NULL, NULL, 0, 9, 5, 11, 5, 1, 4, 2, '2026-09-30 20:12:07', '2026-09-30 20:16:07', '2026-09-30 20:07:07'),
  (39, 'REG-BSIT-004', 'served', 'regular', NULL, NULL, 0, 10, 5, 11, 5, 1, 4, 2, '2026-09-30 20:20:07', '2026-09-30 20:24:07', '2026-09-30 20:15:07'),
  (40, 'REG-BSIT-005', 'serving', 'regular', NULL, NULL, 0, 11, 5, 11, 5, 1, 4, 2, '2026-09-30 22:18:07', NULL, '2026-09-30 21:55:07'),
  (41, 'REG-BSIT-006', 'waiting', 'pwd', NULL, NULL, 1, 12, 5, 11, 5, 1, 4, NULL, NULL, NULL, '2026-09-30 21:57:07'),
  (42, 'REG-BSIT-007', 'waiting', 'regular', NULL, NULL, 0, 13, 5, 11, 5, 1, 4, NULL, NULL, NULL, '2026-09-30 22:03:07'),
  (43, 'REG-BSED-001', 'served', 'regular', NULL, NULL, 0, 14, 6, 13, 6, 2, 5, 2, '2026-09-30 19:56:07', '2026-09-30 20:00:07', '2026-09-30 19:51:07'),
  (44, 'REG-BSED-002', 'served', 'regular', NULL, NULL, 0, 15, 6, 13, 6, 2, 5, 2, '2026-09-30 20:04:07', '2026-09-30 20:08:07', '2026-09-30 19:59:07'),
  (45, 'REG-BSED-003', 'served', 'regular', NULL, NULL, 0, 16, 6, 13, 6, 2, 5, 2, '2026-09-30 20:12:07', '2026-09-30 20:16:07', '2026-09-30 20:07:07'),
  (46, 'REG-BSED-004', 'served', 'regular', NULL, NULL, 0, 17, 6, 13, 6, 2, 5, 2, '2026-09-30 20:20:07', '2026-09-30 20:24:07', '2026-09-30 20:15:07'),
  (47, 'REG-BSED-005', 'served', 'regular', NULL, NULL, 0, 18, 6, 13, 6, 2, 5, 2, '2026-09-30 20:28:07', '2026-09-30 20:32:07', '2026-09-30 20:23:07'),
  (48, 'REG-BSED-006', 'served', 'regular', NULL, NULL, 0, 19, 6, 13, 6, 2, 5, 2, '2026-09-30 20:36:07', '2026-09-30 20:40:07', '2026-09-30 20:31:07'),
  (49, 'REG-BSED-007', 'served', 'regular', NULL, NULL, 0, 4, 6, 13, 6, 2, 5, 2, '2026-09-30 20:44:07', '2026-09-30 20:48:07', '2026-09-30 20:39:07'),
  (50, 'REG-BSED-008', 'served', 'regular', NULL, NULL, 0, 5, 6, 13, 6, 2, 5, 2, '2026-09-30 20:52:07', '2026-09-30 20:56:07', '2026-09-30 20:47:07'),
  (51, 'REG-BSED-009', 'served', 'regular', NULL, NULL, 0, 6, 6, 13, 6, 2, 5, 2, '2026-09-30 21:00:07', '2026-09-30 21:04:07', '2026-09-30 20:55:07'),
  (52, 'REG-BSED-010', 'served', 'regular', NULL, NULL, 0, 7, 6, 13, 6, 2, 5, 2, '2026-09-30 21:08:07', '2026-09-30 21:12:07', '2026-09-30 21:03:07'),
  (53, 'REG-BSED-011', 'served', 'regular', NULL, NULL, 0, 8, 6, 13, 6, 2, 5, 2, '2026-09-30 21:16:07', '2026-09-30 21:20:07', '2026-09-30 21:11:07'),
  (54, 'REG-BSED-012', 'served', 'regular', NULL, NULL, 0, 9, 6, 13, 6, 2, 5, 2, '2026-09-30 21:24:07', '2026-09-30 21:28:07', '2026-09-30 21:19:07'),
  (55, 'REG-BSED-013', 'served', 'regular', NULL, NULL, 0, 10, 6, 13, 6, 2, 5, 2, '2026-09-30 21:32:07', '2026-09-30 21:36:07', '2026-09-30 21:27:07'),
  (56, 'REG-BSED-014', 'served', 'regular', NULL, NULL, 0, 11, 6, 13, 6, 2, 5, 2, '2026-09-30 21:40:07', '2026-09-30 21:44:07', '2026-09-30 21:35:07'),
  (57, 'REG-BSED-015', 'served', 'regular', NULL, NULL, 0, 12, 6, 13, 6, 2, 5, 2, '2026-09-30 21:48:07', '2026-09-30 21:52:07', '2026-09-30 21:43:07'),
  (58, 'REG-BSED-016', 'served', 'regular', NULL, NULL, 0, 13, 6, 13, 6, 2, 5, 2, '2026-09-30 21:56:07', '2026-09-30 22:00:07', '2026-09-30 21:51:07'),
  (59, 'REG-BSED-017', 'served', 'regular', NULL, NULL, 0, 14, 6, 13, 6, 2, 5, 2, '2026-09-30 22:04:07', '2026-09-30 22:08:07', '2026-09-30 21:59:07'),
  (60, 'REG-BSED-018', 'served', 'regular', NULL, NULL, 0, 15, 6, 13, 6, 2, 5, 2, '2026-09-30 22:12:07', '2026-09-30 22:16:07', '2026-09-30 22:07:07'),
  (61, 'REG-BSED-019', 'served', 'regular', NULL, NULL, 0, 16, 6, 13, 6, 2, 5, 2, '2026-09-30 22:20:07', '2026-09-30 22:24:07', '2026-09-30 22:15:07'),
  (62, 'REG-BSED-020', 'served', 'regular', NULL, NULL, 0, 17, 6, 13, 6, 2, 5, 2, '2026-09-30 22:28:07', '2026-09-30 22:32:07', '2026-09-30 22:23:07');

INSERT INTO `announcements` (`id`, `title`, `body`, `status`, `is_active`, `author`, `office`, `created_at`) VALUES
  (1, 'Guidance Office closed for renovation', 'The Guidance Office will be closed until further notice. Career counselling appointments are being handled at the Student Services desk in the Main Building.', 'published', 1, 'Maria Santos', 'Guidance Office', '2026-09-30 19:21:07'),
  (2, 'Cashier closes at 5:00 PM this week', 'To clear the end-of-term backlog the Cashier will stop issuing numbers at 5:00 PM from Monday. Anyone already in line will be called.', 'published', 1, 'Ramon Cruz', 'Cashier', '2026-09-29 22:21:07'),
  (3, 'Library study rooms now queueable', 'Study room reservations can be taken through the Library queue alongside book borrowing. Bring your library card.', 'published', 1, 'Maria Santos', 'Library', '2026-09-28 22:21:07');

INSERT INTO `office_sequences` (`code`, `last_number`) VALUES
  ('CASH', 15),
  ('REG', 10),
  ('LIB', 10),
  ('REG-BSIT', 7),
  ('REG-BSED', 20);

-- reset codes are intentionally empty: they are short lived and the
-- app mints them on the device, so none are worth carrying over.

-- ---------------- summary ----------------
SELECT 'courses' AS t, COUNT(*) AS n FROM courses
UNION ALL SELECT 'offices', COUNT(*) FROM offices
UNION ALL SELECT 'services', COUNT(*) FROM services
UNION ALL SELECT 'stations', COUNT(*) FROM stations
UNION ALL SELECT 'accounts', COUNT(*) FROM accounts
UNION ALL SELECT 'batches', COUNT(*) FROM batches
UNION ALL SELECT 'tickets', COUNT(*) FROM tickets
UNION ALL SELECT 'announcements', COUNT(*) FROM announcements;


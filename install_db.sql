CREATE DATABASE IF NOT EXISTS `dbbarangaymanagement` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `dbbarangaymanagement`;

CREATE TABLE IF NOT EXISTS `users` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `first_name` VARCHAR(100) NOT NULL,
  `last_name` VARCHAR(100) NOT NULL,
  `username` VARCHAR(100) NOT NULL UNIQUE,
  `email` VARCHAR(150) NOT NULL UNIQUE,
  `password_hash` VARCHAR(255) NOT NULL,
  `role` ENUM('admin','staff','health_worker','security_force','resident') NOT NULL DEFAULT 'admin',
  `status` ENUM('Active','Inactive') NOT NULL DEFAULT 'Active',
  `term` VARCHAR(100) DEFAULT NULL,
  `position` VARCHAR(100) DEFAULT NULL,
  `address` VARCHAR(255) DEFAULT NULL,
  `contact_number` VARCHAR(50) DEFAULT NULL,
  `photo_path` VARCHAR(255) DEFAULT NULL,
  `photo_data` MEDIUMBLOB DEFAULT NULL,
  `photo_mime` VARCHAR(50) DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `php_sessions` (
  `id` VARCHAR(128) NOT NULL,
  `data` MEDIUMBLOB NOT NULL,
  `last_activity` INT UNSIGNED NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_php_sessions_last_activity` (`last_activity`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `barangay_settings` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `barangay_name` VARCHAR(255) DEFAULT NULL,
  `barangay_captain` VARCHAR(255) DEFAULT NULL,
  `municipality` VARCHAR(150) DEFAULT NULL,
  `province` VARCHAR(150) DEFAULT NULL,
  `zip_code` VARCHAR(20) DEFAULT NULL,
  `contact_number` VARCHAR(50) DEFAULT NULL,
  `email` VARCHAR(150) DEFAULT NULL,
  `office_hours` VARCHAR(100) DEFAULT NULL,
  `address` TEXT DEFAULT NULL,
  `logo_path` VARCHAR(255) DEFAULT NULL,
  `enable_appointments` TINYINT(1) NOT NULL DEFAULT 0,
  `enable_complaints` TINYINT(1) NOT NULL DEFAULT 0,
  `enable_notifications` TINYINT(1) NOT NULL DEFAULT 0,
  `allow_registration` TINYINT(1) NOT NULL DEFAULT 0,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `activity_logs` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` INT UNSIGNED DEFAULT NULL,
  `action` VARCHAR(50) NOT NULL,
  `entity` VARCHAR(100) NOT NULL,
  `details` TEXT DEFAULT NULL,
  `related_id` INT UNSIGNED DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_activity_logs_user_id` (`user_id`),
  KEY `idx_activity_logs_entity` (`entity`),
  KEY `idx_activity_logs_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `user_security` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` INT UNSIGNED NOT NULL,
  `failed_login_attempts` INT UNSIGNED NOT NULL DEFAULT 0,
  `locked_until` DATETIME NULL DEFAULT NULL,
  `last_login_at` DATETIME NULL DEFAULT NULL,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `idx_user_security_user_id` (`user_id`),
  KEY `idx_user_security_locked_until` (`locked_until`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `login_attempts` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `ip_hash` CHAR(64) NOT NULL,
  `username_hash` CHAR(64) NOT NULL,
  `successful` TINYINT(1) NOT NULL DEFAULT 0,
  `attempted_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_login_attempts_ip_time` (`ip_hash`, `attempted_at`),
  KEY `idx_login_attempts_username_time` (`username_hash`, `attempted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `residents` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `resident_number` VARCHAR(50) NOT NULL UNIQUE,
  `first_name` VARCHAR(100) NOT NULL,
  `middle_name` VARCHAR(100) DEFAULT NULL,
  `last_name` VARCHAR(100) NOT NULL,
  `suffix` VARCHAR(50) DEFAULT NULL,
  `sex` VARCHAR(20) DEFAULT NULL,
  `birth_date` DATE DEFAULT NULL,
  `age` INT DEFAULT NULL,
  `birth_place` VARCHAR(150) DEFAULT NULL,
  `civil_status` VARCHAR(50) DEFAULT NULL,
  `nationality` VARCHAR(100) DEFAULT NULL,
  `religion` VARCHAR(100) DEFAULT NULL,
  `blood_type` VARCHAR(10) DEFAULT NULL,
  `mobile_number` VARCHAR(50) DEFAULT NULL,
  `telephone_number` VARCHAR(50) DEFAULT NULL,
  `email` VARCHAR(150) DEFAULT NULL,
  `emergency_contact` VARCHAR(150) DEFAULT NULL,
  `emergency_number` VARCHAR(50) DEFAULT NULL,
  `relationship` VARCHAR(100) DEFAULT NULL,
  `house_number` VARCHAR(50) DEFAULT NULL,
  `street` VARCHAR(150) DEFAULT NULL,
  `purok` VARCHAR(150) DEFAULT NULL,
  `barangay` VARCHAR(150) DEFAULT NULL,
  `municipality` VARCHAR(150) DEFAULT NULL,
  `province` VARCHAR(150) DEFAULT NULL,
  `zip_code` VARCHAR(20) DEFAULT NULL,
  `length_residency` VARCHAR(100) DEFAULT NULL,
  `residency_type` VARCHAR(50) DEFAULT NULL,
  `household_id` VARCHAR(50) DEFAULT NULL,
  `household_head` VARCHAR(150) DEFAULT NULL,
  `relationship_head` VARCHAR(100) DEFAULT NULL,
  `household_members` INT DEFAULT NULL,
  `household_type` VARCHAR(50) DEFAULT NULL,
  `income_bracket` VARCHAR(100) DEFAULT NULL,
  `voter_status` VARCHAR(50) DEFAULT NULL,
  `precinct_number` VARCHAR(50) DEFAULT NULL,
  `philhealth_number` VARCHAR(100) DEFAULT NULL,
  `sss_number` VARCHAR(100) DEFAULT NULL,
  `gsis_number` VARCHAR(100) DEFAULT NULL,
  `tin` VARCHAR(100) DEFAULT NULL,
  `national_id` VARCHAR(100) DEFAULT NULL,
  `employment_status` VARCHAR(100) DEFAULT NULL,
  `occupation` VARCHAR(150) DEFAULT NULL,
  `company` VARCHAR(150) DEFAULT NULL,
  `workplace` VARCHAR(255) DEFAULT NULL,
  `monthly_income` VARCHAR(100) DEFAULT NULL,
  `education` VARCHAR(100) DEFAULT NULL,
  `school_name` VARCHAR(150) DEFAULT NULL,
  `student_status` VARCHAR(50) DEFAULT NULL,
  `pwd_status` VARCHAR(50) DEFAULT NULL,
  `disability_type` VARCHAR(150) DEFAULT NULL,
  `senior_citizen` VARCHAR(10) DEFAULT NULL,
  `blood_type_health` VARCHAR(10) DEFAULT NULL,
  `medical_condition` TEXT DEFAULT NULL,
  `categories` TEXT DEFAULT NULL,
  `resident_status` VARCHAR(50) DEFAULT NULL,
  `date_registered` DATE DEFAULT NULL,
  `date_moved_in` DATE DEFAULT NULL,
  `date_moved_out` DATE DEFAULT NULL,
  `move_reason` TEXT DEFAULT NULL,
  `account_status` VARCHAR(50) DEFAULT NULL,
  `created_date` DATETIME DEFAULT NULL,
  `last_updated` DATETIME DEFAULT NULL,
  `photo_path` VARCHAR(255) DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `households` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `household_number` VARCHAR(50) NOT NULL UNIQUE,
  `household_head` VARCHAR(150) DEFAULT NULL,
  `members_count` INT DEFAULT NULL,
  `zone` VARCHAR(100) DEFAULT NULL,
  `status` VARCHAR(50) DEFAULT NULL,
  `household_type` VARCHAR(50) DEFAULT NULL,
  `income_bracket` VARCHAR(100) DEFAULT NULL,
  `address` TEXT DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `certificates` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `resident_id` INT UNSIGNED DEFAULT NULL,
  `certificate_type` VARCHAR(150) DEFAULT NULL,
  `status` VARCHAR(50) DEFAULT NULL,
  `request_date` DATE DEFAULT NULL,
  `approved_date` DATE DEFAULT NULL,
  `purpose` TEXT DEFAULT NULL,
  `remarks` TEXT DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_resident_id` (`resident_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `complaints` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `tracking_number` VARCHAR(100) NOT NULL UNIQUE,
  `resident_id` INT UNSIGNED DEFAULT NULL,
  `resident_name` VARCHAR(200) DEFAULT NULL,
  `category` VARCHAR(150) DEFAULT NULL,
  `status` VARCHAR(50) DEFAULT NULL,
  `date_filed` DATE DEFAULT NULL,
  `description` TEXT DEFAULT NULL,
  `response` TEXT DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_complaint_resident_id` (`resident_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `appointments` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `resident_id` INT UNSIGNED DEFAULT NULL,
  `resident_name` VARCHAR(200) DEFAULT NULL,
  `purpose` VARCHAR(255) DEFAULT NULL,
  `appointment_date` DATE DEFAULT NULL,
  `appointment_time` TIME DEFAULT NULL,
  `status` VARCHAR(50) DEFAULT NULL,
  `notes` TEXT DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_appointment_resident_id` (`resident_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ================================================
-- User Database Setup Script (dbs15253086)
-- ================================================
-- This database handles: User authentication, profiles,
-- logins, passwords, and alumni information
-- ================================================

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;
START TRANSACTION;
SET time_zone = "+00:00";

-- ================================================
-- TABLE: users
-- NOTE: For existing databases, run:
--   ALTER TABLE users ADD COLUMN has_seen_onboarding BOOLEAN NOT NULL DEFAULT 0 COMMENT 'Whether user has seen the onboarding modal';
--   ALTER TABLE users ADD COLUMN entra_photo_path VARCHAR(500) DEFAULT NULL COMMENT 'Cached profile photo path fetched from Microsoft Entra ID';
--   ALTER TABLE users ADD COLUMN avatar_path VARCHAR(500) DEFAULT NULL COMMENT 'Active profile photo path; NULL = default avatar, custom_* = manually uploaded, uploads/profile_photos/entra_* = synced from Entra ID';
--   ALTER TABLE users ADD COLUMN is_onboarded BOOLEAN NOT NULL DEFAULT 0 COMMENT 'Whether user has completed the mandatory first-login onboarding workflow';
--   ALTER TABLE users ADD COLUMN session_token VARCHAR(255) DEFAULT NULL COMMENT 'Random token for single-session enforcement; regenerated on every login';
--   ALTER TABLE users ADD COLUMN last_profile_update DATETIME DEFAULT NULL COMMENT 'Timestamp when the user last saved their profile; used to trigger yearly reminder emails';
--   ALTER TABLE users ADD COLUMN profile_reminder_sent_at DATETIME DEFAULT NULL COMMENT 'Timestamp when the yearly profile reminder email was sent; reset to NULL when user updates profile';
--   ALTER TABLE users ADD COLUMN failed_login_attempts INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Number of consecutive failed password login attempts';
--   ALTER TABLE users ADD COLUMN locked_until DATETIME DEFAULT NULL COMMENT 'Timestamp until which password login is locked after too many failed attempts';
--   ALTER TABLE users ADD COLUMN is_locked_permanently BOOLEAN NOT NULL DEFAULT 0 COMMENT 'Whether the account is permanently locked (requires admin unlock)';
--   ALTER TABLE users ADD COLUMN tfa_failed_attempts INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Number of consecutive failed 2FA verification attempts';
--   ALTER TABLE users ADD COLUMN tfa_locked_until DATETIME DEFAULT NULL COMMENT 'Timestamp until which 2FA verification is locked after too many failed attempts';
--   ALTER TABLE users ADD COLUMN use_custom_avatar TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1 = user uploaded own photo (Entra photo sync disabled), 0 = Entra photo is used';
-- ================================================
CREATE TABLE IF NOT EXISTS `users` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `username` VARCHAR(50) UNIQUE DEFAULT NULL,
  `email` VARCHAR(255) NOT NULL UNIQUE,
  `password` VARCHAR(255) NOT NULL,
  `first_name` VARCHAR(100),
  `last_name` VARCHAR(100),
  `birthday` DATE DEFAULT NULL,
  `show_birthday` BOOLEAN NOT NULL DEFAULT 0 COMMENT 'Whether to display birthday publicly on profile',
  `about_me` TEXT DEFAULT NULL COMMENT 'User biography or about me section',
  `gender` VARCHAR(50) DEFAULT NULL COMMENT 'User gender',
  `role` ENUM('anwaerter', 'alumni', 'mitglied', 'ehrenmitglied', 'ressortleiter', 'alumni_vorstand', 'alumni_finanz', 'vorstand_finanzen', 'vorstand_intern', 'vorstand_extern', 'admin', 'manager') NOT NULL DEFAULT 'mitglied',
  `azure_roles` JSON DEFAULT NULL COMMENT 'Original Microsoft Entra ID roles from Azure AD authentication',
  `azure_oid` VARCHAR(255) DEFAULT NULL COMMENT 'Azure Object Identifier (OID) from Microsoft Entra ID authentication',
  `user_type` ENUM('member', 'guest') DEFAULT NULL COMMENT 'Microsoft Entra ID user type: member (internal) or guest (external/invited)',
  `job_title` VARCHAR(255) DEFAULT NULL COMMENT 'Job title from Microsoft Entra ID',
  `company` VARCHAR(255) DEFAULT NULL COMMENT 'Company name from Microsoft Entra ID',
  `entra_roles` TEXT DEFAULT NULL COMMENT 'JSON array of Microsoft Entra role names for display',
  `entra_photo_path` VARCHAR(500) DEFAULT NULL COMMENT 'Cached profile photo path fetched from Microsoft Entra ID',
  `avatar_path` VARCHAR(500) DEFAULT NULL COMMENT 'Active profile photo path; NULL = default avatar, custom_* = manually uploaded, uploads/profile_photos/entra_* = synced from Entra ID',
  `use_custom_avatar` TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1 = user uploaded own photo (Entra photo sync disabled), 0 = Entra photo is used',
  `profile_complete` BOOLEAN NOT NULL DEFAULT 0 COMMENT 'Flag to track if user has completed initial profile setup',
  `has_seen_onboarding` BOOLEAN NOT NULL DEFAULT 0 COMMENT 'Whether user has seen the onboarding welcome modal',
  `is_onboarded` BOOLEAN NOT NULL DEFAULT 0 COMMENT 'Whether user has completed the mandatory first-login onboarding workflow',
  `tfa_secret` VARCHAR(255) DEFAULT NULL COMMENT 'Two-factor authentication secret key',
  `tfa_enabled` BOOLEAN NOT NULL DEFAULT 0 COMMENT 'Whether two-factor authentication is enabled',
  `tfa_failed_attempts` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Number of consecutive failed 2FA verification attempts',
  `tfa_locked_until` DATETIME DEFAULT NULL COMMENT 'Timestamp until which 2FA verification is locked after too many failed attempts',
  `failed_login_attempts` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Number of consecutive failed password login attempts',
  `locked_until` DATETIME DEFAULT NULL COMMENT 'Timestamp until which password login is locked after too many failed attempts',
  `is_locked_permanently` BOOLEAN NOT NULL DEFAULT 0 COMMENT 'Whether the account is permanently locked (requires admin unlock)',
  `is_alumni_validated` BOOLEAN NOT NULL DEFAULT 1 COMMENT 'Whether alumni user is validated by board (0=needs approval, 1=approved)',
  `notify_new_projects` BOOLEAN NOT NULL DEFAULT 0 COMMENT 'Receive email notifications for new projects',
  `notify_new_events` BOOLEAN NOT NULL DEFAULT 0 COMMENT 'Receive email notifications for new events',
  `blog_newsletter` BOOLEAN NOT NULL DEFAULT 0 COMMENT 'Receive email notifications for new blog posts',
  `theme_preference` ENUM('auto', 'light', 'dark') DEFAULT 'auto' COMMENT 'User interface theme preference',
  `current_session_id` VARCHAR(255) DEFAULT NULL COMMENT 'Active session ID for single-session enforcement; NULL if no active session',
  `session_token` VARCHAR(255) DEFAULT NULL COMMENT 'Random token for single-session enforcement; regenerated on every login',
  `deleted_at` DATETIME DEFAULT NULL COMMENT 'Timestamp when the user was soft deleted (NULL = active)',
  `last_profile_update` DATETIME DEFAULT NULL COMMENT 'Timestamp when the user last saved their profile; used to trigger yearly reminder emails',
  `profile_reminder_sent_at` DATETIME DEFAULT NULL COMMENT 'Timestamp when the yearly profile reminder email was sent; reset to NULL when user updates profile',
  `last_reminder_sent_at` DATETIME DEFAULT NULL COMMENT 'Timestamp when the last profile reminder email was sent to the user',
  `privacy_hide_email` BOOLEAN NOT NULL DEFAULT 0 COMMENT 'Hide email from non-privileged users',
  `privacy_hide_phone` BOOLEAN NOT NULL DEFAULT 0 COMMENT 'Hide phone from non-privileged users',
  `privacy_hide_career` BOOLEAN NOT NULL DEFAULT 0 COMMENT 'Hide career/work data from non-privileged users',
  `easyverein_id` VARCHAR(100) DEFAULT NULL COMMENT 'EasyVerein member/contact ID (NULL if user has no EasyVerein account)',
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `last_login` TIMESTAMP NULL DEFAULT NULL,
  INDEX `idx_username` (`username`),
  INDEX `idx_email` (`email`),
  INDEX `idx_role` (`role`),
  INDEX `idx_azure_oid` (`azure_oid`),
  INDEX `idx_deleted_at` (`deleted_at`),
  INDEX `idx_last_reminder_sent_at` (`last_reminder_sent_at`),
  INDEX `idx_easyverein_id` (`easyverein_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='User authentication and profile information';

-- ================================================
-- TABLE: user_sessions
-- ================================================
CREATE TABLE IF NOT EXISTS `user_sessions` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT UNSIGNED NOT NULL,
  `session_token` VARCHAR(255) NOT NULL UNIQUE,
  `ip_address` VARCHAR(45),
  `user_agent` TEXT,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `expires_at` TIMESTAMP NOT NULL,
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
  INDEX `idx_user_id` (`user_id`),
  INDEX `idx_session_token` (`session_token`),
  INDEX `idx_expires_at` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='User session management';

-- ================================================
-- TABLE: login_attempts
-- ================================================
CREATE TABLE IF NOT EXISTS `login_attempts` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `username` VARCHAR(50) NOT NULL,
  `ip_address` VARCHAR(45) NOT NULL,
  `success` BOOLEAN NOT NULL DEFAULT 0,
  `attempted_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_username` (`username`),
  INDEX `idx_ip_address` (`ip_address`),
  INDEX `idx_attempted_at` (`attempted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Track login attempts for security';

-- ================================================
-- TABLE: password_resets
-- ================================================
CREATE TABLE IF NOT EXISTS `password_resets` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT UNSIGNED NOT NULL,
  `reset_token` VARCHAR(255) NOT NULL UNIQUE,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `expires_at` TIMESTAMP NOT NULL,
  `used` BOOLEAN NOT NULL DEFAULT 0,
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
  INDEX `idx_user_id` (`user_id`),
  INDEX `idx_reset_token` (`reset_token`),
  INDEX `idx_expires_at` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Password reset token management';

-- ================================================
-- TABLE: email_change_requests
-- ================================================
CREATE TABLE IF NOT EXISTS `email_change_requests` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT UNSIGNED NOT NULL,
  `new_email` VARCHAR(255) NOT NULL,
  `token` VARCHAR(255) NOT NULL UNIQUE,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `expires_at` TIMESTAMP NOT NULL,
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
  INDEX `idx_user_id` (`user_id`),
  INDEX `idx_token` (`token`),
  INDEX `idx_expires_at` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Email change request token management';

-- ================================================
-- TABLE: notifications
-- ================================================
CREATE TABLE IF NOT EXISTS `notifications` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT UNSIGNED NOT NULL,
  `title` VARCHAR(255) NOT NULL,
  `message` TEXT NOT NULL,
  `link` VARCHAR(500) DEFAULT NULL,
  `is_read` BOOLEAN NOT NULL DEFAULT 0,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
  INDEX `idx_user_id` (`user_id`),
  INDEX `idx_is_read` (`is_read`),
  INDEX `idx_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='On-site user notifications';

COMMIT;

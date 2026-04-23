-- ================================================
-- Invoice Database Setup Script (dbs15251284)
-- ================================================
-- This database handles: Invoice management and billing
-- ================================================

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;
START TRANSACTION;
SET time_zone = "+00:00";

-- ================================================
-- TABLE: invoices
-- ================================================
CREATE TABLE IF NOT EXISTS `invoices` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT UNSIGNED NOT NULL COMMENT 'User who submitted the invoice',
  `description` TEXT NOT NULL COMMENT 'Invoice description',
  `amount` DECIMAL(10, 2) NOT NULL COMMENT 'Invoice amount in EUR',
  `file_path` VARCHAR(500) NOT NULL COMMENT 'Path to uploaded invoice file',
  `status` ENUM('pending', 'approved', 'rejected', 'paid', 'failed', 'refunded') NOT NULL DEFAULT 'pending' COMMENT 'Invoice processing status',
  `rejection_reason` TEXT DEFAULT NULL COMMENT 'Reason for rejection if status is rejected',
  `paid_at` TIMESTAMP NULL DEFAULT NULL COMMENT 'Timestamp when invoice was marked as paid',
  `paid_by_user_id` INT UNSIGNED DEFAULT NULL COMMENT 'User ID who marked invoice as paid',
  `paypal_transaction_id` VARCHAR(64) DEFAULT NULL COMMENT 'PayPal capture/transaction ID for webhook lookup',
  `payment_purpose` VARCHAR(255) DEFAULT NULL COMMENT 'Payment purpose / Verwendungszweck for Vorkasse',
  `easyverein_document_id` VARCHAR(64) DEFAULT NULL COMMENT 'EasyVerein billing document ID for bank-transfer invoices',
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX `idx_user_id` (`user_id`),
  INDEX `idx_status` (`status`),
  INDEX `idx_created_at` (`created_at`),
  INDEX `idx_paid_by_user_id` (`paid_by_user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Invoice management table for expense reimbursement tracking';

COMMIT;

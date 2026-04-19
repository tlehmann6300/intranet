-- ================================================
-- Content Database Setup Script (dbs15161271)
-- ================================================
-- This database handles: Events, Projects, Blog Posts, 
-- Inventory, Polls, Alumni Profiles, Event Documentation
-- ================================================

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;
START TRANSACTION;
SET time_zone = "+00:00";

-- ================================================
-- TABLE: events
-- NOTE: For existing databases, run:
--   ALTER TABLE events MODIFY COLUMN status ENUM('draft','planned','open','closed','running','past') DEFAULT 'planned';
--   (The created_by column already exists in the schema; no action needed for it.)
--   ALTER TABLE events ADD COLUMN IF NOT EXISTS is_internal_project BOOLEAN NOT NULL DEFAULT 0 COMMENT 'Whether the event is an internal project';
--   ALTER TABLE events ADD COLUMN IF NOT EXISTS requires_application BOOLEAN NOT NULL DEFAULT 0 COMMENT 'Whether applicants must submit an application to join an internal project';
-- ================================================
CREATE TABLE IF NOT EXISTS `events` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `title` VARCHAR(255) NOT NULL,
  `description` TEXT,
  `location` VARCHAR(255) DEFAULT NULL COMMENT 'Event location',
  `maps_link` TEXT DEFAULT NULL COMMENT 'Google Maps or location link',
  `start_time` DATETIME DEFAULT NULL COMMENT 'Event start date and time',
  `end_time` DATETIME DEFAULT NULL COMMENT 'Event end date and time',
  `registration_start` DATETIME DEFAULT NULL COMMENT 'When registration opens',
  `registration_end` DATETIME DEFAULT NULL COMMENT 'When registration closes',
  `status` ENUM('draft', 'planned', 'open', 'closed', 'running', 'past') DEFAULT 'planned' COMMENT 'Event status (draft = unpublished, planned/open/closed/running/past = time-based)',
  `is_external` BOOLEAN NOT NULL DEFAULT 0 COMMENT 'Whether the event is an external event',
  `external_link` TEXT DEFAULT NULL COMMENT 'Link to external event page',
  `registration_link` VARCHAR(500) DEFAULT NULL COMMENT 'External registration link',
  `needs_helpers` BOOLEAN NOT NULL DEFAULT 0 COMMENT 'Flag indicating if the event needs helpers',
  `is_internal_project` BOOLEAN NOT NULL DEFAULT 0 COMMENT 'Whether the event is an internal project',
  `requires_application` BOOLEAN NOT NULL DEFAULT 0 COMMENT 'Whether applicants must submit an application to join an internal project',
  `feedback_contact_user_id` INT UNSIGNED DEFAULT NULL COMMENT 'User ID of the alumni who volunteered as feedback contact',
  `image_path` VARCHAR(500) DEFAULT NULL COMMENT 'Path to event image',
  `contact_person` VARCHAR(255) NULL COMMENT 'Contact person for the event',
  `locked_by` INT UNSIGNED DEFAULT NULL COMMENT 'User ID who locked the event for editing',
  `locked_at` TIMESTAMP NULL DEFAULT NULL COMMENT 'When the event was locked',
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `created_by` INT UNSIGNED DEFAULT NULL COMMENT 'User ID who created the event',
  INDEX `idx_created_by` (`created_by`),
  INDEX `idx_status` (`status`),
  INDEX `idx_needs_helpers` (`needs_helpers`),
  INDEX `idx_locked_by` (`locked_by`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ================================================
-- TABLE: event_documentation
-- ================================================
CREATE TABLE IF NOT EXISTS `event_documentation` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `event_id` INT UNSIGNED NOT NULL,
  `calculations` TEXT DEFAULT NULL COMMENT 'Calculations and notes for the event (legacy)',
  `calculation_link` VARCHAR(2048) DEFAULT NULL COMMENT 'URL link to external calculation document',
  `total_costs` DECIMAL(10,2) DEFAULT NULL COMMENT 'Total costs for the event in EUR',
  `sales_data` JSON DEFAULT NULL COMMENT 'JSON array of sales data entries',
  `sellers_data` JSON DEFAULT NULL COMMENT 'JSON array of seller entries with name, items, quantity, and revenue',
  `created_by` INT UNSIGNED DEFAULT NULL COMMENT 'User who created the documentation',
  `updated_by` INT UNSIGNED DEFAULT NULL COMMENT 'User who last updated the documentation',
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (`event_id`) REFERENCES `events`(`id`) ON DELETE CASCADE,
  INDEX `idx_event_id` (`event_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ================================================
-- TABLE: event_financial_stats
-- ================================================
CREATE TABLE IF NOT EXISTS `event_financial_stats` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `event_id` INT UNSIGNED NOT NULL,
  `category` ENUM('Verkauf', 'Kalkulation', 'Spenden') NOT NULL COMMENT 'Category: Sales, Calculation, or Donations',
  `item_name` VARCHAR(255) NOT NULL COMMENT 'Item name, e.g., Brezeln, Äpfel, Grillstand',
  `quantity` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Quantity sold or calculated',
  `revenue` DECIMAL(10, 2) DEFAULT NULL COMMENT 'Revenue in EUR (optional for calculations)',
  `donations_total` DECIMAL(10, 2) DEFAULT NULL COMMENT 'Total donations in EUR (used for Spenden category)',
  `record_year` YEAR NOT NULL COMMENT 'Year of record for historical comparison',
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `created_by` INT UNSIGNED NOT NULL COMMENT 'User who created the record',
  FOREIGN KEY (`event_id`) REFERENCES `events`(`id`) ON DELETE CASCADE,
  INDEX `idx_event_id` (`event_id`),
  INDEX `idx_category` (`category`),
  INDEX `idx_record_year` (`record_year`),
  INDEX `idx_event_year` (`event_id`, `record_year`),
  INDEX `idx_created_by` (`created_by`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Financial statistics for events - tracks sales and calculations with yearly comparison';

-- ================================================
-- TABLE: alumni_profiles
-- NOTE: For existing databases, run:
--   ALTER TABLE alumni_profiles ADD COLUMN bio TEXT DEFAULT NULL AFTER last_reminder_sent_at;
--   ALTER TABLE alumni_profiles ADD COLUMN skills TEXT DEFAULT NULL COMMENT 'Comma-separated list of skills/competencies' AFTER bio;
--   ALTER TABLE alumni_profiles ADD COLUMN cv_path VARCHAR(500) DEFAULT NULL COMMENT 'Path to uploaded CV/resume PDF' AFTER skills;
-- Migration: add bio, skills and cv_path columns if not already present
-- Note: ALTER TABLE ... ADD COLUMN IF NOT EXISTS is not supported in MySQL.
-- For existing databases that are missing these columns, run manually:
--   ALTER TABLE alumni_profiles ADD COLUMN bio TEXT DEFAULT NULL AFTER last_reminder_sent_at;
--   ALTER TABLE alumni_profiles ADD COLUMN skills TEXT DEFAULT NULL COMMENT 'Comma-separated list of skills/competencies' AFTER bio;
--   ALTER TABLE alumni_profiles ADD COLUMN cv_path VARCHAR(500) DEFAULT NULL COMMENT 'Path to uploaded CV/resume PDF' AFTER skills;
-- ================================================
CREATE TABLE IF NOT EXISTS `alumni_profiles` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT UNSIGNED NOT NULL,
  `first_name` VARCHAR(100) DEFAULT NULL,
  `last_name` VARCHAR(100) DEFAULT NULL,
  `email` VARCHAR(255) DEFAULT NULL,
  `secondary_email` VARCHAR(255) DEFAULT NULL COMMENT 'Optional secondary email address for profile display only',
  `mobile_phone` VARCHAR(50) DEFAULT NULL,
  `linkedin_url` VARCHAR(255) DEFAULT NULL,
  `xing_url` VARCHAR(255) DEFAULT NULL,
  `industry` VARCHAR(100) DEFAULT NULL,
  `company` VARCHAR(255) DEFAULT NULL,
  `position` VARCHAR(255) DEFAULT NULL,
  `study_program` VARCHAR(255) DEFAULT NULL,
  `semester` VARCHAR(50) DEFAULT NULL,
  `angestrebter_abschluss` VARCHAR(100) DEFAULT NULL,
  `degree` VARCHAR(100) DEFAULT NULL,
  `graduation_year` INT DEFAULT NULL,
  `image_path` VARCHAR(500) DEFAULT NULL,
  `last_verified_at` DATETIME DEFAULT NULL,
  `last_reminder_sent_at` DATETIME DEFAULT NULL,
  `bio` TEXT,
  `skills` TEXT DEFAULT NULL COMMENT 'Comma-separated list of skills/competencies',
  `cv_path` VARCHAR(500) DEFAULT NULL COMMENT 'Path to uploaded CV/resume PDF',
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY `unique_user_id` (`user_id`),
  INDEX `idx_user_id` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ================================================
-- TABLE: polls
-- ================================================
CREATE TABLE IF NOT EXISTS `polls` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `title` VARCHAR(255) NOT NULL,
  `description` TEXT,
  `microsoft_forms_url` TEXT DEFAULT NULL COMMENT 'Microsoft Forms embed URL or direct link for external survey integration',
  `target_groups` JSON DEFAULT NULL COMMENT 'JSON array of target groups (candidate, alumni_board, board, member, head)',
  `visible_to_all` BOOLEAN NOT NULL DEFAULT 0 COMMENT 'If true, show poll to all users regardless of roles',
  `is_internal` BOOLEAN NOT NULL DEFAULT 1 COMMENT 'If true, hide poll after user votes. If false (external Forms), show hide button',
  `allowed_roles` JSON DEFAULT NULL COMMENT 'JSON array of Entra roles that can see this poll (filters against user azure_roles)',
  `target_roles` JSON DEFAULT NULL COMMENT 'JSON array of Microsoft Entra roles required to see this poll',
  `is_active` BOOLEAN NOT NULL DEFAULT 1 COMMENT 'Flag to activate/deactivate poll display',
  `end_date` DATETIME DEFAULT NULL COMMENT 'Poll expiration date',
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `created_by` INT UNSIGNED NOT NULL,
  INDEX `idx_created_by` (`created_by`),
  INDEX `idx_is_active` (`is_active`),
  INDEX `idx_end_date` (`end_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ================================================
-- TABLE: mass_mail_jobs
-- ================================================
CREATE TABLE IF NOT EXISTS `mass_mail_jobs` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `subject` VARCHAR(255) NOT NULL COMMENT 'Email subject',
  `body_template` TEXT NOT NULL COMMENT 'Raw body template with placeholders',
  `event_name` VARCHAR(255) DEFAULT NULL COMMENT 'Value for {Event_Name} placeholder',
  `event_id` INT UNSIGNED DEFAULT NULL COMMENT 'ID of the linked event for placeholder substitution',
  `status` ENUM('active','paused','completed') NOT NULL DEFAULT 'active' COMMENT 'Job status',
  `next_run_at` DATETIME DEFAULT NULL COMMENT 'When this job should automatically continue',
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `created_by` INT UNSIGNED DEFAULT NULL COMMENT 'User ID who created the job',
  `total_recipients` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Total number of recipients',
  `sent_count` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Number of emails sent so far',
  `failed_count` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Number of failed sends',
  INDEX `idx_status` (`status`),
  INDEX `idx_next_run_at` (`next_run_at`),
  INDEX `idx_created_by` (`created_by`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Tracks bulk email sending jobs for batch processing';

-- ================================================
-- TABLE: mass_mail_recipients
-- ================================================
CREATE TABLE IF NOT EXISTS `mass_mail_recipients` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `job_id` INT UNSIGNED NOT NULL,
  `email` VARCHAR(255) NOT NULL,
  `first_name` VARCHAR(100) DEFAULT NULL,
  `last_name` VARCHAR(100) DEFAULT NULL,
  `status` ENUM('pending','sent','failed') NOT NULL DEFAULT 'pending',
  `processed_at` DATETIME DEFAULT NULL,
  FOREIGN KEY (`job_id`) REFERENCES `mass_mail_jobs`(`id`) ON DELETE CASCADE,
  INDEX `idx_job_id` (`job_id`),
  INDEX `idx_status` (`status`),
  INDEX `idx_job_status` (`job_id`, `status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Individual recipients for bulk email jobs';

-- ================================================
-- TABLE: poll_hidden_by_user
-- ================================================
CREATE TABLE IF NOT EXISTS `poll_hidden_by_user` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `poll_id` INT UNSIGNED NOT NULL,
  `user_id` INT UNSIGNED NOT NULL,
  `hidden_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY `unique_poll_user` (`poll_id`, `user_id`),
  INDEX `idx_poll_id` (`poll_id`),
  INDEX `idx_user_id` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Tracks which users have manually hidden which polls';

-- ================================================
-- TABLE: system_settings
-- ================================================
CREATE TABLE IF NOT EXISTS `system_settings` (
  `setting_key` VARCHAR(100) PRIMARY KEY,
  `setting_value` TEXT,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `updated_by` INT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ================================================
-- TABLE: event_roles
-- ================================================
CREATE TABLE IF NOT EXISTS `event_roles` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `event_id` INT UNSIGNED NOT NULL,
  `role` VARCHAR(255) NOT NULL COMMENT 'Role name/identifier',
  FOREIGN KEY (`event_id`) REFERENCES `events`(`id`) ON DELETE CASCADE,
  INDEX `idx_event_id` (`event_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Roles/permissions associated with events';

-- ================================================
-- TABLE: event_helper_types
-- ================================================
CREATE TABLE IF NOT EXISTS `event_helper_types` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `event_id` INT UNSIGNED NOT NULL,
  `title` VARCHAR(255) NOT NULL,
  `description` TEXT,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`event_id`) REFERENCES `events`(`id`) ON DELETE CASCADE,
  INDEX `idx_event_id` (`event_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Types of helper roles needed for events';

-- ================================================
-- TABLE: event_slots
-- ================================================
CREATE TABLE IF NOT EXISTS `event_slots` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `helper_type_id` INT UNSIGNED NOT NULL,
  `start_time` DATETIME NOT NULL,
  `end_time` DATETIME NOT NULL,
  `quantity_needed` INT UNSIGNED NOT NULL DEFAULT 1,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`helper_type_id`) REFERENCES `event_helper_types`(`id`) ON DELETE CASCADE,
  INDEX `idx_helper_type_id` (`helper_type_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Time slots for event helpers';

-- ================================================
-- TABLE: event_signups
-- ================================================
CREATE TABLE IF NOT EXISTS `event_signups` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `event_id` INT UNSIGNED NOT NULL,
  `user_id` INT UNSIGNED NOT NULL,
  `slot_id` INT UNSIGNED DEFAULT NULL,
  `status` ENUM('pending', 'confirmed', 'cancelled') DEFAULT 'confirmed',
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`event_id`) REFERENCES `events`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`slot_id`) REFERENCES `event_slots`(`id`) ON DELETE SET NULL,
  INDEX `idx_event_id` (`event_id`),
  INDEX `idx_user_id` (`user_id`),
  INDEX `idx_slot_id` (`slot_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='User signups for event helper slots';

-- ================================================
-- TABLE: event_history
-- ================================================
CREATE TABLE IF NOT EXISTS `event_history` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `event_id` INT UNSIGNED NOT NULL,
  `user_id` INT UNSIGNED NOT NULL,
  `change_type` VARCHAR(100) NOT NULL,
  `change_details` TEXT,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`event_id`) REFERENCES `events`(`id`) ON DELETE CASCADE,
  INDEX `idx_event_id` (`event_id`),
  INDEX `idx_user_id` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Audit trail for event changes';

-- ================================================
-- TABLE: projects
-- NOTE: For existing databases, run:
--   ALTER TABLE projects ADD COLUMN feedback_contact_user_id INT UNSIGNED DEFAULT NULL COMMENT 'User ID of the alumni who volunteered as feedback contact' AFTER documentation;
-- ================================================
CREATE TABLE IF NOT EXISTS `projects` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `title` VARCHAR(255) NOT NULL,
  `description` TEXT,
  `client_name` VARCHAR(255) DEFAULT NULL,
  `client_contact_details` TEXT DEFAULT NULL,
  `priority` ENUM('low', 'medium', 'high') DEFAULT 'medium',
  `type` ENUM('internal', 'external') DEFAULT 'internal',
  `status` ENUM('draft', 'open', 'in_progress', 'completed', 'cancelled') DEFAULT 'draft',
  `max_consultants` INT UNSIGNED DEFAULT NULL,
  `requires_application` TINYINT NOT NULL DEFAULT 1 COMMENT 'Whether applicants must submit an application (0 = direct join, 1 = application required)',
  `start_date` DATE DEFAULT NULL,
  `end_date` DATE DEFAULT NULL,
  `image_path` VARCHAR(500) DEFAULT NULL,
  `documentation` VARCHAR(500) DEFAULT NULL COMMENT 'Path to project documentation PDF',
  `feedback_contact_user_id` INT UNSIGNED DEFAULT NULL COMMENT 'User ID of the alumni who volunteered as feedback contact',
  `created_by` INT UNSIGNED DEFAULT NULL COMMENT 'User who created the project',
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX `idx_status` (`status`),
  INDEX `idx_type` (`type`),
  INDEX `idx_priority` (`priority`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Project management';

-- ================================================
-- TABLE: project_applications
-- ================================================
CREATE TABLE IF NOT EXISTS `project_applications` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `project_id` INT UNSIGNED NOT NULL,
  `user_id` INT UNSIGNED NOT NULL,
  `motivation` TEXT,
  `experience_count` INT UNSIGNED DEFAULT 0,
  `status` ENUM('pending', 'accepted', 'rejected') DEFAULT 'pending',
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`project_id`) REFERENCES `projects`(`id`) ON DELETE CASCADE,
  INDEX `idx_project_id` (`project_id`),
  INDEX `idx_user_id` (`user_id`),
  INDEX `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='User applications for projects';

-- ================================================
-- TABLE: project_assignments
-- ================================================
CREATE TABLE IF NOT EXISTS `project_assignments` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `project_id` INT UNSIGNED NOT NULL,
  `user_id` INT UNSIGNED NOT NULL,
  `role` VARCHAR(100) DEFAULT 'consultant',
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`project_id`) REFERENCES `projects`(`id`) ON DELETE CASCADE,
  UNIQUE KEY `unique_project_user` (`project_id`, `user_id`),
  INDEX `idx_project_id` (`project_id`),
  INDEX `idx_user_id` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='User assignments to projects';

-- ================================================
-- TABLE: blog_posts
-- ================================================
CREATE TABLE IF NOT EXISTS `blog_posts` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `title` VARCHAR(255) NOT NULL,
  `content` TEXT NOT NULL,
  `image_path` VARCHAR(500) DEFAULT NULL,
  `external_link` VARCHAR(500) DEFAULT NULL,
  `category` VARCHAR(100) DEFAULT NULL,
  `author_id` INT UNSIGNED NOT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX `idx_author_id` (`author_id`),
  INDEX `idx_category` (`category`),
  INDEX `idx_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Blog posts and news articles';

-- ================================================
-- TABLE: blog_likes
-- ================================================
CREATE TABLE IF NOT EXISTS `blog_likes` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `post_id` INT UNSIGNED NOT NULL,
  `user_id` INT UNSIGNED NOT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`post_id`) REFERENCES `blog_posts`(`id`) ON DELETE CASCADE,
  UNIQUE KEY `unique_post_user` (`post_id`, `user_id`),
  INDEX `idx_post_id` (`post_id`),
  INDEX `idx_user_id` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='User likes on blog posts';

-- ================================================
-- TABLE: blog_comments
-- ================================================
CREATE TABLE IF NOT EXISTS `blog_comments` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `post_id` INT UNSIGNED NOT NULL,
  `user_id` INT UNSIGNED NOT NULL,
  `content` TEXT NOT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (`post_id`) REFERENCES `blog_posts`(`id`) ON DELETE CASCADE,
  INDEX `idx_post_id` (`post_id`),
  INDEX `idx_user_id` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Comments on blog posts';

-- ================================================
-- TABLE: blog_comment_reactions
-- ================================================
CREATE TABLE IF NOT EXISTS `blog_comment_reactions` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `comment_id` INT UNSIGNED NOT NULL,
  `user_id` INT UNSIGNED NOT NULL,
  `reaction` VARCHAR(10) NOT NULL COMMENT 'Emoji reaction (e.g. 👍, ❤️, 😄)',
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`comment_id`) REFERENCES `blog_comments`(`id`) ON DELETE CASCADE,
  UNIQUE KEY `unique_comment_user_reaction` (`comment_id`, `user_id`, `reaction`),
  INDEX `idx_comment_id` (`comment_id`),
  INDEX `idx_user_id` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Emoji reactions on blog comments';

-- ================================================
-- TABLE: categories
-- ================================================
CREATE TABLE IF NOT EXISTS `categories` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `name` VARCHAR(100) NOT NULL UNIQUE,
  `description` TEXT,
  `color` VARCHAR(7) DEFAULT '#6D9744' COMMENT 'Hex color code for category',
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Categories for inventory items';

-- ================================================
-- TABLE: locations
-- ================================================
CREATE TABLE IF NOT EXISTS `locations` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `name` VARCHAR(100) NOT NULL UNIQUE,
  `description` TEXT,
  `address` TEXT,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Storage locations for inventory items';

-- ================================================
-- TABLE: inventory_items
-- ================================================
CREATE TABLE IF NOT EXISTS `inventory_items` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `name` VARCHAR(255) NOT NULL,
  `description` TEXT,
  `category_id` INT UNSIGNED DEFAULT NULL,
  `location_id` INT UNSIGNED DEFAULT NULL,
  `quantity` INT NOT NULL DEFAULT 0 COMMENT 'Current available stock (EasyVerein sync)',
  `total_quantity` INT NOT NULL DEFAULT 1 COMMENT 'Total stock from EasyVerein data',
  `quantity_borrowed` INT NOT NULL DEFAULT 0 COMMENT 'Number of items currently borrowed/checked out',
  `quantity_rented` INT NOT NULL DEFAULT 0 COMMENT 'Number of items currently rented (via rental with return date, awaiting board confirmation)',
  `loaned_count` INT DEFAULT 0,
  `min_stock` INT DEFAULT 0 COMMENT 'Minimum stock level for alerts',
  `unit` VARCHAR(50) DEFAULT 'Stück' COMMENT 'Unit of measurement',
  `unit_price` DECIMAL(10, 2) DEFAULT NULL,
  `image_path` VARCHAR(500) DEFAULT NULL,
  `notes` TEXT,
  `serial_number` VARCHAR(255) DEFAULT NULL,
  `easyverein_id` VARCHAR(255) DEFAULT NULL COMMENT 'ID from EasyVerein sync',
  `is_archived_in_easyverein` BOOLEAN NOT NULL DEFAULT 0 COMMENT 'Flag indicating if item is archived in EasyVerein',
  `last_synced_at` TIMESTAMP NULL DEFAULT NULL COMMENT 'Last sync timestamp from EasyVerein',
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (`category_id`) REFERENCES `categories`(`id`) ON DELETE SET NULL,
  FOREIGN KEY (`location_id`) REFERENCES `locations`(`id`) ON DELETE SET NULL,
  INDEX `idx_category_id` (`category_id`),
  INDEX `idx_location_id` (`location_id`),
  INDEX `idx_easyverein_id` (`easyverein_id`),
  INDEX `idx_is_archived_in_easyverein` (`is_archived_in_easyverein`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Inventory items';

-- ================================================
-- TABLE: inventory_rentals
-- ================================================
CREATE TABLE IF NOT EXISTS `inventory_rentals` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `item_id` INT UNSIGNED NOT NULL,
  `user_id` INT UNSIGNED NOT NULL,
  `amount` INT UNSIGNED NOT NULL DEFAULT 1 COMMENT 'Legacy quantity field',
  `rented_quantity` INT NOT NULL DEFAULT 1 COMMENT 'Number of units rented in this transaction (partial lending)',
  `purpose` VARCHAR(255) DEFAULT NULL COMMENT 'Purpose of the rental',
  `destination` VARCHAR(255) DEFAULT NULL COMMENT 'Destination/location where item is used',
  `checkout_date` DATETIME DEFAULT CURRENT_TIMESTAMP COMMENT 'Date when item was checked out',
  `expected_return` DATE DEFAULT NULL,
  `actual_return` DATE DEFAULT NULL,
  `status` ENUM('rented', 'pending_return', 'returned', 'overdue') NOT NULL DEFAULT 'rented' COMMENT 'Rental status',
  `notes` TEXT,
  `defect_notes` TEXT,
  `return_approved_by` INT UNSIGNED DEFAULT NULL COMMENT 'User ID who approved the return',
  `return_approved_at` DATETIME DEFAULT NULL COMMENT 'Timestamp when return was approved',
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`item_id`) REFERENCES `inventory_items`(`id`) ON DELETE CASCADE,
  INDEX `idx_item_id` (`item_id`),
  INDEX `idx_user_id` (`user_id`),
  INDEX `idx_actual_return` (`actual_return`),
  INDEX `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Item rentals/loans tracking';

-- ================================================
-- TABLE: inventory_history
-- ================================================
CREATE TABLE IF NOT EXISTS `inventory_history` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `item_id` INT UNSIGNED NOT NULL,
  `user_id` INT UNSIGNED NOT NULL,
  `change_type` ENUM('create', 'update', 'delete', 'adjustment', 'checkout', 'checkin', 'writeoff', 'sync_update', 'sync_create', 'sync_archive') NOT NULL,
  `old_stock` INT DEFAULT NULL,
  `new_stock` INT DEFAULT NULL,
  `change_amount` INT DEFAULT NULL,
  `reason` VARCHAR(255) DEFAULT NULL,
  `comment` TEXT,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`item_id`) REFERENCES `inventory_items`(`id`) ON DELETE CASCADE,
  INDEX `idx_item_id` (`item_id`),
  INDEX `idx_user_id` (`user_id`),
  INDEX `idx_change_type` (`change_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Audit trail for inventory changes';

-- ================================================
-- TABLE: inventory_requests
-- Board approval workflow for inventory loans.
-- Item master data comes live from the EasyVerein API.
-- NOTE: For existing databases, run:
--   ALTER TABLE `inventory_requests`
--     ADD COLUMN `status` ENUM('pending','approved','rejected','returned','pending_return') NOT NULL DEFAULT 'pending'
--     COMMENT 'Approval workflow status'
--     AFTER `purpose`;
--   ALTER TABLE `inventory_requests` ADD INDEX `idx_ir_status` (`status`);
-- ================================================
CREATE TABLE IF NOT EXISTS `inventory_requests` (
  `id`                  INT UNSIGNED        AUTO_INCREMENT PRIMARY KEY,
  `inventory_object_id` VARCHAR(64)         NOT NULL    COMMENT 'easyVerein inventory-object ID',
  `user_id`             INT UNSIGNED        NOT NULL    COMMENT 'Applicant (Antragsteller) local user ID',
  `start_date`          DATE                NOT NULL    COMMENT 'Requested start date of the loan',
  `end_date`            DATE                NOT NULL    COMMENT 'Requested end date of the loan',
  `quantity`            INT UNSIGNED        NOT NULL DEFAULT 1 COMMENT 'Number of units requested',
  `purpose`             VARCHAR(200)        DEFAULT NULL COMMENT 'Stated purpose / Verwendungszweck',
  `status`              ENUM('pending','approved','rejected','returned','pending_return') NOT NULL DEFAULT 'pending' COMMENT 'Approval workflow status',
  `return_notes`        TEXT                DEFAULT NULL COMMENT 'Optional remarks at return',
  `returned_at`         DATETIME            DEFAULT NULL COMMENT 'When the return was verified',
  `created_at`          DATETIME            NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'When the request was created',
  INDEX `idx_ir_inventory_object_id` (`inventory_object_id`),
  INDEX `idx_ir_user_id`             (`user_id`),
  INDEX `idx_ir_status`              (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Inventory loan requests with board approval workflow';

-- ================================================
-- TABLE: poll_options
-- ================================================
CREATE TABLE IF NOT EXISTS `poll_options` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `poll_id` INT UNSIGNED NOT NULL,
  `option_text` VARCHAR(500) NOT NULL COMMENT 'Text of the poll option',
  `display_order` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Order in which options are displayed',
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`poll_id`) REFERENCES `polls`(`id`) ON DELETE CASCADE,
  INDEX `idx_poll_id` (`poll_id`),
  INDEX `idx_display_order` (`display_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Options/choices for internal polls (not used for Microsoft Forms)';

-- ================================================
-- TABLE: poll_votes
-- ================================================
CREATE TABLE IF NOT EXISTS `poll_votes` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `poll_id` INT UNSIGNED NOT NULL,
  `option_id` INT UNSIGNED NOT NULL,
  `user_id` INT UNSIGNED NOT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`poll_id`) REFERENCES `polls`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`option_id`) REFERENCES `poll_options`(`id`) ON DELETE CASCADE,
  UNIQUE KEY `unique_poll_user_vote` (`poll_id`, `user_id`),
  INDEX `idx_poll_id` (`poll_id`),
  INDEX `idx_option_id` (`option_id`),
  INDEX `idx_user_id` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='User votes on poll options (not used for Microsoft Forms)';

-- ================================================
-- TABLE: event_registrations
-- ================================================
CREATE TABLE IF NOT EXISTS `event_registrations` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `event_id` INT UNSIGNED NOT NULL,
  `user_id` INT UNSIGNED NOT NULL,
  `status` ENUM('confirmed', 'cancelled') NOT NULL DEFAULT 'confirmed',
  `registered_at` DATETIME NOT NULL,
  `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`event_id`) REFERENCES `events`(`id`) ON DELETE CASCADE,
  UNIQUE KEY `unique_event_user_registration` (`event_id`, `user_id`),
  INDEX `idx_event_id` (`event_id`),
  INDEX `idx_user_id` (`user_id`),
  INDEX `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Simple event registrations (alternative to event_signups with slots)';

-- ================================================
-- TABLE: system_logs
-- ================================================
CREATE TABLE IF NOT EXISTS `system_logs` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT UNSIGNED NOT NULL COMMENT 'User who performed the action (0 for system/cron)',
  `action` VARCHAR(100) NOT NULL COMMENT 'Action type (e.g., login_success, invitation_created)',
  `entity_type` VARCHAR(100) DEFAULT NULL COMMENT 'Type of entity affected (e.g., user, event, cron)',
  `entity_id` INT UNSIGNED DEFAULT NULL COMMENT 'ID of affected entity',
  `details` TEXT DEFAULT NULL COMMENT 'Additional details in text or JSON format',
  `ip_address` VARCHAR(45) DEFAULT NULL COMMENT 'IP address of the user',
  `user_agent` TEXT DEFAULT NULL COMMENT 'User agent string',
  `timestamp` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_user_id` (`user_id`),
  INDEX `idx_action` (`action`),
  INDEX `idx_entity_type` (`entity_type`),
  INDEX `idx_entity_id` (`entity_id`),
  INDEX `idx_timestamp` (`timestamp`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='System-wide audit log for tracking all user and system actions';

-- ================================================
-- TABLE: links
-- ================================================
CREATE TABLE IF NOT EXISTS `links` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `title` VARCHAR(255) NOT NULL,
  `url` VARCHAR(500) NOT NULL,
  `description` VARCHAR(500) DEFAULT NULL,
  `icon` VARCHAR(100) DEFAULT 'fas fa-link',
  `category` VARCHAR(100) DEFAULT NULL COMMENT 'Optional category for grouping links',
  `sort_order` INT UNSIGNED DEFAULT 0,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `created_by` INT UNSIGNED DEFAULT NULL COMMENT 'User ID who created the link',
  INDEX `idx_sort_order` (`sort_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Useful links for quick access to frequently used tools and resources';

-- ================================================
-- TABLE: mail_queue
-- ================================================
CREATE TABLE IF NOT EXISTS `mail_queue` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `to_email` VARCHAR(255) NOT NULL COMMENT 'Recipient email address',
  `subject` VARCHAR(500) NOT NULL COMMENT 'Email subject line',
  `body` LONGTEXT NOT NULL COMMENT 'Full HTML email body',
  `sent` TINYINT NOT NULL DEFAULT 0 COMMENT 'Send status: 0 = pending, 1 = sent',
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `sent_at` DATETIME DEFAULT NULL COMMENT 'Timestamp when the email was successfully sent',
  INDEX `idx_sent` (`sent`),
  INDEX `idx_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Email queue for batch processing via cron job (200 per batch, 60-minute cooldown)';

-- ================================================
-- TABLE: newsletters
-- ================================================
CREATE TABLE IF NOT EXISTS `newsletters` (
    `id`          INT UNSIGNED   NOT NULL AUTO_INCREMENT,
    `title`       VARCHAR(255)   NOT NULL,
    `month_year`  VARCHAR(20)    DEFAULT NULL    COMMENT 'Versandmonat/-jahr, z. B. März 2025',
    `file_path`   VARCHAR(500)   NOT NULL        COMMENT 'Gespeicherter Dateiname im Upload-Verzeichnis',
    `uploaded_by` INT UNSIGNED   DEFAULT NULL    COMMENT 'Intranet-Benutzer-ID des Hochladenden',
    `created_at`  TIMESTAMP      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_month_year`  (`month_year`),
    KEY `idx_uploaded_by` (`uploaded_by`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Internes Newsletter-Archiv (.eml / .msg Dateien)';

-- ================================================
-- SEED DATA: links
-- ================================================
INSERT IGNORE INTO `links` (`id`, `title`, `url`, `description`, `icon`, `sort_order`) VALUES
(1,  'IBC Website',           'https://www.business-consulting.de', 'Offizielle IBC-Vereinswebsite',              'fas fa-globe',        1),
(2,  'EasyVerein',            'https://app.easyverein.com',         'Mitgliederverwaltung und Vereinsbuchhaltung', 'fas fa-users-cog',    2),
(3,  'Microsoft 365',         'https://www.office.com',             'Office-Apps, E-Mail und Kalender',            'fab fa-microsoft',    3),
(4,  'Microsoft Entra Admin', 'https://entra.microsoft.com',        'Benutzerverwaltung und Identitäten',          'fas fa-id-badge',     4),
(5,  'SharePoint',            'https://sharepoint.com',             'Dokumente und Zusammenarbeit',                'fas fa-folder-open',  5),
(6,  'Teams',                 'https://teams.microsoft.com',        'Chats, Meetings und Kanäle',                  'fas fa-comments',     6),
(7,  'Azure Portal',          'https://portal.azure.com',           'Cloud-Infrastruktur und Dienste',             'fas fa-cloud',        7),
(8,  'GitHub',                'https://github.com',                 'Quellcode und Versionsverwaltung',            'fab fa-github',       8),
(9,  'Confluence',           'https://business-consulting.atlassian.net', 'Wissensdatenbank und Dokumentation',        'fab fa-atlassian',    9);

-- ================================================
-- TABLE: ideas
-- ================================================
CREATE TABLE IF NOT EXISTS `ideas` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT UNSIGNED NOT NULL,
  `title` VARCHAR(200) NOT NULL,
  `description` TEXT NOT NULL,
  `status` ENUM('new', 'in_review', 'accepted', 'rejected', 'implemented') DEFAULT 'new',
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX `idx_user_id` (`user_id`),
  INDEX `idx_status` (`status`),
  INDEX `idx_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Ideas submitted by members';

-- ================================================
-- TABLE: idea_votes
-- ================================================
CREATE TABLE IF NOT EXISTS `idea_votes` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `idea_id` INT UNSIGNED NOT NULL,
  `user_id` INT UNSIGNED NOT NULL,
  `vote` ENUM('up', 'down') NOT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY `uq_user_idea` (`idea_id`, `user_id`),
  INDEX `idx_idea_id` (`idea_id`),
  INDEX `idx_user_id` (`user_id`),
  FOREIGN KEY (`idea_id`) REFERENCES `ideas`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Up/down votes on ideas';

-- ================================================
-- TABLE: job_board
-- NOTE: For existing databases, run:
--   CREATE TABLE IF NOT EXISTS `job_board` (
--     `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
--     `user_id` INT UNSIGNED NOT NULL,
--     `title` VARCHAR(255) NOT NULL,
--     `search_type` ENUM('Festanstellung', 'Werksstudententätigkeit', 'Praxissemester', 'Praktikum') NOT NULL,
--     `description` TEXT NOT NULL,
--     `pdf_path` VARCHAR(500) DEFAULT NULL,
--     `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
--     INDEX `idx_user_id` (`user_id`),
--     INDEX `idx_search_type` (`search_type`),
--     INDEX `idx_created_at` (`created_at`)
--   ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
--   COMMENT='Job and internship listings posted by users';
-- ================================================
CREATE TABLE IF NOT EXISTS `job_board` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT UNSIGNED NOT NULL,
  `title` VARCHAR(255) NOT NULL,
  `search_type` ENUM('Festanstellung', 'Werksstudententätigkeit', 'Praxissemester', 'Praktikum') NOT NULL,
  `description` TEXT NOT NULL,
  `pdf_path` VARCHAR(500) DEFAULT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_user_id` (`user_id`),
  INDEX `idx_search_type` (`search_type`),
  INDEX `idx_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Job and internship listings posted by users';

-- ================================================
-- TABLE: alumni_access_requests
-- Stores public requests from alumni who need help
-- recovering or updating their e-mail address.
-- ================================================
CREATE TABLE IF NOT EXISTS `alumni_access_requests` (
  `id`                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `first_name`          VARCHAR(100)  NOT NULL                    COMMENT 'Applicant first name',
  `last_name`           VARCHAR(100)  NOT NULL                    COMMENT 'Applicant last name',
  `new_email`           VARCHAR(255)  NOT NULL                    COMMENT 'New / desired e-mail address',
  `old_email`           VARCHAR(255)  DEFAULT NULL                COMMENT 'Previously used e-mail address (optional)',
  `graduation_semester` VARCHAR(20)   NOT NULL                    COMMENT 'Graduation semester, e.g. WS 2019/20',
  `study_program`       VARCHAR(255)  NOT NULL                    COMMENT 'Field of study / study programme',
  `status`              ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending' COMMENT 'Processing status',
  `created_at`          TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `processed_at`        TIMESTAMP     NULL DEFAULT NULL           COMMENT 'Timestamp when the request was processed',
  `processed_by`        INT UNSIGNED  DEFAULT NULL                COMMENT 'User ID of the admin who processed the request',
  INDEX `idx_status`       (`status`),
  INDEX `idx_new_email`    (`new_email`),
  INDEX `idx_processed_by` (`processed_by`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

COMMIT;

-- ================================================
-- Migration: Add 'rolle' column to vcards_table
-- ================================================
-- Run this script on the external vCard database
-- to extend vcards_table with the new 'rolle' field
-- which stores hierarchical roles (e.g. Vorstand,
-- Ressortleitung).
-- ================================================

ALTER TABLE `vcards_table`
    ADD COLUMN `rolle` VARCHAR(50) DEFAULT NULL COMMENT 'Hierarchische Rolle (z.B. Vorstand, Ressortleitung)' AFTER `nachname`;

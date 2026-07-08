-- Migration: Fix schema issues and add missing columns
-- Date: 2026-07-08
-- Description: Adds missing columns for lecturer assignment management

-- Add instructions column to assignments table (for detailed assignment requirements)
ALTER TABLE `assignments`
ADD COLUMN `instructions` TEXT DEFAULT NULL AFTER `description`;

-- Add allow_late_submission column to assignments table
ALTER TABLE `assignments`
ADD COLUMN `allow_late_submission` TINYINT(1) DEFAULT 0 AFTER `total_marks`;

-- Rename downloads to download_count in notes table if needed
-- (This column already exists as download_count in the schema, so we skip this)

-- Add original_filename column to notes table (stores the user's original filename)
ALTER TABLE `notes`
ADD COLUMN `original_filename` VARCHAR(255) DEFAULT NULL AFTER `file_name`;

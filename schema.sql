-- Database Schema for Multi-Entity Multi-Currency Accounting System

CREATE DATABASE IF NOT EXISTS `accounting_db` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `accounting_db`;

-- 1. Entities
CREATE TABLE IF NOT EXISTS `entities` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `name` VARCHAR(150) NOT NULL,
  `default_currency` ENUM('INR', 'USDT') NOT NULL,
  `status` ENUM('active', 'inactive') NOT NULL DEFAULT 'active',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 2. Users
CREATE TABLE IF NOT EXISTS `users` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `full_name` VARCHAR(100) NOT NULL,
  `email` VARCHAR(100) NOT NULL UNIQUE,
  `password_hash` VARCHAR(255) NOT NULL,
  `role` ENUM('admin', 'staff') NOT NULL DEFAULT 'staff',
  `status` ENUM('active', 'inactive') NOT NULL DEFAULT 'active',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 3. Chart of Accounts
CREATE TABLE IF NOT EXISTS `chart_of_accounts` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `account_code` VARCHAR(20) NOT NULL UNIQUE,
  `account_name` VARCHAR(100) NOT NULL,
  `account_type` ENUM('Asset', 'Liability', 'Equity', 'Income', 'Expense') NOT NULL,
  `currency_type` ENUM('INR', 'USDT', 'BOTH') NOT NULL DEFAULT 'BOTH',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 4. Journal Entries
CREATE TABLE IF NOT EXISTS `journal_entries` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `entity_id` INT NOT NULL,
  `entry_date` DATE NOT NULL,
  `currency` ENUM('INR', 'USDT') NOT NULL,
  `reference_number` VARCHAR(100) DEFAULT NULL,
  `description` TEXT,
  `created_by` INT NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`entity_id`) REFERENCES `entities` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  INDEX (`entry_date`),
  INDEX (`currency`),
  INDEX (`entity_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 5. Journal Entry Lines
CREATE TABLE IF NOT EXISTS `journal_entry_lines` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `journal_entry_id` INT NOT NULL,
  `account_id` INT NOT NULL,
  `debit` DECIMAL(18, 4) NOT NULL DEFAULT 0.0000,
  `credit` DECIMAL(18, 4) NOT NULL DEFAULT 0.0000,
  FOREIGN KEY (`journal_entry_id`) REFERENCES `journal_entries` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  FOREIGN KEY (`account_id`) REFERENCES `chart_of_accounts` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  INDEX (`journal_entry_id`),
  INDEX (`account_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 6. Audit Logs
CREATE TABLE IF NOT EXISTS `audit_logs` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT DEFAULT NULL,
  `action` VARCHAR(255) NOT NULL,
  `ip_address` VARCHAR(45) NOT NULL,
  `timestamp` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  INDEX (`user_id`),
  INDEX (`timestamp`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 7. API Tokens (for Mobile Bearer Token Auth)
CREATE TABLE IF NOT EXISTS `api_tokens` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT NOT NULL,
  `token` VARCHAR(64) NOT NULL UNIQUE,
  `expires_at` DATETIME NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  INDEX (`token`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Seed Data

-- Entities
INSERT INTO `entities` (`id`, `name`, `default_currency`, `status`) VALUES
(1, 'Consultation & GST Services', 'INR', 'active'),
(2, 'Burfee Cart', 'INR', 'active'),
(3, 'Optimus Infinity', 'USDT', 'active'),
(4, 'Rayon Global Impact', 'USDT', 'active'),
(5, 'TAX-India', 'INR', 'active')
ON DUPLICATE KEY UPDATE `name` = VALUES(`name`);

-- Default Users (Password: admin123 / staff123)
INSERT INTO `users` (`id`, `full_name`, `email`, `password_hash`, `role`, `status`) VALUES
(1, 'System Admin', 'admin@company.com', '$2y$10$UAVDLdsCS6pyuQzYvfnlAOJqXRUGoIqHDOKXVlRZyyaodR9yqUQtm', 'admin', 'active'),
(2, 'Staff User', 'staff@company.com', '$2y$10$vyNV00w995T8eyu5xKJpbunTiLDY1beTHtFNqG9JMvg2kNBfRd3hC', 'staff', 'active')
ON DUPLICATE KEY UPDATE `full_name` = VALUES(`full_name`);

-- Chart of Accounts
-- Chart of Accounts (Basic Generic Account Heads)
INSERT INTO `chart_of_accounts` (`id`, `account_code`, `account_name`, `account_type`, `currency_type`) VALUES
(1, '1010', 'Cash in Hand', 'Asset', 'INR'),
(2, '1020', 'Bank Account', 'Asset', 'INR'),
(3, '1030', 'USDT Wallet', 'Asset', 'USDT'),
(4, '2010', 'Liabilities / Payable', 'Liability', 'BOTH'),
(5, '3010', 'Owner Equity', 'Equity', 'BOTH'),
(6, '4010', 'General Revenue / Income', 'Income', 'BOTH'),
(7, '5010', 'General Expense', 'Expense', 'BOTH')
ON DUPLICATE KEY UPDATE `account_name` = VALUES(`account_name`);

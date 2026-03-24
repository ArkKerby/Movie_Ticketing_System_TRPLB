-- PWD Discount System Migration
-- Run this in phpMyAdmin: select TICKETIX database -> SQL tab -> paste ALL and run

USE TICKETIX;

-- 1. Add pwd_approved column to USER_ACCOUNT
--    (If you get "Duplicate column" error, it means the column already exists -- that's fine, skip this line)
ALTER TABLE USER_ACCOUNT ADD COLUMN pwd_approved TINYINT(1) DEFAULT 0;

-- 2. Create PWD_APPLICATIONS table
CREATE TABLE IF NOT EXISTS PWD_APPLICATIONS (
    pwd_app_id INT PRIMARY KEY AUTO_INCREMENT,
    acc_id INT NOT NULL,
    pwd_id_number VARCHAR(100) NOT NULL,
    pwd_id_image VARCHAR(255) NOT NULL,
    status ENUM('pending', 'approved', 'rejected') DEFAULT 'pending',
    admin_notes TEXT NULL,
    submitted_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    reviewed_at DATETIME NULL,
    FOREIGN KEY (acc_id) REFERENCES USER_ACCOUNT(acc_id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- 3. Create ADMIN_NOTIFICATIONS table
CREATE TABLE IF NOT EXISTS ADMIN_NOTIFICATIONS (
    notif_id INT PRIMARY KEY AUTO_INCREMENT,
    type VARCHAR(50) NOT NULL,
    message TEXT NOT NULL,
    is_read TINYINT(1) DEFAULT 0,
    reference_id INT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

SELECT 'Migration complete!' AS result;

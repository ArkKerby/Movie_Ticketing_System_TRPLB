-- Booking Type & Walk-in PWD Discount Migration
-- Run this in phpMyAdmin: select TICKETIX database -> SQL tab -> paste ALL and run

USE TICKETIX;

-- 1. Add booking_type column to RESERVE (online vs walk-in)
ALTER TABLE RESERVE ADD COLUMN booking_type ENUM('online','walk-in') DEFAULT 'online';

-- 2. Add PWD walk-in discount columns to RESERVE
ALTER TABLE RESERVE ADD COLUMN pwd_discount TINYINT(1) DEFAULT 0;
ALTER TABLE RESERVE ADD COLUMN pwd_id_number VARCHAR(100) NULL;
ALTER TABLE RESERVE ADD COLUMN pwd_id_image VARCHAR(255) NULL;

SELECT 'Migration complete!' AS result;

-- Consolidate to single TICKETIX branch with real cinema types
USE TICKETIX;

-- Disable foreign key checks
SET FOREIGN_KEY_CHECKS = 0;

-- 1. Clear existing cinema assignments and cinema numbers
DELETE FROM CINEMA_MOVIE_ASSIGNMENT;
DELETE FROM CINEMA_NUMBER;
DELETE FROM MALL_ADMIN_BRANCH;

-- 2. Remove all existing branches and create single TICKETIX branch
DELETE FROM BRANCH;
INSERT INTO BRANCH (branch_id, branch_name, branch_location) VALUES
(1, 'TICKETIX', 'TICKETIX Cinema');

-- 3. Point all existing movie schedules to the TICKETIX branch
UPDATE MOVIE_SCHEDULE SET branch_id = 1;

-- 4. Create cinema types (like real cinemas)
INSERT INTO CINEMA_NUMBER (branch_id, cinema_name, capacity) VALUES
(1, 'IMAX', 150),
(1, "Director's Club", 50),
(1, 'Regular', 120),
(1, 'Cinema 1', 100),
(1, 'Cinema 2', 100),
(1, 'Cinema 3', 100),
(1, 'Cinema 4', 100),
(1, 'Cinema 5', 100);

-- 5. Assign mall admin to TICKETIX branch
INSERT IGNORE INTO MALL_ADMIN_BRANCH (acc_id, branch_id)
SELECT acc_id, 1 FROM USER_ACCOUNT WHERE email = 'eyabananaaa0828@gmail.com';

-- Re-enable foreign key checks
SET FOREIGN_KEY_CHECKS = 1;

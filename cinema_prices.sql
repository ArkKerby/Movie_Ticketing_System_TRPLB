USE TICKETIX;

-- Add price column to CINEMA_NUMBER
ALTER TABLE CINEMA_NUMBER ADD COLUMN price DECIMAL(10,2) DEFAULT 350.00;

-- Set prices per cinema type
UPDATE CINEMA_NUMBER SET price = 900.00 WHERE cinema_name = 'IMAX';
UPDATE CINEMA_NUMBER SET price = 600.00 WHERE cinema_name = "Director's Club";
UPDATE CINEMA_NUMBER SET price = 350.00 WHERE cinema_name = 'Regular';
UPDATE CINEMA_NUMBER SET price = 275.00 WHERE cinema_name LIKE 'Cinema %';

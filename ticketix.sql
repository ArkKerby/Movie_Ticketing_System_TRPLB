-- ============================================================
--  TICKETIX — Full Database Schema (Consolidated & Final)
--  Reflects the post-migration state. Safe to run on a fresh DB.
--
--  Tables: 15
--    BRANCH, USER_ACCOUNT, MOVIE, CINEMA_NUMBER,
--    MOVIE_SCHEDULE, CINEMA_MOVIE_ASSIGNMENT,
--    RESERVE, RESERVE_SEAT, TICKET, TICKET_FOOD,
--    FOOD, USER_PAYMENT_METHODS, DISCOUNT_APPLICATIONS,
--    ADMIN_NOTIFICATIONS, CINEMA_MOVIE_ASSIGNMENT
-- ============================================================

CREATE DATABASE IF NOT EXISTS TICKETIX CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci;
USE TICKETIX;

-- ----------------------------------------------------------------
-- Drop tables in reverse-dependency order (children first)
-- ----------------------------------------------------------------
DROP TABLE IF EXISTS TICKET_FOOD;
DROP TABLE IF EXISTS TICKET;
DROP TABLE IF EXISTS RESERVE_SEAT;
DROP TABLE IF EXISTS RESERVE;
DROP TABLE IF EXISTS MOVIE_SCHEDULE;
DROP TABLE IF EXISTS CINEMA_MOVIE_ASSIGNMENT;
DROP TABLE IF EXISTS CINEMA_NUMBER;
DROP TABLE IF EXISTS MOVIE;
DROP TABLE IF EXISTS USER_PAYMENT_METHODS;
DROP TABLE IF EXISTS DISCOUNT_APPLICATIONS;
DROP TABLE IF EXISTS ADMIN_NOTIFICATIONS;
DROP TABLE IF EXISTS USER_ACCOUNT;
DROP TABLE IF EXISTS FOOD;
DROP TABLE IF EXISTS BRANCH;

-- ================================================================
-- 1. BRANCH
-- ================================================================
CREATE TABLE BRANCH (
    branch_id       INT PRIMARY KEY AUTO_INCREMENT,
    branch_name     VARCHAR(100) NOT NULL,
    branch_location VARCHAR(150),
    contact_number  VARCHAR(15)
) ENGINE=InnoDB;

INSERT INTO BRANCH (branch_name, branch_location, contact_number) VALUES
('Light Residences', 'EDSA Cor Madison St., Brgy Barangka Ilaya, Mandaluyong City', '09171234567');

-- ================================================================
-- 2. USER_ACCOUNT
-- ================================================================
CREATE TABLE USER_ACCOUNT (
    acc_id                 INT        PRIMARY KEY AUTO_INCREMENT,
    firstName              VARCHAR(50) NOT NULL,
    lastName               VARCHAR(50) NOT NULL,
    contNo                 VARCHAR(12),
    email                  VARCHAR(50) UNIQUE NOT NULL,
    address                VARCHAR(50),
    birthdate              DATE,
    user_password          VARCHAR(70),
    time_created           DATETIME,
    user_status            ENUM('online','offline')  DEFAULT 'offline',
    role                   VARCHAR(50)               DEFAULT 'user',
    reset_token_hash       VARCHAR(64)               NULL,
    reset_token_expires_at DATETIME                  NULL,
    pwd_approved           TINYINT(1)                DEFAULT 0,
    senior_approved        TINYINT(1)                DEFAULT 0
) ENGINE=InnoDB;

-- ================================================================
-- 3. MOVIE
-- ================================================================
CREATE TABLE MOVIE (
    movie_show_id  INT          PRIMARY KEY AUTO_INCREMENT,
    title          VARCHAR(50),
    genre          VARCHAR(100),
    duration       INT,
    rating         VARCHAR(20),
    movie_descrp   TEXT,
    image_poster   VARCHAR(100),
    carousel_image VARCHAR(100),
    now_showing    BOOLEAN      DEFAULT FALSE,
    coming_soon    BOOLEAN      DEFAULT FALSE,
    is_deleted     TINYINT(1)   DEFAULT 0,
    deleted_at     DATETIME     NULL,
    delete_at      DATETIME     NULL
) ENGINE=InnoDB;

CREATE INDEX idx_is_deleted ON MOVIE(is_deleted);

-- ================================================================
-- 4. CINEMA_NUMBER  (each branch has multiple screens)
-- ================================================================
CREATE TABLE CINEMA_NUMBER (
    cinema_number_id INT PRIMARY KEY AUTO_INCREMENT,
    branch_id        INT NOT NULL,
    cinema_name      VARCHAR(50) NOT NULL,
    capacity         INT DEFAULT 100,
    FOREIGN KEY (branch_id) REFERENCES BRANCH(branch_id) ON DELETE CASCADE
) ENGINE=InnoDB;

INSERT INTO CINEMA_NUMBER (branch_id, cinema_name, capacity) VALUES
(1,'Cinema 1',100),
(1,'Cinema 2',80),
(1,'Cinema 3',120);

-- ================================================================
-- 5. MOVIE_SCHEDULE
-- ================================================================
CREATE TABLE MOVIE_SCHEDULE (
    schedule_id   INT PRIMARY KEY AUTO_INCREMENT,
    movie_show_id INT NOT NULL,
    branch_id     INT NOT NULL,
    show_date     DATE,
    show_hour     TIME,
    FOREIGN KEY (movie_show_id) REFERENCES MOVIE(movie_show_id),
    FOREIGN KEY (branch_id)     REFERENCES BRANCH(branch_id)
) ENGINE=InnoDB;

-- ================================================================
-- 6. CINEMA_MOVIE_ASSIGNMENT
-- ================================================================
CREATE TABLE CINEMA_MOVIE_ASSIGNMENT (
    assignment_id    INT      PRIMARY KEY AUTO_INCREMENT,
    cinema_number_id INT      NOT NULL,
    movie_show_id    INT      NOT NULL,
    assigned_at      DATETIME DEFAULT CURRENT_TIMESTAMP,
    assigned_by      INT      NOT NULL,
    FOREIGN KEY (cinema_number_id) REFERENCES CINEMA_NUMBER(cinema_number_id) ON DELETE CASCADE,
    FOREIGN KEY (movie_show_id)    REFERENCES MOVIE(movie_show_id)            ON DELETE CASCADE,
    FOREIGN KEY (assigned_by)      REFERENCES USER_ACCOUNT(acc_id)
) ENGINE=InnoDB;

-- ================================================================
-- 7. RESERVE
-- ================================================================
CREATE TABLE RESERVE (
    reservation_id INT          PRIMARY KEY AUTO_INCREMENT,
    acc_id         INT,
    schedule_id    INT,
    reserve_date   DATETIME,
    ticket_amount  INT,
    sum_price      DECIMAL(10,2),
    food_total     DECIMAL(10,2) DEFAULT 0.00,
    booking_status ENUM('pending','approved','rejected','declined') DEFAULT 'pending',
    booking_type   ENUM('online','walk-in')  DEFAULT 'online',
    pwd_discount   TINYINT(1)   DEFAULT 0,
    pwd_id_number  VARCHAR(100) NULL,
    pwd_id_image   VARCHAR(255) NULL,
    FOREIGN KEY (acc_id)      REFERENCES USER_ACCOUNT(acc_id),
    FOREIGN KEY (schedule_id) REFERENCES MOVIE_SCHEDULE(schedule_id)
) ENGINE=InnoDB;

-- ================================================================
-- 8. RESERVE_SEAT
--    seat_number stored directly (no FK to SEAT — SEAT table removed)
-- ================================================================
CREATE TABLE RESERVE_SEAT (
    reserve_seat_id INT         PRIMARY KEY AUTO_INCREMENT,
    reservation_id  INT,
    seat_number     VARCHAR(10) DEFAULT NULL,
    FOREIGN KEY (reservation_id) REFERENCES RESERVE(reservation_id)
) ENGINE=InnoDB;

-- ================================================================
-- 9. FOOD
-- ================================================================
CREATE TABLE FOOD (
    food_id    INT           PRIMARY KEY AUTO_INCREMENT,
    food_name  VARCHAR(50)   NOT NULL,
    food_price DECIMAL(10,2) DEFAULT 0.00,
    image_path VARCHAR(255)
) ENGINE=InnoDB;

INSERT INTO FOOD (food_name, food_price, image_path) VALUES
('All-In-Combo', 199.00, 'images/all-in.png'),
('HotCoke',      165.00, 'images/hotdog-coke.png'),
('Froke',        120.00, 'images/fries-coke.png'),
('Fries',         50.00, 'images/fries-solo.png'),
('Hotdog',        60.00, 'images/hotdog-solo.png'),
('Coke',          40.00, 'images/coke-solo.png'),
('Popcorn',       40.00, 'images/popcorn-solo.png');

-- ================================================================
-- 10. TICKET
--     Payment columns merged in (PAYMENT table removed in consolidation)
-- ================================================================
CREATE TABLE TICKET (
    ticket_id        INT          PRIMARY KEY AUTO_INCREMENT,
    reserve_id       INT,
    ticket_number    VARCHAR(50),
    date_issued      DATETIME,
    ticket_status    ENUM('valid','cancelled','refunded','pending') DEFAULT 'pending',
    e_ticket_code    VARCHAR(100) UNIQUE,
    e_ticket_file    VARCHAR(255),
    -- Payment fields (merged from PAYMENT table)
    payment_type     ENUM('cash','credit','e-wallet')                        DEFAULT NULL,
    amount_paid      DECIMAL(10,2)                                            DEFAULT NULL,
    payment_status   ENUM('paid','pending','not-yet','refunded')              DEFAULT 'pending',
    payment_date     DATETIME                                                 DEFAULT NULL,
    reference_number VARCHAR(100)                                             DEFAULT NULL,
    FOREIGN KEY (reserve_id) REFERENCES RESERVE(reservation_id)
) ENGINE=InnoDB;

-- ================================================================
-- 11. TICKET_FOOD
-- ================================================================
CREATE TABLE TICKET_FOOD (
    ticket_food_id INT AUTO_INCREMENT PRIMARY KEY,
    ticket_id      INT NOT NULL,
    food_id        INT NOT NULL,
    quantity       INT NOT NULL DEFAULT 1,
    FOREIGN KEY (ticket_id) REFERENCES TICKET(ticket_id) ON DELETE CASCADE,
    FOREIGN KEY (food_id)   REFERENCES FOOD(food_id)     ON DELETE CASCADE,
    UNIQUE KEY unique_ticket_food (ticket_id, food_id)
) ENGINE=InnoDB;

-- ================================================================
-- 12. USER_PAYMENT_METHODS
-- ================================================================
CREATE TABLE USER_PAYMENT_METHODS (
    payment_method_id INT AUTO_INCREMENT PRIMARY KEY,
    acc_id            INT         NOT NULL,
    method_type       VARCHAR(50) NULL DEFAULT NULL,
    payment_type      VARCHAR(50),
    card_number       VARCHAR(20),
    card_holder       VARCHAR(100),
    card_name         VARCHAR(100),
    card_expiry       VARCHAR(10),
    card_cvv          VARCHAR(5),
    expiry_date       VARCHAR(10),
    paypal_email      VARCHAR(100),
    phone_number      VARCHAR(20),
    is_default        TINYINT(1)  DEFAULT 0,
    created_at        DATETIME    DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (acc_id) REFERENCES USER_ACCOUNT(acc_id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ================================================================
-- 13. DISCOUNT_APPLICATIONS
--     Unified table for both PWD and Senior Citizen applications
--     (replaces the old PWD_APPLICATIONS + SENIOR_APPLICATIONS tables)
-- ================================================================
CREATE TABLE DISCOUNT_APPLICATIONS (
    app_id        INT                         NOT NULL AUTO_INCREMENT,
    acc_id        INT                         NOT NULL,
    discount_type ENUM('pwd','senior')        NOT NULL,
    id_number     VARCHAR(100)                NOT NULL,
    id_image      VARCHAR(255)                NOT NULL,
    status        ENUM('pending','approved','rejected') DEFAULT 'pending',
    admin_notes   TEXT,
    submitted_at  DATETIME                    DEFAULT CURRENT_TIMESTAMP,
    reviewed_at   DATETIME                    DEFAULT NULL,
    PRIMARY KEY (app_id),
    KEY acc_id (acc_id),
    FOREIGN KEY (acc_id) REFERENCES USER_ACCOUNT(acc_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- ================================================================
-- 14. ADMIN_NOTIFICATIONS
-- ================================================================
CREATE TABLE ADMIN_NOTIFICATIONS (
    notif_id     INT         PRIMARY KEY AUTO_INCREMENT,
    type         VARCHAR(50) NOT NULL,
    message      TEXT        NOT NULL,
    is_read      TINYINT(1)  DEFAULT 0,
    reference_id INT         NULL,
    created_at   DATETIME    DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;
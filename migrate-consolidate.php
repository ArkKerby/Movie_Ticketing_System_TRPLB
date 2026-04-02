<?php
/**
 * TICKETIX Table Consolidation Migration
 * 19 tables → 15 tables
 * 
 * Run: php migrate-consolidate.php
 */

$conn = new mysqli('localhost', 'root', '', 'ticketix');
if ($conn->connect_error) die("Connection failed: " . $conn->connect_error);

echo "=== TICKETIX Table Consolidation Migration ===\n\n";

// ──────────────────────────────────────────────────────
// MERGE 1: PWD_APPLICATIONS + SENIOR_APPLICATIONS → DISCOUNT_APPLICATIONS
// ──────────────────────────────────────────────────────
echo "--- Merge 1: Creating DISCOUNT_APPLICATIONS ---\n";

$conn->query("
    CREATE TABLE IF NOT EXISTS DISCOUNT_APPLICATIONS (
        app_id INT NOT NULL AUTO_INCREMENT,
        acc_id INT NOT NULL,
        discount_type ENUM('pwd','senior') NOT NULL,
        id_number VARCHAR(100) NOT NULL,
        id_image VARCHAR(255) NOT NULL,
        status ENUM('pending','approved','rejected') DEFAULT 'pending',
        admin_notes TEXT,
        submitted_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        reviewed_at DATETIME DEFAULT NULL,
        PRIMARY KEY (app_id),
        KEY acc_id (acc_id),
        CONSTRAINT discount_applications_ibfk_1 FOREIGN KEY (acc_id) REFERENCES USER_ACCOUNT(acc_id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
");
echo "  Created DISCOUNT_APPLICATIONS table\n";

// Migrate PWD data
$conn->query("
    INSERT INTO DISCOUNT_APPLICATIONS (acc_id, discount_type, id_number, id_image, status, admin_notes, submitted_at, reviewed_at)
    SELECT acc_id, 'pwd', pwd_id_number, pwd_id_image, status, admin_notes, submitted_at, reviewed_at
    FROM PWD_APPLICATIONS
");
echo "  Migrated " . $conn->affected_rows . " PWD applications\n";

// Migrate Senior data
$conn->query("
    INSERT INTO DISCOUNT_APPLICATIONS (acc_id, discount_type, id_number, id_image, status, admin_notes, submitted_at, reviewed_at)
    SELECT acc_id, 'senior', senior_id_number, senior_id_image, status, admin_notes, submitted_at, reviewed_at
    FROM SENIOR_APPLICATIONS
");
echo "  Migrated " . $conn->affected_rows . " Senior applications\n";

// ──────────────────────────────────────────────────────
// MERGE 2: PAYMENT columns → TICKET table
// ──────────────────────────────────────────────────────
echo "\n--- Merge 2: Adding PAYMENT columns to TICKET ---\n";

// Add payment columns to TICKET
$cols = [
    "ADD COLUMN payment_type ENUM('cash','credit','e-wallet') DEFAULT NULL AFTER e_ticket_code",
    "ADD COLUMN amount_paid DECIMAL(10,2) DEFAULT NULL AFTER payment_type",
    "ADD COLUMN payment_status ENUM('paid','pending','not-yet','refunded') DEFAULT 'pending' AFTER amount_paid",
    "ADD COLUMN payment_date DATETIME DEFAULT NULL AFTER payment_status",
    "ADD COLUMN reference_number VARCHAR(100) DEFAULT NULL AFTER payment_date"
];

foreach ($cols as $col) {
    $conn->query("ALTER TABLE TICKET $col");
}
echo "  Added payment columns to TICKET\n";

// Migrate payment data into ticket
$conn->query("
    UPDATE TICKET t
    INNER JOIN PAYMENT p ON t.payment_id = p.payment_id
    SET t.payment_type = p.payment_type,
        t.amount_paid = p.amount_paid,
        t.payment_status = p.payment_status,
        t.payment_date = p.payment_date,
        t.reference_number = p.reference_number
");
echo "  Migrated " . $conn->affected_rows . " payment records into TICKET\n";

// Drop payment_id FK and column from TICKET
$conn->query("ALTER TABLE TICKET DROP FOREIGN KEY ticket_ibfk_1");
$conn->query("ALTER TABLE TICKET DROP COLUMN payment_id");
echo "  Removed payment_id FK from TICKET\n";

// ──────────────────────────────────────────────────────
// MERGE 3: SEAT into RESERVE_SEAT (store seat_number directly)
// ──────────────────────────────────────────────────────
echo "\n--- Merge 3: Adding seat_number to RESERVE_SEAT ---\n";

$conn->query("ALTER TABLE RESERVE_SEAT ADD COLUMN seat_number VARCHAR(10) DEFAULT NULL AFTER seat_id");
echo "  Added seat_number column\n";

// Copy seat_number from SEAT table
$conn->query("
    UPDATE RESERVE_SEAT rs
    INNER JOIN SEAT s ON rs.seat_id = s.seat_id
    SET rs.seat_number = s.seat_number
");
echo "  Migrated " . $conn->affected_rows . " seat numbers\n";

// Drop seat_id FK and column
$conn->query("ALTER TABLE RESERVE_SEAT DROP FOREIGN KEY reserve_seat_ibfk_2");
$conn->query("ALTER TABLE RESERVE_SEAT DROP COLUMN seat_id");
echo "  Removed seat_id FK from RESERVE_SEAT\n";

// ──────────────────────────────────────────────────────
// MERGE 4: Drop MALL_ADMIN_BRANCH (not used in code)
// ──────────────────────────────────────────────────────
echo "\n--- Merge 4: Dropping unused tables ---\n";

$conn->query("DROP TABLE IF EXISTS MALL_ADMIN_BRANCH");
echo "  Dropped MALL_ADMIN_BRANCH\n";

// ──────────────────────────────────────────────────────
// Drop old tables (after all data migrated)
// ──────────────────────────────────────────────────────
echo "\n--- Dropping old tables ---\n";

$conn->query("DROP TABLE IF EXISTS PWD_APPLICATIONS");
echo "  Dropped PWD_APPLICATIONS\n";

$conn->query("DROP TABLE IF EXISTS SENIOR_APPLICATIONS");
echo "  Dropped SENIOR_APPLICATIONS\n";

$conn->query("DROP TABLE IF EXISTS PAYMENT");
echo "  Dropped PAYMENT\n";

$conn->query("DROP TABLE IF EXISTS SEAT");
echo "  Dropped SEAT\n";

echo "\n=== Migration Complete! 19 → 15 tables ===\n";
echo "Tables removed: PWD_APPLICATIONS, SENIOR_APPLICATIONS, PAYMENT, SEAT, MALL_ADMIN_BRANCH\n";
echo "Tables added: DISCOUNT_APPLICATIONS\n";
echo "Tables modified: TICKET (added payment columns), RESERVE_SEAT (added seat_number)\n";

$conn->close();

-- =====================================================
-- Hotel Front Desk Management System - Database Schema
-- MySQL 5.7+ / MariaDB 10.2+
-- =====================================================

CREATE DATABASE IF NOT EXISTS hotel_pms CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE hotel_pms;

-- ---------------------------------------------------
-- 1. ROOM TYPES (Category Mapping)
-- ---------------------------------------------------
CREATE TABLE room_types (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,              -- e.g. Deluxe, Executive Suite
    description VARCHAR(255) DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- ---------------------------------------------------
-- 2. ROOMS (Inventory + individual pricing)
-- ---------------------------------------------------
CREATE TABLE rooms (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    room_number VARCHAR(20) NOT NULL UNIQUE,
    room_type_id INT UNSIGNED NOT NULL,
    price_per_day DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    floor VARCHAR(20) DEFAULT NULL,
    status ENUM('available','reserved','occupied') NOT NULL DEFAULT 'available',
    is_active TINYINT(1) NOT NULL DEFAULT 1,   -- soft disable a room (maintenance etc.)
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (room_type_id) REFERENCES room_types(id)
) ENGINE=InnoDB;

-- ---------------------------------------------------
-- 3. GUESTS (Reusable guest directory)
-- ---------------------------------------------------
CREATE TABLE guests (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    full_name VARCHAR(150) NOT NULL,
    phone VARCHAR(30) NOT NULL,
    email VARCHAR(150) DEFAULT NULL,
    id_proof_type VARCHAR(50) DEFAULT NULL,   -- e.g. NID, Passport
    id_proof_number VARCHAR(100) DEFAULT NULL,
    address VARCHAR(255) DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_phone (phone),
    INDEX idx_name (full_name)
) ENGINE=InnoDB;

-- ---------------------------------------------------
-- 4. BOOKINGS (Reservation -> Checkin -> Checkout lifecycle)
-- ---------------------------------------------------
CREATE TABLE bookings (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    room_id INT UNSIGNED NOT NULL,
    guest_id INT UNSIGNED NOT NULL,

    check_in_date DATE NOT NULL,             -- planned/actual arrival date
    check_out_date DATE NOT NULL,            -- planned/expected departure date
    actual_check_in_at DATETIME DEFAULT NULL,
    actual_check_out_at DATETIME DEFAULT NULL,

    price_per_day DECIMAL(10,2) NOT NULL,    -- snapshot of price at booking time
    total_days INT UNSIGNED NOT NULL DEFAULT 0,
    room_charge_total DECIMAL(10,2) NOT NULL DEFAULT 0.00,   -- days * price (current, incl. extensions)
    extension_charge_total DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    advance_paid DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    extra_paid DECIMAL(10,2) NOT NULL DEFAULT 0.00,          -- extension payments
    final_paid DECIMAL(10,2) NOT NULL DEFAULT 0.00,          -- settlement at checkout
    discount DECIMAL(10,2) NOT NULL DEFAULT 0.00,

    status ENUM('reserved','checked_in','checked_out','cancelled') NOT NULL DEFAULT 'reserved',

    notes VARCHAR(255) DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    FOREIGN KEY (room_id) REFERENCES rooms(id),
    FOREIGN KEY (guest_id) REFERENCES guests(id),
    INDEX idx_room_status (room_id, status)
) ENGINE=InnoDB;

-- ---------------------------------------------------
-- 5. PAYMENTS (ledger of every payment event)
-- ---------------------------------------------------
CREATE TABLE payments (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    booking_id INT UNSIGNED NOT NULL,
    payment_type ENUM('advance','extension','final','refund') NOT NULL,
    amount DECIMAL(10,2) NOT NULL,
    payment_method VARCHAR(50) DEFAULT 'cash',
    reference_note VARCHAR(255) DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (booking_id) REFERENCES bookings(id)
) ENGINE=InnoDB;

-- ---------------------------------------------------
-- 6. BOOKING EXTENSIONS (history of extend.php actions)
-- ---------------------------------------------------
CREATE TABLE booking_extensions (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    booking_id INT UNSIGNED NOT NULL,
    old_check_out_date DATE NOT NULL,
    new_check_out_date DATE NOT NULL,
    added_days INT NOT NULL,
    added_charge DECIMAL(10,2) NOT NULL,
    payment_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (booking_id) REFERENCES bookings(id)
) ENGINE=InnoDB;

-- =====================================================
-- SEED DATA
-- =====================================================

INSERT INTO room_types (name, description) VALUES
('Standard', 'Standard single/double room'),
('Deluxe', 'Deluxe room with extra amenities'),
('Executive Suite', 'Large suite with living area'),
('Family Room', 'Room suited for families');

INSERT INTO rooms (room_number, room_type_id, price_per_day, floor, status) VALUES
('101', 1, 80.00, '1', 'available'),
('102', 1, 80.00, '1', 'available'),
('103', 2, 120.00, '1', 'available'),
('104', 2, 120.00, '1', 'available'),
('201', 3, 220.00, '2', 'available'),
('202', 3, 220.00, '2', 'available'),
('203', 4, 150.00, '2', 'available'),
('204', 4, 150.00, '2', 'available');

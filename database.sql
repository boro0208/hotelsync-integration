CREATE DATABASE IF NOT EXISTS hotelsync_bridgeone;
USE hotelsync_bridgeone;

CREATE TABLE IF NOT EXISTS rooms (
    id INT AUTO_INCREMENT PRIMARY KEY,
    hs_room_id INT NOT NULL UNIQUE,
    room_name VARCHAR(255) NOT NULL,
    room_slug VARCHAR(255) NOT NULL,
    room_code VARCHAR(255) NOT NULL UNIQUE,
    payload_hash VARCHAR(64) NULL,
    raw_payload LONGTEXT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS rate_plans (
    id INT AUTO_INCREMENT PRIMARY KEY,
    hs_rate_plan_id INT NOT NULL UNIQUE,
    rate_plan_name VARCHAR(255) NOT NULL,
    meal_plan VARCHAR(100) NOT NULL DEFAULT 'no-meal',
    rate_plan_code VARCHAR(255) NOT NULL UNIQUE,
    payload_hash VARCHAR(64) NULL,
    raw_payload LONGTEXT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

CREATE TABLE reservations (
    id INT AUTO_INCREMENT PRIMARY KEY,
    hs_reservation_id INT NOT NULL UNIQUE,
    external_reservation_code VARCHAR(255) NULL,
    guest_name VARCHAR(255) NOT NULL,
    guest_email VARCHAR(255) NULL,
    arrival_date DATE NOT NULL,
    departure_date DATE NOT NULL,
    status VARCHAR(50) NOT NULL,
    total_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    currency VARCHAR(10) NOT NULL,
    lock_id VARCHAR(255) NOT NULL,
    payload_hash VARCHAR(64) NOT NULL,
    raw_payload LONGTEXT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

CREATE TABLE reservation_rooms (
    id INT AUTO_INCREMENT PRIMARY KEY,
    reservation_id INT NOT NULL,
    room_id INT NOT NULL,
    quantity INT NOT NULL DEFAULT 1,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_reservation_room (reservation_id, room_id),
    CONSTRAINT fk_reservation_rooms_reservation
        FOREIGN KEY (reservation_id) REFERENCES reservations(id)
        ON DELETE CASCADE,
    CONSTRAINT fk_reservation_rooms_room
        FOREIGN KEY (room_id) REFERENCES rooms(id)
        ON DELETE CASCADE
);

CREATE TABLE reservation_rate_plans (
    id INT AUTO_INCREMENT PRIMARY KEY,
    reservation_id INT NOT NULL,
    rate_plan_id INT NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_reservation_rate_plan (reservation_id, rate_plan_id),
    CONSTRAINT fk_reservation_rate_plans_reservation
        FOREIGN KEY (reservation_id) REFERENCES reservations(id)
        ON DELETE CASCADE,
    CONSTRAINT fk_reservation_rate_plans_rate_plan
        FOREIGN KEY (rate_plan_id) REFERENCES rate_plans(id)
        ON DELETE CASCADE
);
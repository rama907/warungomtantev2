-- Skema database (tanpa akun/password bawaan).
-- Buat akun admin pertama secara manual, contoh hash:  php -r "echo password_hash('GANTI_DENGAN_PASSWORD_KUAT', PASSWORD_DEFAULT);"
-- Database structure for Warung Om Tante Management System

CREATE DATABASE IF NOT EXISTS warung_om_tante;
USE warung_om_tante;

-- Employees table
CREATE TABLE employees (
    id INT PRIMARY KEY AUTO_INCREMENT,
    name VARCHAR(100) NOT NULL,
    role ENUM('direktur', 'wakil_direktur', 'manager', 'chef', 'karyawan', 'magang') NOT NULL,
    password VARCHAR(255) NOT NULL,
    status ENUM('active', 'inactive') DEFAULT 'active',
    is_on_duty BOOLEAN DEFAULT FALSE,
    current_duty_start DATETIME NULL,
    total_duty_hours INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- Duty logs table
CREATE TABLE duty_logs (
    id INT PRIMARY KEY AUTO_INCREMENT,
    employee_id INT NOT NULL,
    duty_start DATETIME NOT NULL,
    duty_end DATETIME NULL,
    duration_minutes INT DEFAULT 0,
    is_manual BOOLEAN DEFAULT FALSE,
    approved_by INT NULL,
    status ENUM('active', 'completed', 'pending_approval') DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (employee_id) REFERENCES employees(id),
    FOREIGN KEY (approved_by) REFERENCES employees(id)
);

-- Sales data table
CREATE TABLE sales_data (
    id INT PRIMARY KEY AUTO_INCREMENT,
    employee_id INT NOT NULL,
    paket_makan_minum INT DEFAULT 0,
    paket_snack INT DEFAULT 0,
    masak_paket INT DEFAULT 0,
    masak_snack INT DEFAULT 0,
    date DATE NOT NULL,
    week_number INT NOT NULL,
    year INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (employee_id) REFERENCES employees(id)
);

-- Leave requests table
CREATE TABLE leave_requests (
    id INT PRIMARY KEY AUTO_INCREMENT,
    employee_id INT NOT NULL,
    start_date DATE NOT NULL,
    end_date DATE NOT NULL,
    reason_ooc TEXT,
    reason_ic TEXT,
    status ENUM('pending', 'approved', 'rejected') DEFAULT 'pending',
    approved_by INT NULL,
    approved_at DATETIME NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (employee_id) REFERENCES employees(id),
    FOREIGN KEY (approved_by) REFERENCES employees(id)
);

-- Resignation requests table
CREATE TABLE resignation_requests (
    id INT PRIMARY KEY AUTO_INCREMENT,
    employee_id INT NOT NULL,
    start_date DATE NOT NULL,
    end_date DATE NOT NULL,
    reason_ooc TEXT,
    reason_ic TEXT,
    passport VARCHAR(50),
    cid VARCHAR(50),
    status ENUM('pending', 'approved', 'rejected') DEFAULT 'pending',
    approved_by INT NULL,
    approved_at DATETIME NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (employee_id) REFERENCES employees(id),
    FOREIGN KEY (approved_by) REFERENCES employees(id)
);

-- Manual duty requests table
CREATE TABLE manual_duty_requests (
    id INT PRIMARY KEY AUTO_INCREMENT,
    employee_id INT NOT NULL,
    duty_date DATE NOT NULL,
    start_time TIME NOT NULL,
    end_time TIME NOT NULL,
    reason TEXT,
    status ENUM('pending', 'approved', 'rejected') DEFAULT 'pending',
    approved_by INT NULL,
    approved_at DATETIME NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (employee_id) REFERENCES employees(id),
    FOREIGN KEY (approved_by) REFERENCES employees(id)
);

-- System settings table
CREATE TABLE system_settings (
    id INT PRIMARY KEY AUTO_INCREMENT,
    setting_key VARCHAR(100) NOT NULL UNIQUE,
    setting_value TEXT,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- Insert default admin users

-- Insert default system settings
INSERT INTO system_settings (setting_key, setting_value) VALUES 
('last_weekly_reset', CURDATE()),
('discord_webhook_url', ''),
('system_timezone', 'Asia/Jakarta');

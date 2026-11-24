CREATE DATABASE IF NOT EXISTS db_taxguard;
USE db_taxguard;

-- 1. Tabel Pengguna (RBAC)
CREATE TABLE users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    full_name VARCHAR(100) NOT NULL,
    role ENUM('admin', 'tax_staff', 'auditor') NOT NULL DEFAULT 'tax_staff',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- 2. Tabel Batch Unggahan CSV
CREATE TABLE upload_batches (
    id INT AUTO_INCREMENT PRIMARY KEY,
    batch_code VARCHAR(30) NOT NULL UNIQUE,
    source_type ENUM('INTERNAL', 'CORETAX') NOT NULL,
    file_name VARCHAR(150) NOT NULL,
    uploaded_by INT NOT NULL,
    uploaded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (uploaded_by) REFERENCES users(id)
);

-- 3. Tabel Faktur Pajak (Internal & Coretax)
CREATE TABLE tax_invoices (
    id INT AUTO_INCREMENT PRIMARY KEY,
    batch_id INT NOT NULL,
    source_type ENUM('INTERNAL', 'CORETAX') NOT NULL,
    nomor_faktur VARCHAR(30) NOT NULL,
    tanggal_faktur DATE NOT NULL,
    npwp_lawan VARCHAR(20) NOT NULL,
    nama_lawan VARCHAR(150) NOT NULL,
    dpp DECIMAL(15,2) NOT NULL,
    ppn DECIMAL(15,2) NOT NULL,
    recon_status ENUM('PENDING', 'MATCHED', 'AMOUNT_MISMATCH', 'MISSING_IN_CORETAX', 'MISSING_IN_INTERNAL') DEFAULT 'PENDING',
    fraud_score INT DEFAULT 0,
    fraud_flags TEXT NULL,
    FOREIGN KEY (batch_id) REFERENCES upload_batches(id) ON DELETE CASCADE
);

-- 4. Tabel Kertas Kerja Investigasi Auditor
CREATE TABLE investigation_notes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    invoice_id INT NOT NULL,
    auditor_id INT NOT NULL,
    status ENUM('OPEN', 'CLEARED', 'CONFIRMED_FRAUD') DEFAULT 'OPEN',
    findings TEXT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (invoice_id) REFERENCES tax_invoices(id) ON DELETE CASCADE,
    FOREIGN KEY (auditor_id) REFERENCES users(id)
);

-- 5. Tabel Jejak Digital (Digital Forensics Log)
CREATE TABLE audit_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NULL,
    action VARCHAR(100) NOT NULL,
    target_table VARCHAR(50) NULL,
    target_id INT NULL,
    details TEXT NULL,
    ip_address VARCHAR(45) NOT NULL,
    user_agent VARCHAR(255) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Seed Akun Default (Password semua akun: password123)
INSERT INTO users (username, password_hash, full_name, role) VALUES
('admin', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Administrator Sistem', 'admin'),
('staff_pajak', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Khanif Adnan, S.Kom., CTM.', 'tax_staff'),
('auditor_cpfi', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Lead Fraud Investigator, CPFI.', 'auditor');

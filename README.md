# 🛡️ TaxGuard — VAT Reconciliation & Fraud Anomaly Detection System

Sistem informasi berbasis web untuk melakukan rekonsiliasi otomatis antara pembukuan pajak internal perusahaan dengan data keluaran **Coretax (e-Faktur)**, serta mendeteksi indikator kecurangan (*Red Flags*) menggunakan pendekatan investigasi fraud.

## 🎯 Latar Belakang & Relevansi Bisnis
Perusahaan sering menghadapi risiko selisih pencatatan PPN dan potensi faktur pajak fiktif yang dapat memicu sanksi administrasi maupun temuan pemeriksaan pajak. **TaxGuard** mengotomatiskan proses *two-way matching* dan memberikan skor risiko kecurangan pada setiap transaksi.

## ✨ Fitur Utama
- **Two-Way VAT Reconciliation:** Pencocokan otomatis Nomor Faktur, NPWP 16 Digit, DPP, dan PPN antara data *Internal Ledger* dan *Coretax*.
- **Rule-Based Fraud Detection Engine:**
  - `RF-01` **Duplicate Invoice:** Deteksi nomor faktur ganda.
  - `RF-02` **Tax Rate Mismatch:** Validasi rasio PPN terhadap DPP.
  - `RF-03` **Invalid NPWP:** Validasi format 16 digit NPWP lawan transaksi.
  - `RF-04` **Round-Number Anomaly:** Deteksi transaksi bernilai bulat besar di atas Rp50.000.000.
- **Digital Forensics Audit Trail:** Pencatatan log aktivitas sistem secara *immutable* (IP Address, User Agent, waktu, dan perubahan data).
- **Role-Based Access Control (RBAC):** Pemisahan hak akses antara *Tax Staff*, *Internal Auditor*, dan *Admin*.

## 🛠️ Teknologi yang Digunakan
- **Backend:** PHP 8+ (Native / PDO Prepared Statements)
- **Database:** MySQL / MariaDB
- **Frontend:** HTML5, CSS3, Bootstrap 5

<?php
session_start();
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/fraud_engine.php';
require_once __DIR__ . '/includes/audit_logger.php';

// Simulasi Sesi RBAC (Default: Tax Staff, bisa switch ke Auditor untuk demo portofolio)
if (isset($_GET['switch_role'])) {
    $roleMap = [
        'tax_staff' => ['id' => 2, 'name' => 'Khanif Adnan, S.Kom., CTM.', 'role' => 'tax_staff'],
        'auditor'   => ['id' => 3, 'name' => 'Lead Fraud Investigator, CPFI.', 'role' => 'auditor']
    ];
    if (isset($roleMap[$_GET['switch_role']])) {
        $_SESSION['user'] = $roleMap[$_GET['switch_role']];
        logAuditTrail($pdo, $_SESSION['user']['id'], 'SWITCH_ROLE', 'users', $_SESSION['user']['id'], 'Beralih peran ke ' . $_SESSION['user']['role']);
    }
    header("Location: index.php");
    exit;
}

if (!isset($_SESSION['user'])) {
    $_SESSION['user'] = ['id' => 2, 'name' => 'Khanif Adnan, S.Kom., CTM.', 'role' => 'tax_staff'];
}
$currentUser = $_SESSION['user'];
$alertMsg = '';
$alertType = 'info';

// 1. PROSES UPLOAD CSV (INTERNAL LEDGER / CORETAX E-FAKTUR)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'upload_csv') {
    $sourceType = $_POST['source_type'] === 'CORETAX' ? 'CORETAX' : 'INTERNAL';

    if (isset($_FILES['csv_file']) && $_FILES['csv_file']['error'] === UPLOAD_ERR_OK) {
        $fileName = basename($_FILES['csv_file']['name']);
        $tmpPath  = $_FILES['csv_file']['tmp_name'];

        $pdo->beginTransaction();
        try {
            $batchCode = 'BATCH-' . $sourceType . '-' . date('YmdHis');
            $stmtBatch = $pdo->prepare("INSERT INTO upload_batches (batch_code, source_type, file_name, uploaded_by) VALUES (?, ?, ?, ?)");
            $stmtBatch->execute([$batchCode, $sourceType, $fileName, $currentUser['id']]);
            $batchId = (int) $pdo->lastInsertId();

            $handle = fopen($tmpPath, 'r');
            $header = fgetcsv($handle); // Lewati baris header CSV

            $seenInvoices = [];
            $insertedCount = 0;

            $stmtInv = $pdo->prepare("
                INSERT INTO tax_invoices (batch_id, source_type, nomor_faktur, tanggal_faktur, npwp_lawan, nama_lawan, dpp, ppn, fraud_score, fraud_flags)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");

            while (($row = fgetcsv($handle)) !== false) {
                if (count($row) < 6) continue;
                $invData = [
                    'nomor_faktur'   => trim($row[0]),
                    'tanggal_faktur' => trim($row[1]),
                    'npwp_lawan'     => trim($row[2]),
                    'nama_lawan'     => trim($row[3]),
                    'dpp'            => (float) $row[4],
                    'ppn'            => (float) $row[5]
                ];

                // Jalankan Fraud Detection Engine
                $fraudResult = analyzeInvoiceFraud($invData, $seenInvoices);
                $seenInvoices[] = $invData['nomor_faktur'];

                $stmtInv->execute([
                    $batchId,
                    $sourceType,
                    $invData['nomor_faktur'],
                    $invData['tanggal_faktur'],
                    $invData['npwp_lawan'],
                    $invData['nama_lawan'],
                    $invData['dpp'],
                    $invData['ppn'],
                    $fraudResult['score'],
                    $fraudResult['flags']
                ]);
                $insertedCount++;
            }
            fclose($handle);

            logAuditTrail($pdo, $currentUser['id'], 'UPLOAD_CSV_' . $sourceType, 'upload_batches', $batchId, "Mengunggah $insertedCount faktur dari file $fileName ($batchCode)");
            $pdo->commit();

            $alertType = 'success';
            $alertMsg = "Berhasil mengunggah <strong>$insertedCount faktur</strong> ($sourceType) dan menjalankan pemindaian Red-Flag otomatis.";
        } catch (Exception $e) {
            $pdo->rollBack();
            $alertType = 'danger';
            $alertMsg = "Gagal memproses CSV: " . htmlspecialchars($e->getMessage());
        }
    }
}

// 2. PROSES REKONSILIASI DUA ARAH (INTERNAL VS CORETAX)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'run_reconcile') {
    $internalList = $pdo->query("SELECT * FROM tax_invoices WHERE source_type = 'INTERNAL'")->fetchAll();
    $coretaxList  = $pdo->query("SELECT * FROM tax_invoices WHERE source_type = 'CORETAX'")->fetchAll();

    // Index data Coretax berdasarkan nomor faktur
    $coretaxMap = [];
    foreach ($coretaxList as $ct) {
        $coretaxMap[$ct['nomor_faktur']] = $ct;
    }

    $internalMap = [];
    foreach ($internalList as $in) {
        $internalMap[$in['nomor_faktur']] = $in;
    }

    $updateStmt = $pdo->prepare("UPDATE tax_invoices SET recon_status = ? WHERE id = ?");

    // Cek Internal terhadap Coretax
    foreach ($internalList as $in) {
        $no = $in['nomor_faktur'];
        if (!isset($coretaxMap[$no])) {
            $updateStmt->execute(['MISSING_IN_CORETAX', $in['id']]);
        } else {
            $ct = $coretaxMap[$no];
            if (abs((float)$in['dpp'] - (float)$ct['dpp']) <= 1 && abs((float)$in['ppn'] - (float)$ct['ppn']) <= 1) {
                $updateStmt->execute(['MATCHED', $in['id']]);
            } else {
                $updateStmt->execute(['AMOUNT_MISMATCH', $in['id']]);
            }
        }
    }

    // Cek Coretax terhadap Internal
    foreach ($coretaxList as $ct) {
        $no = $ct['nomor_faktur'];
        if (!isset($internalMap[$no])) {
            $updateStmt->execute(['MISSING_IN_INTERNAL', $ct['id']]);
        } else {
            $in = $internalMap[$no];
            if (abs((float)$in['dpp'] - (float)$ct['dpp']) <= 1 && abs((float)$in['ppn'] - (float)$ct['ppn']) <= 1) {
                $updateStmt->execute(['MATCHED', $ct['id']]);
            } else {
                $updateStmt->execute(['AMOUNT_MISMATCH', $ct['id']]);
            }
        }
    }

    logAuditTrail($pdo, $currentUser['id'], 'RUN_VAT_RECONCILIATION', 'tax_invoices', null, 'Menjalankan rekonsiliasi otomatis dua arah antara Internal Ledger dan Coretax');
    $alertType = 'primary';
    $alertMsg = "Proses <strong>Two-Way VAT Reconciliation</strong> selesai dijalankan.";
}

// 3. PROSES SIMPAN CATATAN INVESTIGASI AUDITOR (CPFI)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_investigation') {
    if ($currentUser['role'] !== 'auditor') {
        $alertType = 'danger';
        $alertMsg = "Akses ditolak: Hanya peran Auditor (CPFI) yang dapat memfinalisasi kertas kerja investigasi.";
    } else {
        $invoiceId = (int) $_POST['invoice_id'];
        $status    = $_POST['inv_status'];
        $findings  = trim($_POST['findings']);

        $stmt = $pdo->prepare("INSERT INTO investigation_notes (invoice_id, auditor_id, status, findings) VALUES (?, ?, ?, ?)");
        $stmt->execute([$invoiceId, $currentUser['id'], $status, $findings]);

        logAuditTrail($pdo, $currentUser['id'], 'AUDIT_FINDING_' . $status, 'tax_invoices', $invoiceId, "Auditor mencatat hasil investigasi: $findings");
        $alertType = 'success';
        $alertMsg = "Kertas kerja investigasi berhasil disimpan dan tercatat di Digital Forensics Audit Log.";
    }
}

// 4. RESET DATA DEMO
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'reset_demo') {
    $pdo->exec("DELETE FROM investigation_notes");
    $pdo->exec("DELETE FROM tax_invoices");
    $pdo->exec("DELETE FROM upload_batches");
    logAuditTrail($pdo, $currentUser['id'], 'RESET_DEMO_DATA', 'tax_invoices', null, 'Membersihkan dataset simulasi transaksi');
    $alertType = 'warning';
    $alertMsg = "Dataset transaksi berhasil direset. Silakan unggah ulang file dari folder sample_data/.";
}

// QUERY RINGKASAN KPI & DATA TABEL
$totalInvoices = (int) $pdo->query("SELECT COUNT(*) FROM tax_invoices")->fetchColumn();
$matchedCount  = (int) $pdo->query("SELECT COUNT(*) FROM tax_invoices WHERE recon_status = 'MATCHED'")->fetchColumn();
$mismatchCount = (int) $pdo->query("SELECT COUNT(*) FROM tax_invoices WHERE recon_status IN ('AMOUNT_MISMATCH', 'MISSING_IN_CORETAX', 'MISSING_IN_INTERNAL')")->fetchColumn();
$highRiskCount = (int) $pdo->query("SELECT COUNT(*) FROM tax_invoices WHERE fraud_score >= 30")->fetchColumn();

$invoices = $pdo->query("
    SELECT t.*, 
           (SELECT status FROM investigation_notes WHERE invoice_id = t.id ORDER BY id DESC LIMIT 1) AS audit_status,
           (SELECT findings FROM investigation_notes WHERE invoice_id = t.id ORDER BY id DESC LIMIT 1) AS audit_findings
    FROM tax_invoices t 
    ORDER BY t.fraud_score DESC, t.id ASC
")->fetchAll();

$auditLogs = $pdo->query("
    SELECT a.*, u.full_name, u.role 
    FROM audit_logs a 
    LEFT JOIN users u ON a.user_id = u.id 
    ORDER BY a.id DESC LIMIT 25
")->fetchAll();
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>TaxGuard — VAT Reconciliation & Fraud Anomaly Detection</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        body { background-color: #f4f6f9; font-size: 0.92rem; }
        .navbar-brand { font-weight: 700; letter-spacing: 0.5px; }
        .kpi-card { border: none; border-radius: 10px; box-shadow: 0 2px 8px rgba(0,0,0,0.05); }
        .badge-recon { font-size: 0.75rem; padding: 5px 8px; }
        .table th { background-color: #212529; color: #fff; font-weight: 500; white-space: nowrap; }
    </style>
</head>
<body>

<nav class="navbar navbar-expand-lg navbar-dark bg-dark shadow-sm mb-4">
    <div class="container-fluid px-4">
        <a class="navbar-brand" href="index.php">
            <i class="bi bi-shield-lock-fill text-warning me-2"></i>TaxGuard <span class="badge bg-warning text-dark fs-6 ms-1">Coretax & CPFI</span>
        </a>
        <div class="d-flex align-items-center gap-2">
            <span class="text-light small me-2 d-none d-md-inline">
                <i class="bi bi-person-badge me-1"></i><?= htmlspecialchars($currentUser['name']) ?>
                <span class="badge bg-info text-dark text-uppercase ms-1"><?= $currentUser['role'] ?></span>
            </span>
            <div class="btn-group btn-group-sm">
                <a href="?switch_role=tax_staff" class="btn <?= $currentUser['role'] === 'tax_staff' ? 'btn-primary' : 'btn-outline-light' ?>">Mode Tax Staff</a>
                <a href="?switch_role=auditor" class="btn <?= $currentUser['role'] === 'auditor' ? 'btn-danger' : 'btn-outline-light' ?>">Mode Auditor (CPFI)</a>
            </div>
        </div>
    </div>
</nav>

<div class="container-fluid px-4 pb-5">

    <?php if ($alertMsg): ?>
        <div class="alert alert-<?= $alertType ?> alert-dismissible fade show shadow-sm" role="alert">
            <?= $alertMsg ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <!-- KPI SUMMARY CARDS -->
    <div class="row g-3 mb-4">
        <div class="col-md-3">
            <div class="card kpi-card p-3 border-start border-primary border-4">
                <div class="text-muted small fw-bold">TOTAL FAKTUR TERUNGGAH</div>
                <div class="fs-3 fw-bold text-dark mt-1"><?= $totalInvoices ?> <span class="fs-6 fw-normal text-muted">dokumen</span></div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card kpi-card p-3 border-start border-success border-4">
                <div class="text-muted small fw-bold">REKONSILIASI COCOK (MATCHED)</div>
                <div class="fs-3 fw-bold text-success mt-1"><?= $matchedCount ?> <span class="fs-6 fw-normal text-muted">dokumen</span></div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card kpi-card p-3 border-start border-warning border-4">
                <div class="text-muted small fw-bold">SELISIH / TIDAK DITEMUKAN</div>
                <div class="fs-3 fw-bold text-warning mt-1"><?= $mismatchCount ?> <span class="fs-6 fw-normal text-muted">dokumen</span></div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card kpi-card p-3 border-start border-danger border-4">
                <div class="text-muted small fw-bold">INDIKASI RED FLAG (FRAUD SCORE ≥ 30)</div>
                <div class="fs-3 fw-bold text-danger mt-1"><?= $highRiskCount ?> <span class="fs-6 fw-normal text-muted">anomali</span></div>
            </div>
        </div>
    </div>

    <!-- PANEL UPLOAD & AKSI REKONSILIASI -->
    <div class="card shadow-sm border-0 mb-4">
        <div class="card-body">
            <div class="row g-3 align-items-end">
                <div class="col-md-4">
                    <form method="POST" enctype="multipart/form-data">
                        <input type="hidden" name="action" value="upload_csv">
                        <input type="hidden" name="source_type" value="INTERNAL">
                        <label class="form-label fw-bold text-primary"><i class="bi bi-file-earmark-spreadsheet me-1"></i>1. Unggah Pembukuan Internal (CSV)</label>
                        <div class="input-group input-group-sm">
                            <input type="file" name="csv_file" accept=".csv" class="form-control" required>
                            <button type="submit" class="btn btn-primary">Upload Internal</button>
                        </div>
                    </form>
                </div>
                <div class="col-md-4">
                    <form method="POST" enctype="multipart/form-data">
                        <input type="hidden" name="action" value="upload_csv">
                        <input type="hidden" name="source_type" value="CORETAX">
                        <label class="form-label fw-bold text-success"><i class="bi bi-cloud-arrow-up me-1"></i>2. Unggah Data Coretax e-Faktur (CSV)</label>
                        <div class="input-group input-group-sm">
                            <input type="file" name="csv_file" accept=".csv" class="form-control" required>
                            <button type="submit" class="btn btn-success">Upload Coretax</button>
                        </div>
                    </form>
                </div>
                <div class="col-md-4 d-flex gap-2 justify-content-md-end">
                    <form method="POST">
                        <input type="hidden" name="action" value="run_reconcile">
                        <button type="submit" class="btn btn-dark btn-sm px-3">
                            <i class="bi bi-arrow-left-right me-1"></i>3. Jalankan Rekonsiliasi PPN
                        </button>
                    </form>
                    <form method="POST" onsubmit="return confirm('Reset semua data simulasi?');">
                        <input type="hidden" name="action" value="reset_demo">
                        <button type="submit" class="btn btn-outline-secondary btn-sm">Reset</button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <!-- NAVIGASI TAB UTAMA -->
    <ul class="nav nav-tabs mb-3" id="mainTab" role="tablist">
        <li class="nav-item">
            <button class="nav-link active fw-bold" data-bs-toggle="tab" data-bs-target="#tab-invoices" type="button">
                <i class="bi bi-table me-1"></i>Hasil Rekonsiliasi & Deteksi Fraud (<?= $totalInvoices ?>)
            </button>
        </li>
        <li class="nav-item">
            <button class="nav-link fw-bold text-danger" data-bs-toggle="tab" data-bs-target="#tab-forensics" type="button">
                <i class="bi bi-fingerprint me-1"></i>Digital Forensics Audit Trail (<?= count($auditLogs) ?>)
            </button>
        </li>
    </ul>

    <div class="tab-content">
        <!-- TAB 1: TABEL FAKTUR & INVESTIGASI -->
        <div class="tab-pane fade show active" id="tab-invoices">
            <div class="card shadow-sm border-0">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead>
                            <tr>
                                <th>Sumber</th>
                                <th>Nomor Faktur</th>
                                <th>Tanggal</th>
                                <th>NPWP & Lawan Transaksi</th>
                                <th class="text-end">DPP (Rp)</th>
                                <th class="text-end">PPN (Rp)</th>
                                <th>Status Rekonsiliasi</th>
                                <th>Fraud Risk & Red Flags</th>
                                <th>Tindakan Auditor</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($invoices)): ?>
                                <tr>
                                    <td colspan="9" class="text-center py-4 text-muted">
                                        Belum ada data faktur. Silakan unggah file <code>sample_data/internal_ledger.csv</code> dan <code>sample_data/coretax_efaktur.csv</code> di atas.
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($invoices as $row): ?>
                                    <?php
                                        $reconBadge = match($row['recon_status']) {
                                            'MATCHED'             => 'bg-success',
                                            'AMOUNT_MISMATCH'     => 'bg-warning text-dark',
                                            'MISSING_IN_CORETAX',
                                            'MISSING_IN_INTERNAL' => 'bg-danger',
                                            default               => 'bg-secondary'
                                        };
                                        $scoreBadge = $row['fraud_score'] >= 40 ? 'bg-danger' : ($row['fraud_score'] > 0 ? 'bg-warning text-dark' : 'bg-light text-dark border');
                                    ?>
                                    <tr>
                                        <td><span class="badge <?= $row['source_type'] === 'CORETAX' ? 'bg-info text-dark' : 'bg-primary' ?>"><?= $row['source_type'] ?></span></td>
                                        <td class="fw-semibold font-monospace"><?= htmlspecialchars($row['nomor_faktur']) ?></td>
                                        <td><?= htmlspecialchars($row['tanggal_faktur']) ?></td>
                                        <td>
                                            <div class="fw-semibold"><?= htmlspecialchars($row['nama_lawan']) ?></div>
                                            <small class="text-muted font-monospace">NPWP: <?= htmlspecialchars($row['npwp_lawan']) ?></small>
                                        </td>
                                        <td class="text-end font-monospace"><?= number_format((float)$row['dpp'], 0, ',', '.') ?></td>
                                        <td class="text-end font-monospace"><?= number_format((float)$row['ppn'], 0, ',', '.') ?></td>
                                        <td><span class="badge badge-recon <?= $reconBadge ?>"><?= $row['recon_status'] ?></span></td>
                                        <td style="max-width: 280px;">
                                            <span class="badge <?= $scoreBadge ?> mb-1">Skor Risiko: <?= $row['fraud_score'] ?>/100</span>
                                            <?php if ($row['fraud_flags']): ?>
                                                <div class="small text-danger fw-semibold"><?= htmlspecialchars($row['fraud_flags']) ?></div>
                                            <?php else: ?>
                                                <div class="small text-muted">Tidak ada anomali terdeteksi</div>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ($row['audit_status']): ?>
                                                <span class="badge <?= $row['audit_status'] === 'CONFIRMED_FRAUD' ? 'bg-danger' : 'bg-success' ?> d-block mb-1">
                                                    <?= $row['audit_status'] ?>
                                                </span>
                                                <small class="text-muted d-block"><?= htmlspecialchars($row['audit_findings']) ?></small>
                                            <?php endif; ?>

                                            <?php if ($currentUser['role'] === 'auditor'): ?>
                                                <button class="btn btn-outline-dark btn-sm mt-1" data-bs-toggle="modal" data-bs-target="#auditModal<?= $row['id'] ?>">
                                                    <i class="bi bi-search"></i> Periksa
                                                </button>

                                                <!-- Modal Investigasi Auditor -->
                                                <div class="modal fade" id="auditModal<?= $row['id'] ?>" tabindex="-1">
                                                    <div class="modal-dialog">
                                                        <form method="POST" class="modal-content">
                                                            <div class="modal-header">
                                                                <h5 class="modal-title">Kertas Kerja Investigasi — <?= htmlspecialchars($row['nomor_faktur']) ?></h5>
                                                                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                                            </div>
                                                            <div class="modal-body">
                                                                <input type="hidden" name="action" value="save_investigation">
                                                                <input type="hidden" name="invoice_id" value="<?= $row['id'] ?>">
                                                                <div class="mb-3">
                                                                    <label class="form-label fw-bold">Kesimpulan Pemeriksaan (CPFI)</label>
                                                                    <select name="inv_status" class="form-select" required>
                                                                        <option value="CLEARED">CLEARED (Wajar / Human Error Terverifikasi)</option>
                                                                        <option value="CONFIRMED_FRAUD">CONFIRMED FRAUD (Indikasi Faktur Fiktif / Penyimpangan)</option>
                                                                    </select>
                                                                </div>
                                                                <div class="mb-3">
                                                                    <label class="form-label fw-bold">Catatan Bukti Audit</label>
                                                                    <textarea name="findings" class="form-control" rows="3" placeholder="Tuliskan hasil konfirmasi lawan transaksi, dokumen PO/Surat Jalan, atau analisis anomali..." required></textarea>
                                                                </div>
                                                            </div>
                                                            <div class="modal-footer">
                                                                <button type="submit" class="btn btn-danger">Simpan Kertas Kerja</button>
                                                            </div>
                                                        </form>
                                                    </div>
                                                </div>
                                            <?php else: ?>
                                                <span class="text-muted small fst-italic">Ganti ke Mode Auditor</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- TAB 2: DIGITAL FORENSICS AUDIT LOG -->
        <div class="tab-pane fade" id="tab-forensics">
            <div class="card shadow-sm border-0">
                <div class="card-header bg-dark text-white d-flex justify-content-between align-items-center">
                    <span><i class="bi bi-shield-check me-2 text-warning"></i>Immutable System Activity & Digital Forensics Log</span>
                    <span class="badge bg-secondary">Read-Only Trail</span>
                </div>
                <div class="table-responsive">
                    <table class="table table-sm table-striped align-middle mb-0">
                        <thead>
                            <tr>
                                <th>Waktu (Timestamp)</th>
                                <th>Aktor Pengguna</th>
                                <th>Aksi Sistem</th>
                                <th>Detail Aktivitas</th>
                                <th>IP Address</th>
                                <th>User Agent</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($auditLogs as $log): ?>
                                <tr>
                                    <td class="font-monospace small"><?= htmlspecialchars($log['created_at']) ?></td>
                                    <td>
                                        <span class="fw-semibold"><?= htmlspecialchars($log['full_name'] ?? 'System') ?></span>
                                        <span class="badge bg-secondary ms-1"><?= htmlspecialchars($log['role'] ?? '-') ?></span>
                                    </td>
                                    <td><span class="badge bg-dark font-monospace"><?= htmlspecialchars($log['action']) ?></span></td>
                                    <td class="small"><?= htmlspecialchars($log['details'] ?? '-') ?></td>
                                    <td class="font-monospace small"><?= htmlspecialchars($log['ip_address']) ?></td>
                                    <td class="small text-muted text-truncate" style="max-width: 200px;"><?= htmlspecialchars($log['user_agent']) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>

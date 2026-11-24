<?php
/**
 * Engine Deteksi Anomali & Red Flag Faktur Pajak
 * Mengacu pada standar analisis kepatuhan pajak & investigasi fraud
 */
function analyzeInvoiceFraud(array $invoice, array $existingInvoices): array
{
    $score = 0;
    $flags = [];

    $nomorFaktur = trim($invoice['nomor_faktur']);
    $npwp        = preg_replace('/[^0-9]/', '', $invoice['npwp_lawan']);
    $dpp         = (float) $invoice['dpp'];
    $ppn         = (float) $invoice['ppn'];

    // RF-01: Cek Duplikasi Nomor Faktur di sumber data yang sama
    if (in_array($nomorFaktur, $existingInvoices)) {
        $score += 50;
        $flags[] = "[RF-01] Duplikasi Nomor Faktur terdeteksi.";
    }

    // RF-02: Cek Kesesuaian Tarif PPN (Toleransi pembulatan Rp 100, skema 11% atau 12%)
    $expectedPpn11 = $dpp * 0.11;
    $expectedPpn12 = $dpp * 0.12;
    if (abs($ppn - $expectedPpn11) > 100 && abs($ppn - $expectedPpn12) > 100) {
        $score += 30;
        $flags[] = "[RF-02] Selisih tarif PPN tidak wajar terhadap DPP.";
    }

    // RF-03: Validasi Format NPWP 16 Digit & Pola Tidak Wajar
    if (strlen($npwp) !== 16 || preg_match('/^(\d)\1{15}$/', $npwp)) {
        $score += 40;
        $flags[] = "[RF-03] Format NPWP 16 digit tidak valid atau terindikasi fiktif.";
    }

    // RF-04: Anomali Angka Bulat Sempurna di atas Rp 50.000.000
    if ($dpp >= 50000000 && fmod($dpp, 1000000) == 0.0) {
        $score += 20;
        $flags[] = "[RF-04] Transaksi bernilai bulat sempurna >= Rp 50 Juta (Perlu verifikasi kontrak).";
    }

    return [
        'score' => min($score, 100),
        'flags' => empty($flags) ? null : implode(" | ", $flags)
    ];
}

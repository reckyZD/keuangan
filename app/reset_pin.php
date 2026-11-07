<?php

/* =====================================================================
 *  PIN reset gaji (tersimpan sebagai hash di tabel pengaturan, kunci reset_pin_hash)
 * ===================================================================== */

function reset_pin_hash(): string {
    global $pdo;
    try {
        $s = $pdo->prepare("SELECT nilai FROM pengaturan WHERE kunci = 'reset_pin_hash'");
        $s->execute();
        return (string)$s->fetchColumn();
    } catch (Throwable $t) { return ''; }
}
function reset_pin_terpasang(): bool { return reset_pin_hash() !== ''; }
function reset_pin_format_valid(string $pin): bool { return (bool)preg_match('/^\d{4,12}$/', $pin); }

/**
 * Periksa PIN reset. Mengembalikan true bila benar, atau pesan kesalahan (string).
 * 5x salah berturut-turut mengunci percobaan selama 5 menit (per sesi login).
 */
function reset_pin_periksa(string $pin) {
    $hash = reset_pin_hash();
    if ($hash === '') return 'PIN reset belum dibuat. Buat dulu di menu Pengaturan > PIN reset gaji.';
    if (($_SESSION['reset_kunci_sampai'] ?? 0) > time()) {
        return 'Terlalu banyak PIN salah. Coba lagi dalam ' . (int)ceil(($_SESSION['reset_kunci_sampai'] - time()) / 60) . ' menit.';
    }
    if ($pin !== '' && password_verify($pin, $hash)) {
        unset($_SESSION['reset_gagal'], $_SESSION['reset_kunci_sampai']);
        return true;
    }
    $_SESSION['reset_gagal'] = (int)($_SESSION['reset_gagal'] ?? 0) + 1;
    if ($_SESSION['reset_gagal'] >= 5) {
        $_SESSION['reset_kunci_sampai'] = time() + 300;
        $_SESSION['reset_gagal'] = 0;
        return 'PIN salah 5 kali. Reset dikunci 5 menit.';
    }
    return 'PIN salah. Reset dibatalkan, tidak ada data yang dihapus.';
}

<?php
// Jalankan SEKALI untuk membuat admin pertama, lalu HAPUS file ini.
require __DIR__ . '/../app/bootstrap.php';
$count = (int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
if ($count > 0) die('Sudah ada user. Hapus setup.php.');
$stmt = $pdo->prepare('INSERT INTO users (nama, username, password, role) VALUES (?,?,?,?)');
$stmt->execute(['Administrator', 'admin', password_hash('admin123', PASSWORD_DEFAULT), 'admin']);
echo 'Admin dibuat. Username: admin | Password: admin123. Segera ganti password & hapus setup.php!';

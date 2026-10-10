<?php
require __DIR__ . '/../app/bootstrap.php';
if (is_logged_in()) redirect('index.php');

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $stmt = $pdo->prepare('SELECT * FROM users WHERE username = ?');
    $stmt->execute([trim($_POST['username'] ?? '')]);
    $u = $stmt->fetch();
    if ($u && password_verify($_POST['password'] ?? '', $u['password'])) {
        session_regenerate_id(true);
        $_SESSION['user'] = ['id' => $u['id'], 'nama' => $u['nama'], 'role' => $u['role']];
        redirect('index.php');
    }
    $error = 'Username atau password salah. Periksa lagi lalu coba masuk.';
}
$co = company();
?>
<!doctype html>
<html lang="id">
<head>
  <?php require APP_PATH . '/views/head.php'; ?>
  <title>Masuk - <?= e($co['nama']) ?></title>
</head>
<body class="app-body">
<div class="login">
  <section class="login-art" aria-label="<?= e($co['nama']) ?>">
    <div class="login-brand">
      <?= brand_mark('brand-mark') ?>
      <span><?= e($co['nama']) ?></span>
    </div>
    <div>
      <h2>Kas, absensi, dan gaji dalam satu tempat.</h2>
      <p>Catat kas harian, hitung lembur dan lalu cetak slip gaji pegawai.</p>
    </div>
  </section>

  <section class="login-form-wrap">
    <div class="login-card">
      <h1>Masuk</h1>
      <p class="sub">Gunakan akun yang diberikan administrator.</p>

      <?php if ($error): ?><div class="alert alert-danger py-2" role="alert"><?= e($error) ?></div><?php endif; ?>

      <form method="post" autocomplete="on">
        <?= csrf_field() ?>
        <div class="mb-3">
          <label class="form-label" for="username">Username</label>
          <input id="username" name="username" class="form-control" required autofocus autocomplete="username" autocapitalize="none" spellcheck="false" value="<?= e($_POST['username'] ?? '') ?>">
        </div>
        <div class="mb-4">
          <label class="form-label" for="password">Password</label>
          <div class="pw">
            <input id="password" type="password" name="password" class="form-control" required autocomplete="current-password">
            <button type="button" data-toggle-pw="password" aria-label="Tampilkan password" aria-pressed="false"><?= icon('eye', 20) ?></button>
          </div>
        </div>
        <button class="btn btn-primary">Masuk</button>
      </form>
    </div>
  </section>
</div>
<script src="<?= asset('app.js') ?>"></script>
</body>
</html>

<?php
$page       = basename($_SERVER['PHP_SELF']);
$co         = company();
$inline_kas = in_array($page, ['index.php', 'kas.php'], true);   // halaman yang sudah punya form kas di tempat
$_nav = [
    'Harian'     => [['index.php', 'Dashboard', 'home'], ['kas.php', 'Kas', 'wallet']],
    'Penggajian' => [['karyawan.php', 'Karyawan', 'users'], ['absensi.php', 'Absensi', 'calendar'],
                     ['import_absensi.php', 'Import finger', 'upload'], ['lembur.php', 'Lembur', 'clock'],
                     ['kasbon.php', 'Kasbon', 'receipt'], ['gaji.php', 'Gaji & slip', 'banknote']],
    'Sistem'     => [['pengaturan.php', 'Pengaturan', 'building']],
];
$kas_btn = function (string $cls, string $inner) use ($inline_kas): string {
    return $inline_kas
        ? '<a class="' . $cls . '" href="#kas-cepat">' . $inner . '</a>'
        : '<button type="button" class="' . $cls . '" data-bs-toggle="modal" data-bs-target="#kasModal">' . $inner . '</button>';
};
$_flash = flash();
?>
<!doctype html>
<html lang="id">
<head>
  <?php require APP_PATH . '/views/head.php'; ?>
  <title><?= e(($title ?? 'Aplikasi Keuangan') . ' - ' . $co['nama']) ?></title>
</head>
<body class="app-body">
<a class="skip-link" href="#main">Lewati ke konten</a>
<div class="sb-backdrop" id="sbBackdrop"></div>

<aside class="sidebar" id="sidebar" aria-label="Menu utama">
  <a class="brand" href="index.php">
    <?= brand_mark('brand-mark') ?>
    <span class="brand-name"><?= e($co['nama']) ?></span>
  </a>

  <?= $kas_btn('btn-kas', icon('plus', 18) . '<span>Catat kas</span>') ?>

  <nav class="sb-nav">
    <?php foreach ($_nav as $_grup => $_items): ?>
      <p class="sb-group"><?= e($_grup) ?></p>
      <?php foreach ($_items as [$_file, $_label, $_ico]): ?>
        <a class="sb-link <?= $page === $_file ? 'active' : '' ?>" href="<?= $_file ?>" <?= $page === $_file ? 'aria-current="page"' : '' ?>>
          <?= icon($_ico) ?><span><?= e($_label) ?></span>
        </a>
      <?php endforeach; ?>
    <?php endforeach; ?>
  </nav>

  <div class="sb-user">
    <div class="sb-avatar" aria-hidden="true"><?= e(mb_strtoupper(mb_substr($_SESSION['user']['nama'] ?? '?', 0, 1))) ?></div>
    <div class="sb-who">
      <strong><?= e($_SESSION['user']['nama'] ?? '') ?></strong>
      <span><?= e($_SESSION['user']['role'] ?? '') ?></span>
    </div>
    <a class="sb-logout" href="logout.php" title="Keluar" aria-label="Keluar"><?= icon('logout', 18) ?></a>
  </div>
</aside>

<div class="main">
  <header class="topbar">
    <button type="button" class="tb-btn" id="sbToggle" aria-controls="sidebar" aria-expanded="false" aria-label="Buka menu"><?= icon('menu', 22) ?></button>
    <span class="tb-title"><?= e($title ?? '') ?></span>
    <?= $kas_btn('tb-btn tb-kas', icon('plus', 20) . '<span class="visually-hidden">Catat kas</span>') ?>
  </header>

  <main class="content" id="main">
    <?php if ($_flash): ?>
      <div class="alert alert-<?= e($_flash[1]) ?> alert-dismissible fade show" role="status">
        <?= e($_flash[0]) ?><button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Tutup"></button>
      </div>
    <?php endif; ?>
    <?php if (empty($hide_title)): ?>
      <div class="page-head">
        <h1><?= e($title ?? '') ?></h1>
        <?php if (!empty($subtitle)): ?><p><?= e($subtitle) ?></p><?php endif; ?>
      </div>
    <?php endif; ?>

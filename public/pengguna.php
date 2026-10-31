<?php
require __DIR__ . '/../app/bootstrap.php';
require_admin();
pastikan_role_pengguna();
$title = 'Pengguna';
$subtitle = 'Kelola akun login. Role "Pengelola konsumable" hanya bisa membuka menu Konsumable.';

const ROLE_LABEL = ['admin' => 'Admin (semua menu)', 'staff' => 'Staff (semua menu, tanpa kelola pengguna)', 'konsumable' => 'Pengelola konsumable (hanya Konsumable)'];
$me = (int)$_SESSION['user']['id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $aksi = $_POST['aksi'] ?? '';

    if ($aksi === 'tambah') {
        $nama = mb_substr(trim((string)($_POST['nama'] ?? '')), 0, 100);
        $user = trim((string)($_POST['username'] ?? ''));
        $pass = (string)($_POST['password'] ?? '');
        $role = array_key_exists($_POST['role'] ?? '', ROLE_LABEL) ? $_POST['role'] : 'konsumable';
        if ($nama === '' || !preg_match('/^[A-Za-z0-9._-]{3,50}$/', $user)) {
            flash('Nama wajib diisi; username 3-50 karakter (huruf, angka, titik, strip, garis bawah).', 'danger');
        } elseif (strlen($pass) < 6) {
            flash('Password minimal 6 karakter.', 'danger');
        } else {
            $cek = $pdo->prepare('SELECT 1 FROM users WHERE username=?');
            $cek->execute([$user]);
            if ($cek->fetch()) {
                flash('Username sudah dipakai.', 'danger');
            } else {
                $pdo->prepare('INSERT INTO users (nama, username, password, role) VALUES (?,?,?,?)')
                    ->execute([$nama, $user, password_hash($pass, PASSWORD_DEFAULT), $role]);
                flash("Pengguna $user dibuat.");
            }
        }
    } elseif ($aksi === 'password') {
        $pass = (string)($_POST['password'] ?? '');
        if (strlen($pass) < 6) {
            flash('Password minimal 6 karakter.', 'danger');
        } else {
            $pdo->prepare('UPDATE users SET password=? WHERE id=?')->execute([password_hash($pass, PASSWORD_DEFAULT), (int)$_POST['id']]);
            flash('Password diganti.');
        }
    } elseif ($aksi === 'hapus') {
        $id = (int)$_POST['id'];
        $admins = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role='admin'")->fetchColumn();
        $r = $pdo->prepare('SELECT role FROM users WHERE id=?'); $r->execute([$id]);
        $role = $r->fetchColumn();
        if ($id === $me) {
            flash('Akun yang sedang dipakai tidak bisa dihapus.', 'danger');
        } elseif ($role === 'admin' && $admins <= 1) {
            flash('Admin terakhir tidak bisa dihapus.', 'danger');
        } else {
            $pdo->prepare('DELETE FROM users WHERE id=?')->execute([$id]);
            flash('Pengguna dihapus.');
        }
    }
    redirect('pengguna.php');
}

$rows = $pdo->query('SELECT id, nama, username, role FROM users ORDER BY role, nama')->fetchAll();
require APP_PATH . '/views/header.php';
?>
<div class="row g-4">
  <div class="col-lg-4">
    <div class="card"><div class="card-header">Tambah Pengguna</div><div class="card-body">
      <form method="post" autocomplete="off"><?= csrf_field() ?><input type="hidden" name="aksi" value="tambah">
        <div class="mb-2"><label class="form-label" for="u-nama">Nama</label><input id="u-nama" name="nama" class="form-control" required maxlength="100"></div>
        <div class="mb-2"><label class="form-label" for="u-user">Username</label><input id="u-user" name="username" class="form-control" required maxlength="50" autocapitalize="none" spellcheck="false"></div>
        <div class="mb-2"><label class="form-label" for="u-pass">Password (min. 6 karakter)</label><input id="u-pass" type="password" name="password" class="form-control" required minlength="6" autocomplete="new-password"></div>
        <div class="mb-3"><label class="form-label" for="u-role">Role</label>
          <select id="u-role" name="role" class="form-select">
            <?php foreach (ROLE_LABEL as $k => $l): ?><option value="<?= e($k) ?>" <?= $k === 'konsumable' ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?>
          </select></div>
        <button class="btn btn-primary">Simpan</button>
      </form>
    </div></div>
  </div>
  <div class="col-lg-8">
    <div class="card"><div class="table-responsive">
      <table class="table table-hover mb-0 align-middle">
        <thead><tr><th>Nama</th><th>Username</th><th>Role</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($rows as $r): ?>
          <tr><td><?= e($r['nama']) ?><?= (int)$r['id'] === $me ? ' <span class="badge text-bg-secondary">Anda</span>' : '' ?></td><td><?= e($r['username']) ?></td>
            <td><?= e(explode(' (', ROLE_LABEL[$r['role']] ?? $r['role'])[0]) ?></td>
            <td class="text-end text-nowrap">
              <form method="post" class="d-inline-flex gap-1" autocomplete="off"><?= csrf_field() ?><input type="hidden" name="aksi" value="password"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                <input type="password" name="password" class="form-control form-control-sm" style="width:140px" placeholder="Password baru" minlength="6" required aria-label="Password baru untuk <?= e($r['username']) ?>" autocomplete="new-password">
                <button class="btn btn-sm btn-outline-primary">Ganti</button></form>
              <?php if ((int)$r['id'] !== $me): ?>
              <form method="post" class="d-inline" onsubmit="return confirm('Hapus pengguna ini?')"><?= csrf_field() ?><input type="hidden" name="aksi" value="hapus"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                <button class="btn btn-sm btn-outline-danger">Hapus</button></form>
              <?php endif; ?>
            </td></tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div></div>
  </div>
</div>
<?php require APP_PATH . '/views/footer.php'; ?>

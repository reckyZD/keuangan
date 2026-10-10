  </main>
</div>

<?php if (!$inline_kas): ?>
<div class="modal fade" id="kasModal" tabindex="-1" aria-labelledby="kasModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header">
        <h2 class="modal-title" id="kasModalLabel">Catat kas</h2>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Tutup"></button>
      </div>
      <div class="modal-body">
        <?php $kf_id = 'kfm'; require APP_PATH . '/views/kas_form.php'; ?>
      </div>
    </div>
  </div>
</div>
<?php endif; ?>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="<?= asset('app.js') ?>"></script>
</body>
</html>

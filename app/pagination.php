<?php
/**
 * Pagination sederhana untuk halaman daftar.
 * Parameter URL: ?hal=2&per=25  (per = 10/25/50/100, diingat di session).
 */
const PG_OPTIONS = [10, 25, 50, 100];

/** Baca halaman & jumlah per halaman. Return [$hal, $per]. */
function pg_params(): array {
    if (isset($_GET['per']) && in_array((int)$_GET['per'], PG_OPTIONS, true)) {
        $_SESSION['per_halaman'] = (int)$_GET['per'];
    }
    $per = (int)($_SESSION['per_halaman'] ?? 10);
    if (!in_array($per, PG_OPTIONS, true)) $per = 10;
    $hal = max(1, (int)($_GET['hal'] ?? 1));
    return [$hal, $per];
}

/** Rapikan $hal (tidak melebihi halaman terakhir) dan kembalikan klausa LIMIT/OFFSET. */
function pg_limit(int $total, int &$hal, int $per): string {
    $hal = min($hal, max(1, (int)ceil($total / $per)));
    return ' LIMIT ' . $per . ' OFFSET ' . (($hal - 1) * $per);
}

/** Info + pilihan per halaman + tombol halaman. */
function pg_render(int $total, int $hal, int $per): string {
    $pages = max(1, (int)ceil($total / $per));
    $hal   = min($hal, $pages);
    $from  = $total ? ($hal - 1) * $per + 1 : 0;
    $to    = min($total, $hal * $per);
    $url   = fn(array $o) => '?' . http_build_query(array_merge($_GET, $o));

    $h  = '<div class="pg-bar">';
    $h .= '<div class="pg-info">Menampilkan ' . $from . '–' . $to . ' dari ' . $total . ' data</div>';
    $h .= '<label class="pg-size">halaman <select class="form-select form-select-sm" onchange="location.href=this.value">';
    foreach (PG_OPTIONS as $o) {
        $h .= '<option value="' . e($url(['per' => $o, 'hal' => 1])) . '"' . ($o === $per ? ' selected' : '') . '>' . $o . '</option>';
    }
    $h .= '</select></label>';

    if ($pages > 1) {
        $item = function (string $label, ?int $p, bool $off = false, bool $on = false) use ($url) {
            $href = ($p && !$off) ? e($url(['hal' => $p])) : '#';
            return '<li class="page-item' . ($off ? ' disabled' : '') . ($on ? ' active' : '') . '"><a class="page-link" href="' . $href . '">' . $label . '</a></li>';
        };
        $h .= '<nav aria-label="Halaman"><ul class="pagination pagination-sm mb-0">';
        $h .= $item('«', $hal - 1, $hal <= 1);
        $last = 0;
        for ($i = 1; $i <= $pages; $i++) {
            if ($i === 1 || $i === $pages || abs($i - $hal) <= 2) {
                if ($last && $i - $last > 1) $h .= $item('…', null, true);
                $h .= $item((string)$i, $i, false, $i === $hal);
                $last = $i;
            }
        }
        $h .= $item('»', $hal + 1, $hal >= $pages) . '</ul></nav>';
    }
    return $h . '</div>';
}

/** Footer kartu berisi pagination (kosong bila tidak ada data). */
function pg_footer(int $total, int $hal, int $per): string {
    return $total > 0 ? '<div class="card-footer bg-transparent">' . pg_render($total, $hal, $per) . '</div>' : '';
}

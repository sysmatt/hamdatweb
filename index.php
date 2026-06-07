<?php
require __DIR__ . '/../simplewebauth/auth.php';

$_cfg = __DIR__ . '/../hamdatweb-config.php';
if (!is_readable($_cfg)) {
    die('<pre>Missing config: create hamdatweb-config.php in the docroot (one level above this directory).
See hamdatweb-config.php.example in this project for the template.</pre>');
}
require $_cfg;
defined('HAMDAT_BIN')      || define('HAMDAT_BIN',      '/usr/local/bin/hamdat');
defined('HAMDAT_DB')       || define('HAMDAT_DB',        (getenv('HOME') ?: '/var/www') . '/.hamdat/hamdat.db');
defined('HAMDAT_TEMP_DIR') || define('HAMDAT_TEMP_DIR',  sys_get_temp_dir());

const CLASSES = ['T' => 'Technician', 'G' => 'General',  'E' => 'Extra',
                 'A' => 'Advanced',   'N' => 'Novice',   'P' => 'Technician Plus'];
const TYPES   = ['individual', 'club', 'races', 'military', 'government'];
const FORMATS = ['csv' => 'CSV', 'json' => 'JSON', 'html' => 'HTML'];

function esc(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
function praw(string $key, string $default = ''): string {
    $v = $_POST[$key] ?? $default;
    return is_array($v) ? $default : trim((string) $v);
}
function pv(string $key, string $default = ''): string {
    return esc(praw($key, $default));
}
function parr(string $key): array {
    $v = $_POST[$key] ?? [];
    return is_array($v) ? $v : [];
}
function pchk(string $key): bool {
    return !empty($_POST[$key]);
}

function valid_date(string $s): bool {
    return (bool) preg_match(
        '/^(\d{4}-\d{2}-\d{2}(:\d{4}-\d{2}-\d{2})?|(since|after|thru|before):\d{4}-\d{2}-\d{2}|[+-]\d+(:-\d+)?)$/',
        $s
    );
}

function dq(string $s): string {
    return preg_match('/[\s\'"]/', $s) ? '"' . addcslashes($s, '"\\') . '"' : $s;
}

// Build the hamdat command from POST data.
// Mode is determined by search_mode field ('call' | 'search') set by the submit buttons.
// Only 'call' mode uses the callsign field; everything else (search, download) uses search mode.
function build_cmd(array $p, string $fmt = '', string $outfile = ''): array {
    $exec = [HAMDAT_BIN, '--db', escapeshellarg(HAMDAT_DB)];
    $disp = ['hamdat'];
    $mode = 'search';

    $call = (($p['search_mode'] ?? '') === 'call') ? strtoupper(trim($p['call'] ?? '')) : '';

    if ($call !== '') {
        $mode = 'call';
        array_push($exec, '--call', escapeshellarg($call));
        array_push($disp, '--call', $call);
        if (!empty($p['history']))      { $exec[] = '--history';      $disp[] = '--history'; }
        if (!empty($p['full_history'])) { $exec[] = '--full-history'; $disp[] = '--full-history'; }
    } else {
        foreach (['callsearch' => '--callsearch', 'name' => '--name', 'address' => '--address'] as $field => $flag) {
            $val = trim($p[$field] ?? '');
            if ($val !== '') {
                array_push($exec, $flag, escapeshellarg($val));
                array_push($disp, $flag, dq($val));
            }
        }
        if (!empty($p['regex'])) { $exec[] = '--regex'; $disp[] = '--regex'; }

        $classes = array_values(array_filter(
            array_map('strtoupper', (array) ($p['class'] ?? [])),
            fn($c) => isset(CLASSES[$c])
        ));
        if ($classes) {
            $exec[] = '--class';
            $disp[] = '--class';
            foreach ($classes as $c) {
                $exec[] = escapeshellarg($c);
                $disp[] = $c;
            }
        }

        $type = strtolower(trim($p['type'] ?? ''));
        if ($type !== '' && in_array($type, TYPES, true)) {
            array_push($exec, '--type', escapeshellarg($type));
            array_push($disp, '--type', $type);
        }

        foreach (['grant_date' => '--grant-date', 'change_date' => '--change-date'] as $field => $flag) {
            $val = trim($p[$field] ?? '');
            if ($val !== '' && valid_date($val)) {
                array_push($exec, $flag, escapeshellarg($val));
                array_push($disp, $flag, $val);
            }
        }

        $zip = trim($p['zip'] ?? '');
        if (preg_match('/^\d{5}$/', $zip)) {
            array_push($exec, '--zip', escapeshellarg($zip));
            array_push($disp, '--zip', $zip);
            $mi = max(0, (int) ($p['radius_miles'] ?? 0));
            if ($mi > 0) {
                array_push($exec, '--radius-miles', (string) $mi);
                array_push($disp, '--radius-miles', (string) $mi);
            }
        }
    }

    if ($fmt !== '' && isset(FORMATS[$fmt])) {
        $exec[] = '--' . $fmt;
        $disp[] = '--' . $fmt;
        if ($outfile !== '') {
            array_push($exec, '--file', escapeshellarg($outfile));
        }
    }

    return ['exec' => implode(' ', $exec), 'disp' => implode(' ', $disp), 'mode' => $mode];
}

function has_search_criteria(array $p): bool {
    return trim($p['callsearch'] ?? '') !== ''
        || trim($p['name'] ?? '')       !== ''
        || trim($p['address'] ?? '')    !== ''
        || !empty($p['class'])
        || trim($p['type'] ?? '')       !== ''
        || trim($p['grant_date'] ?? '') !== ''
        || trim($p['change_date'] ?? '') !== ''
        || trim($p['zip'] ?? '')        !== '';
}

// ── Handle form submission ───────────────────────────────────────────────────

$result_mode  = null;   // 'call' | 'table' | 'error'
$disp_cmd     = null;
$call_out     = null;
$tbl_headers  = [];
$tbl_rows     = [];
$error_msg    = null;

// GET ?call= comes from clicking a callsign link in the table (supports new-tab).
$get_call  = strtoupper(trim($_GET['call'] ?? ''));
$submitted = ($_SERVER['REQUEST_METHOD'] === 'POST') || $get_call !== '';

// Pre-populate call field from whichever source is active.
$call_prefill = esc($get_call ?: strtoupper(trim($_POST['call'] ?? '')));

function do_call_lookup(string $call): void {
    global $result_mode, $disp_cmd, $call_out, $error_msg;
    $cmd      = build_cmd(['call' => $call, 'search_mode' => 'call']);
    $disp_cmd = $cmd['disp'];
    exec($cmd['exec'] . ' 2>&1', $exec_out, $rc);
    if ($rc === 0) {
        $call_out    = implode("\n", $exec_out);
        $result_mode = 'call';
    } else {
        $error_msg   = implode("\n", $exec_out);
        $result_mode = 'error';
    }
}

if ($submitted) {

    if ($get_call !== '') {
        // ── GET-based callsign lookup (table link, supports open-in-new-tab) ─
        do_call_lookup($get_call);

    } else {
        $dl_fmt      = praw('download_format');
        $search_mode = praw('search_mode');

        if ($dl_fmt !== '' && isset(FORMATS[$dl_fmt])) {
            // ── Download: write to temp file, stream, exit ────────────────
            $tmp = tempnam(HAMDAT_TEMP_DIR, 'hamdatweb_') . '.' . $dl_fmt;
            $cmd = build_cmd($_POST, $dl_fmt, $tmp);
            $disp_cmd = $cmd['disp'];
            exec($cmd['exec'] . ' 2>&1', $exec_out, $rc);
            if ($rc === 0 && is_readable($tmp) && filesize($tmp) > 0) {
                $mime = ['csv' => 'text/csv', 'json' => 'application/json', 'html' => 'text/html'];
                header('Content-Type: ' . $mime[$dl_fmt]);
                header('Content-Disposition: attachment; filename="hamdat_results.' . $dl_fmt . '"');
                header('Content-Length: ' . filesize($tmp));
                readfile($tmp);
                @unlink($tmp);
                exit;
            }
            $error_msg   = $rc !== 0
                ? ('hamdat error: ' . implode("\n", $exec_out))
                : 'Output file was not created or was empty.';
            $result_mode = 'error';
            @unlink($tmp);

        } elseif ($search_mode === 'call') {
            // ── Single callsign profile (form button) ─────────────────────
            if (praw('call') === '') {
                $error_msg   = 'Please enter a callsign to look up.';
                $result_mode = 'error';
            } else {
                do_call_lookup(strtoupper(praw('call')));
            }

        } elseif ($search_mode === 'search') {
            // ── Multi-record search (CSV internally → HTML table) ──────────
            if (!has_search_criteria($_POST)) {
                $error_msg   = 'Please enter at least one search criterion.';
                $result_mode = 'error';
            } else {
                $tmp      = tempnam(HAMDAT_TEMP_DIR, 'hamdatweb_') . '.csv';
                $cmd      = build_cmd($_POST, 'csv', $tmp);
                $disp_cmd = build_cmd($_POST)['disp'];
                exec($cmd['exec'] . ' 2>&1', $exec_out, $rc);
                if ($rc !== 0) {
                    $error_msg   = 'hamdat error: ' . implode("\n", $exec_out);
                    $result_mode = 'error';
                } elseif (is_readable($tmp)) {
                    if (($fh = fopen($tmp, 'r')) !== false) {
                        $tbl_headers = fgetcsv($fh) ?: [];
                        while (($row = fgetcsv($fh)) !== false) {
                            $tbl_rows[] = $row;
                        }
                        fclose($fh);
                    }
                    $result_mode = 'table';
                } else {
                    $error_msg   = 'No output file was produced.';
                    $result_mode = 'error';
                }
                @unlink($tmp);
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>HamDat Web</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<style>
  pre.hamdat-out { font-size: 1rem; white-space: pre-wrap; word-break: break-all; }
  code.cli-cmd   { font-size: .88rem; word-break: break-all; color: #7fffb2; }
  .table-scroll thead th { position: sticky; top: 0; z-index: 1; }
  .date-help     { font-size: .75rem; }
  #search-overlay {
    display: none;
    position: fixed;
    inset: 0;
    background: rgba(0, 0, 0, .55);
    z-index: 9999;
    flex-direction: column;
    align-items: center;
    justify-content: center;
  }
</style>
</head>
<body class="bg-light">

<!-- Loading overlay (shown after 400ms on submit) -->
<div id="search-overlay" role="status" aria-live="polite">
  <div class="spinner-border text-light" style="width: 3rem; height: 3rem;"></div>
  <div class="text-light mt-3 fs-5">Searching&hellip;</div>
</div>

<form method="post" id="sf">
<input type="hidden" name="download_format" value="">
<!-- Hidden default submit button — browser picks the first one when Enter is pressed.
     Placing search_mode=search here makes Enter in any field trigger a multi-record search
     rather than the callsign lookup button that appears first in visual DOM order. -->
<button type="submit" name="search_mode" value="search"
        style="position:absolute;left:-9999px;width:1px;height:1px;overflow:hidden"
        aria-hidden="true" tabindex="-1"></button>

<div class="container-fluid py-3 px-4">

  <!-- Page header -->
  <div class="d-flex justify-content-between align-items-center mb-3">
    <h1 class="h4 mb-0">
      HamDat Web
      <span class="text-muted fw-light fs-6">FCC Amateur License Search</span>
    </h1>
    <small class="text-muted">
      <?= esc(auth_user()) ?> &bull;
      <a href="../simplewebauth/logout.php" class="text-muted">Sign out</a>
    </small>
  </div>

  <div class="row g-3 mb-3">

    <!-- Single Callsign Lookup -->
    <div class="col-xl-3 col-lg-4">
      <div class="card h-100">
        <div class="card-header fw-semibold">Single Callsign Lookup</div>
        <div class="card-body d-flex flex-column">
          <div class="mb-3">
            <label class="form-label small mb-1">Callsign</label>
            <input type="text" name="call" class="form-control text-uppercase"
                   value="<?= $call_prefill ?>" placeholder="W1AW" autocomplete="off">
          </div>
          <div class="form-check mb-2">
            <input type="checkbox" name="history" id="chk_hist" class="form-check-input"
                   <?= pchk('history') ? 'checked' : '' ?>>
            <label for="chk_hist" class="form-check-label small">
              History — compact list of prior licensees
            </label>
          </div>
          <div class="form-check mb-3">
            <input type="checkbox" name="full_history" id="chk_fhist" class="form-check-input"
                   <?= pchk('full_history') ? 'checked' : '' ?>>
            <label for="chk_fhist" class="form-check-label small">
              Full history — complete profiles of prior licensees
            </label>
          </div>
          <div class="mt-auto">
            <button type="submit" name="search_mode" value="call"
                    id="btn-call-lookup" class="btn btn-primary w-100">
              Lookup Callsign
            </button>
          </div>
        </div>
      </div>
    </div>

    <!-- Multi-record Search -->
    <div class="col-xl-9 col-lg-8">
      <div class="card h-100">
        <div class="card-header fw-semibold">
          Multi-record Search
          <span class="text-muted fw-normal small ms-2">— all filters AND together; Class values OR within themselves</span>
        </div>
        <div class="card-body d-flex flex-column">

          <!-- Text search -->
          <div class="row g-2 mb-3">
            <div class="col-md-4">
              <label class="form-label small mb-1">Callsign contains</label>
              <input type="text" name="callsearch" class="form-control form-control-sm"
                     value="<?= pv('callsearch') ?>">
            </div>
            <div class="col-md-4">
              <label class="form-label small mb-1">Name contains</label>
              <input type="text" name="name" class="form-control form-control-sm"
                     value="<?= pv('name') ?>">
            </div>
            <div class="col-md-4">
              <label class="form-label small mb-1">Address contains</label>
              <input type="text" name="address" class="form-control form-control-sm"
                     value="<?= pv('address') ?>">
            </div>
          </div>

          <!-- Regex -->
          <div class="mb-3">
            <div class="form-check">
              <input type="checkbox" name="regex" id="chk_regex" class="form-check-input"
                     <?= pchk('regex') ? 'checked' : '' ?>>
              <label for="chk_regex" class="form-check-label small">
                Treat callsign / name / address as regular expressions
              </label>
            </div>
          </div>

          <!-- Class + Type -->
          <div class="row g-2 mb-3">
            <div class="col-md-8">
              <label class="form-label small mb-1">License Class</label>
              <div class="d-flex flex-wrap gap-3">
                <?php foreach (CLASSES as $code => $label): ?>
                <div class="form-check mb-0">
                  <input type="checkbox" name="class[]" id="cls_<?= $code ?>" value="<?= $code ?>"
                         class="form-check-input"
                         <?= in_array($code, parr('class'), true) ? 'checked' : '' ?>>
                  <label for="cls_<?= $code ?>" class="form-check-label small">
                    <strong><?= $code ?></strong> <?= $label ?>
                  </label>
                </div>
                <?php endforeach; ?>
              </div>
            </div>
            <div class="col-md-4">
              <label class="form-label small mb-1">Entity Type</label>
              <select name="type" class="form-select form-select-sm">
                <option value="">Any</option>
                <?php foreach (TYPES as $t): ?>
                <option value="<?= $t ?>" <?= praw('type') === $t ? 'selected' : '' ?>>
                  <?= ucfirst($t) ?>
                </option>
                <?php endforeach; ?>
              </select>
            </div>
          </div>

          <!-- Dates + ZIP -->
          <div class="row g-2 mb-3">
            <div class="col-sm-3">
              <label class="form-label small mb-1">Grant Date</label>
              <input type="text" name="grant_date" class="form-control form-control-sm"
                     value="<?= pv('grant_date') ?>" placeholder="-30">
              <div class="date-help text-muted mt-1">
                <code>-30</code> &middot; <code>2025-01-01</code> &middot; <code>2025-01-01:2025-12-31</code><br>
                <code>since:</code> <code>after:</code> <code>thru:</code> <code>before:</code>
              </div>
            </div>
            <div class="col-sm-3">
              <label class="form-label small mb-1">Change Date</label>
              <input type="text" name="change_date" class="form-control form-control-sm"
                     value="<?= pv('change_date') ?>" placeholder="-7">
              <div class="date-help text-muted mt-1">Same formats as Grant Date</div>
            </div>
            <div class="col-sm-3">
              <label class="form-label small mb-1">ZIP Code</label>
              <input type="text" name="zip" class="form-control form-control-sm"
                     value="<?= pv('zip') ?>" placeholder="07848" maxlength="5">
            </div>
            <div class="col-sm-3">
              <label class="form-label small mb-1">Radius (miles)</label>
              <input type="number" name="radius_miles" class="form-control form-control-sm"
                     value="<?= pv('radius_miles', '0') ?>" min="0" placeholder="0 = exact ZIP">
            </div>
          </div>

          <div class="mt-auto">
            <button type="submit" name="search_mode" value="search"
                    class="btn btn-primary">
              Search Records
            </button>
          </div>

        </div><!-- /card-body -->
      </div><!-- /card -->
    </div><!-- /col -->

  </div><!-- /row -->

  <!-- ── Results ──────────────────────────────────────────────────────────── -->
  <?php if ($submitted): ?>
  <hr class="mb-4">

  <!-- CLI command display -->
  <?php if ($disp_cmd !== null): ?>
  <div class="card mb-3 border-0 shadow-sm">
    <div class="card-header bg-dark text-white d-flex justify-content-between align-items-center py-2">
      <span class="small fw-semibold font-monospace">hamdat CLI</span>
      <span class="text-secondary small">Copy to run locally with your own database</span>
    </div>
    <div class="card-body bg-dark py-2 rounded-bottom">
      <code class="cli-cmd"><?= esc($disp_cmd) ?></code>
    </div>
  </div>
  <?php endif; ?>

  <!-- Error -->
  <?php if ($result_mode === 'error'): ?>
  <div class="alert alert-danger">
    <strong>Error</strong>
    <pre class="mb-0 mt-2 small"><?= esc($error_msg ?? '') ?></pre>
  </div>

  <!-- Single callsign profile -->
  <?php elseif ($result_mode === 'call'): ?>
  <div class="card shadow-sm">
    <div class="card-header">Callsign Profile</div>
    <div class="card-body bg-white">
      <pre class="hamdat-out mb-0"><?= esc($call_out ?? '') ?></pre>
    </div>
  </div>

  <!-- Multi-record table -->
  <?php elseif ($result_mode === 'table'): ?>
  <div class="card shadow-sm">
    <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
      <strong><?= number_format(count($tbl_rows)) ?></strong>&nbsp;result(s)
      <div class="d-flex align-items-center gap-2">
        <span class="text-muted small">Download same query as:</span>
        <?php foreach (FORMATS as $fmt => $label): ?>
        <button type="submit" name="download_format" value="<?= $fmt ?>"
                class="btn btn-sm btn-outline-secondary">
          <?= $label ?>
        </button>
        <?php endforeach; ?>
      </div>
    </div>
    <?php if (empty($tbl_rows)): ?>
    <div class="card-body text-muted">No results found.</div>
    <?php else: ?>
    <?php $call_col = array_search('call_sign', $tbl_headers); ?>
    <div class="table-scroll">
      <table class="table table-sm table-striped table-hover mb-0">
        <thead class="table-dark">
          <tr>
            <?php foreach ($tbl_headers as $col): ?>
            <th class="text-nowrap"><?= esc((string) $col) ?></th>
            <?php endforeach; ?>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($tbl_rows as $row): ?>
          <tr>
            <?php foreach ($row as $ci => $cell): ?>
            <?php if ($ci === $call_col && $call_col !== false): ?>
            <td class="text-nowrap">
              <a href="?call=<?= urlencode((string) $cell) ?>" class="fw-semibold text-decoration-none">
                <?= esc((string) $cell) ?>
              </a>
            </td>
            <?php else: ?>
            <td class="text-nowrap"><?= esc((string) $cell) ?></td>
            <?php endif; ?>
            <?php endforeach; ?>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>
  </div>

  <?php endif; ?>
  <?php endif; // submitted ?>

</div><!-- /container-fluid -->
</form>

<script>
(function () {
  var form    = document.getElementById('sf');
  var overlay = document.getElementById('search-overlay');

  function spinBtn(btn, label) {
    btn.style.pointerEvents = 'none';
    btn.innerHTML =
      '<span class="spinner-border spinner-border-sm me-2" role="status" aria-hidden="true"></span>'
      + label;
  }

  form.addEventListener('submit', function (e) {
    var btn = e.submitter;
    if (!btn) return;

    if (btn.name === 'search_mode') {
      // Search: spinner + overlay. Page reloads so no reset needed.
      spinBtn(btn, 'Searching…');
      setTimeout(function () { overlay.style.display = 'flex'; }, 400);

    } else if (btn.name === 'download_format') {
      // Download: spinner + overlay, but page does NOT reload after file delivery,
      // so we reset the UI when the window regains focus or visibility.
      var origHTML     = btn.innerHTML;
      var overlayTimer = setTimeout(function () { overlay.style.display = 'flex'; }, 400);
      var fallbackTimer;

      function reset() {
        clearTimeout(overlayTimer);
        clearTimeout(fallbackTimer);
        overlay.style.display = 'none';
        btn.style.pointerEvents = '';
        btn.innerHTML = origHTML;
        window.removeEventListener('focus', reset);
        document.removeEventListener('visibilitychange', onVisible);
      }
      function onVisible() { if (!document.hidden) reset(); }

      spinBtn(btn, 'Preparing…');
      window.addEventListener('focus', reset);
      document.addEventListener('visibilitychange', onVisible);
      fallbackTimer = setTimeout(reset, 15000); // safety net
    }
  });


}());
</script>
</body>
</html>

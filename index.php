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

// Wrap a display value in double-quotes if it contains whitespace or quotes.
function dq(string $s): string {
    return preg_match('/[\s\'"]/', $s) ? '"' . addcslashes($s, '"\\') . '"' : $s;
}

// Build the hamdat command from POST data.
// Returns ['exec' => full shell command, 'disp' => portable display string, 'mode' => 'call'|'search']
function build_cmd(array $p, string $fmt = '', string $outfile = ''): array {
    $exec = [HAMDAT_BIN, '--db', escapeshellarg(HAMDAT_DB)];
    $disp = ['hamdat'];
    $mode = 'search';
    $call = strtoupper(trim($p['call'] ?? ''));

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
            // temp path intentionally excluded from display
        }
    }

    return ['exec' => implode(' ', $exec), 'disp' => implode(' ', $disp), 'mode' => $mode];
}

function has_criteria(array $p): bool {
    return trim($p['call'] ?? '')       !== ''
        || trim($p['callsearch'] ?? '') !== ''
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
$submitted    = ($_SERVER['REQUEST_METHOD'] === 'POST');

if ($submitted) {
    if (!has_criteria($_POST)) {
        $result_mode = 'error';
        $error_msg   = 'Please enter at least one search criterion.';
    } else {
        $dl_fmt = praw('download_format');

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

        } elseif (strtoupper(praw('call')) !== '') {
            // ── Single callsign profile ───────────────────────────────────
            $cmd      = build_cmd($_POST);
            $disp_cmd = $cmd['disp'];
            exec($cmd['exec'] . ' 2>&1', $exec_out, $rc);
            if ($rc === 0) {
                $call_out    = implode("\n", $exec_out);
                $result_mode = 'call';
            } else {
                $error_msg   = implode("\n", $exec_out);
                $result_mode = 'error';
            }

        } else {
            // ── Multi-record search (CSV internally → HTML table) ─────────
            $tmp      = tempnam(HAMDAT_TEMP_DIR, 'hamdatweb_') . '.csv';
            $cmd      = build_cmd($_POST, 'csv', $tmp);
            $disp_cmd = build_cmd($_POST)['disp'];   // display without format flag (table is default)
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
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>HamDat Web</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<style>
  pre.hamdat-out { font-size: .82rem; white-space: pre-wrap; word-break: break-all; }
  code.cli-cmd   { font-size: .88rem; word-break: break-all; color: #7fffb2; }
  .table-scroll  { max-height: 70vh; overflow-y: auto; }
  .table-scroll thead th { position: sticky; top: 0; z-index: 1; }
  .date-help     { font-size: .75rem; }
</style>
</head>
<body class="bg-light">
<form method="post" id="sf">
<input type="hidden" name="download_format" value="">

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
        <div class="card-body">
          <label class="form-label small mb-1">Callsign <code>--call</code></label>
          <input type="text" name="call" class="form-control text-uppercase mb-3"
                 value="<?= pv('call') ?>" placeholder="W1AW" autocomplete="off">
          <div class="form-check mb-2">
            <input type="checkbox" name="history" id="chk_hist" class="form-check-input"
                   <?= pchk('history') ? 'checked' : '' ?>>
            <label for="chk_hist" class="form-check-label small">
              <code>--history</code> — compact list of prior licensees
            </label>
          </div>
          <div class="form-check">
            <input type="checkbox" name="full_history" id="chk_fhist" class="form-check-input"
                   <?= pchk('full_history') ? 'checked' : '' ?>>
            <label for="chk_fhist" class="form-check-label small">
              <code>--full-history</code> — full profiles of prior licensees
            </label>
          </div>
          <hr class="my-3">
          <p class="text-muted small mb-0">
            Fill in Callsign <strong>or</strong> the search fields on the right — not both.
            If Callsign is provided, the search fields are ignored.
          </p>
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
        <div class="card-body">

          <!-- Text search -->
          <div class="row g-2 mb-3">
            <div class="col-md-4">
              <label class="form-label small mb-1">Callsign contains <code>--callsearch</code></label>
              <input type="text" name="callsearch" class="form-control form-control-sm"
                     value="<?= pv('callsearch') ?>">
            </div>
            <div class="col-md-4">
              <label class="form-label small mb-1">Name contains <code>--name</code></label>
              <input type="text" name="name" class="form-control form-control-sm"
                     value="<?= pv('name') ?>">
            </div>
            <div class="col-md-4">
              <label class="form-label small mb-1">Address contains <code>--address</code></label>
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
                Treat callsign / name / address as Python regular expressions
                <code>--regex</code>
              </label>
            </div>
          </div>

          <!-- Class + Type -->
          <div class="row g-2 mb-3">
            <div class="col-md-8">
              <label class="form-label small mb-1">License Class <code>--class</code></label>
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
              <label class="form-label small mb-1">Entity Type <code>--type</code></label>
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
          <div class="row g-2">
            <div class="col-sm-3">
              <label class="form-label small mb-1">Grant Date <code>--grant-date</code></label>
              <input type="text" name="grant_date" class="form-control form-control-sm"
                     value="<?= pv('grant_date') ?>" placeholder="-30">
              <div class="date-help text-muted mt-1">
                <code>-30</code> · <code>2025-01-01</code> · <code>2025-01-01:2025-12-31</code><br>
                <code>since:</code> <code>after:</code> <code>thru:</code> <code>before:</code>
              </div>
            </div>
            <div class="col-sm-3">
              <label class="form-label small mb-1">Change Date <code>--change-date</code></label>
              <input type="text" name="change_date" class="form-control form-control-sm"
                     value="<?= pv('change_date') ?>" placeholder="-7">
              <div class="date-help text-muted mt-1">Same formats as Grant Date</div>
            </div>
            <div class="col-sm-3">
              <label class="form-label small mb-1">ZIP Code <code>--zip</code></label>
              <input type="text" name="zip" class="form-control form-control-sm"
                     value="<?= pv('zip') ?>" placeholder="07848" maxlength="5">
            </div>
            <div class="col-sm-3">
              <label class="form-label small mb-1">Radius Miles <code>--radius-miles</code></label>
              <input type="number" name="radius_miles" class="form-control form-control-sm"
                     value="<?= pv('radius_miles', '0') ?>" min="0" placeholder="0 = exact ZIP">
            </div>
          </div>

        </div><!-- /card-body -->
      </div><!-- /card -->
    </div><!-- /col -->

  </div><!-- /row -->

  <!-- Submit -->
  <div class="mb-4">
    <button type="submit" class="btn btn-primary">Search — View as Table</button>
  </div>

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
            <?php foreach ($row as $cell): ?>
            <td class="text-nowrap"><?= esc((string) $cell) ?></td>
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
</body>
</html>

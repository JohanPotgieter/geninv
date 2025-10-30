<?php
declare(strict_types=1);
ini_set('display_errors', '1');           // show errors in the browser
ini_set('display_startup_errors', '1');
error_reporting(E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED);

ini_set('log_errors', '1');               // also write to a file
ini_set('error_log', __DIR__ . '/php-error.log');

date_default_timezone_set('Australia/Brisbane');
if (php_sapi_name() === 'cli-server') {
    $reqPath = parse_url($_SERVER["REQUEST_URI"], PHP_URL_PATH);
    $file = __DIR__ . urldecode($reqPath);   // <<— urldecode fixes “spaces in filename”
    if (is_file($file)) return false;        // let the server serve the file directly
}

require __DIR__ . '/vendor/autoload.php';

use Dompdf\Dompdf;
use Dompdf\Options;

/* ---------- helpers ---------- */

function ensure_dir(string $dir): void {
    if (!is_dir($dir)) {
        mkdir($dir, 0775, true);
    }
}
// --- Amount -> words (AUD) ---
function amount_to_words_aud($val): string {
    $amt = is_numeric($val) ? round((float)$val, 2) : 0.0;
    $dollars = (int) floor($amt);
    $cents   = (int) round(($amt - $dollars) * 100);

    $d = spell_integer_en($dollars);
    $d_label = ($dollars === 1) ? 'dollar' : 'dollars';

    if ($cents > 0) {
        $c = spell_integer_en($cents);
        $c_label = ($cents === 1) ? 'cent' : 'cents';
        return ucfirst("$d $d_label and $c $c_label");
    }
    return ucfirst("$d $d_label");
}
function inject_page_css(string $html, string $paper = 'A4', string $orientation = 'portrait', int $margin_mm = 12): string {
    $pageCss = "@page { size: {$paper} {$orientation}; margin: {$margin_mm}mm 16mm {$margin_mm}mm 16mm; }
html,body{margin:0;padding:0;box-sizing:border-box;}
table{width:100%;table-layout:fixed;border-collapse:collapse;} th,td{word-wrap:break-word;}";
    if (stripos($html, '</head>') !== false) {
        return preg_replace('/<\/head>/i', "<style>{$pageCss}</style></head>", $html, 1);
    }
    return "<style>{$pageCss}</style>" . $html;
}

// Spells 0..trillions in English (AU style "and" before sub-100 remainders)
function spell_integer_en(int $n): string {
    if ($n === 0) return 'zero';
    if ($n < 0)   return 'minus ' . spell_integer_en(-$n);

    $units = [
        '', 'one','two','three','four','five','six','seven','eight','nine',
        'ten','eleven','twelve','thirteen','fourteen','fifteen','sixteen',
        'seventeen','eighteen','nineteen'
    ];
    $tens = ['', '', 'twenty','thirty','forty','fifty','sixty','seventy','eighty','ninety'];
    $scales = [
        1000000000000 => 'trillion',
        1000000000    => 'billion',
        1000000       => 'million',
        1000          => 'thousand',
        100           => 'hundred',
    ];

    $out = [];

    foreach ($scales as $value => $name) {
        if ($n >= $value) {
            $num = intdiv($n, $value);
            $n  -= $num * $value;

            // recurse for the chunk
            $chunk = spell_integer_en($num);
            $out[] = $chunk . ' ' . $name;

            if ($value === 100 && $n > 0 && $n < 100) {
                // AU/UK style "and" after "hundred" when remainder < 100
                $out[] = 'and';
            }
        }
    }

    if ($n >= 20) {
        $out[] = $tens[intdiv($n, 10)] . (($n % 10) ? '-' . $units[$n % 10] : '');
    } elseif ($n > 0) {
        $out[] = $units[$n];
    }

    return implode(' ', array_filter($out));
}

function read_template_or_default(string $path, string $kind): string {
    if (file_exists($path)) return file_get_contents($path);

    // Minimal fallback template so you can test immediately
    $title = ucfirst($kind);
    $numKey = $kind === 'invoice' ? 'invoice_number' : 'receipt_number';
    $numPlaceholder = '{{' . $numKey . '}}';

    // Only invoices show a due date line
    $dueLine = ($kind === 'invoice')
        ? '<div><span class="strong">Due:</span> {{due_date_long}}</div>'
        : '';

    return <<<HTML
<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <title>{$title} {$numPlaceholder}</title>
  <style>
    body { font-family: DejaVu Sans, Arial, sans-serif; font-size: 12px; margin: 28px; }
    .hdr { display:flex; justify-content:space-between; margin-bottom:16px; }
    .strong { font-weight:700; }
    .total { font-size: 14px; font-weight: bold; margin-top: 16px; }
    hr { margin: 16px 0; }
  </style>
</head>
<body>
  <div class="hdr">
    <div>
      <h2>{$title}</h2>
      <div><span class="strong">Number:</span> {$numPlaceholder}</div>
      <div><span class="strong">Date:</span> {{date_long}}</div>
      {$dueLine}
    </div>
    <div style="text-align:right">
      <div class="strong">Client:</div>
      {{client}}
    </div>
  </div>
  <hr>
  <p><span class="strong">Description:</span> {{description}}</p>
  <p class="total">Amount: {{amount}}</p>
</body>
</html>
HTML;
}

function apply_placeholders(string $template, array $data): string {
    $search = [];
    $replace = [];
    foreach ($data as $k => $v) {
        $search[]  = '{{' . $k . '}}';
        $replace[] = htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
    return str_replace($search, $replace, $template);
}

function format_aud($val): string {
    $amount = is_numeric($val) ? (float)$val : 0.0;
    return 'A$' . number_format($amount, 2, '.', ',');
}

function render_pdf_to_file(string $html, string $filePath, string $paper = 'A4', string $orientation = 'portrait'): void {
    $options = new Options();
    $options->set('isRemoteEnabled', true);
    $options->set('defaultFont', 'DejaVu Sans');

    $dompdf = new Dompdf($options);
    $dompdf->loadHtml($html, 'UTF-8');
    $dompdf->setPaper($paper, $orientation);   // <<— key change
    $dompdf->render();
    file_put_contents($filePath, $dompdf->output());
}

/* ---------- handle POST ---------- */

$messages = [];
$generated = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $outDir = __DIR__ . '/output';
    ensure_dir($outDir);

    $tplDir = __DIR__ . '/templates';
    ensure_dir($tplDir);

    $invoiceTpl = read_template_or_default($tplDir . '/invoice.html', 'invoice');
    $receiptTpl = read_template_or_default($tplDir . '/receipt.html', 'receipt');

    $invRows = $_POST['invoice'] ?? [];
    $recRows = $_POST['receipt'] ?? [];

    // Normalize rows (filter out completely empty lines)
    $filterEmpty = function(array $rows): array {
        $result = [];
        foreach ($rows as $r) {
            $vals = array_map('trim', $r);
            if (implode('', $vals) === '') continue;
            $result[] = $r;
        }
        return $result;
    };

    $invRows = $filterEmpty($invRows);
    $recRows = $filterEmpty($recRows);

    // Generate Invoices
    foreach ($invRows as $row) {
        $date = DateTime::createFromFormat('Y-m-d', trim($row['date'] ?? ''), new DateTimeZone('Australia/Brisbane'))
            ?: DateTime::createFromFormat('d/m/Y', trim($row['date'] ?? ''), new DateTimeZone('Australia/Brisbane'))
            ?: new DateTime('today', new DateTimeZone('Australia/Brisbane'));

        // Due date = 1 week after invoice date
        $due = (clone $date)->modify('+7 days');

        $data = [
            'invoice_number' => trim($row['number'] ?? ''),
            'date_iso'       => $date->format('Y-m-d'),
            'date_long'      => $date->format('j F Y'),
            'due_date_iso'   => $due->format('Y-m-d'),
            'due_date_long'  => $due->format('j F Y'),
            'client'         => trim($row['client'] ?? ''),
            'amount'         => format_aud($row['amount'] ?? 0),
            'description'    => trim($row['description'] ?? 'Night Out'),
        ];

        $html = apply_placeholders($invoiceTpl, $data);
//        $html = inject_page_css($html, 'A4', 'portrait', 50);
        // Filename: "Invoice {{invoice_number}}.pdf"
        $safeNum = preg_replace('/[^A-Za-z0-9_\-]/', '_', $data['invoice_number'] ?: 'INV');
        $file = $outDir . "/Invoice {$safeNum}.pdf";
        $number = trim($row['number'] ?? '');
        $amountRaw = $row['amount'] ?? null;
        if ($number === '' || $amountRaw === '' || $amountRaw === null) {
            $messages[] = "Invoice row " . ($index+1) . " missing required fields (date/number/amount). Skipped.";
            $index++;
            continue;
        }

        try {
            render_pdf_to_file($html, $file, 'A4', 'portrait');
            $generated[] = basename($file);
        } catch (Throwable $e) {
            $messages[] = "Invoice {$data['invoice_number']}: " . $e->getMessage();
        }
    }


    // Generate Receipts
    foreach ($recRows as $row) {
        $date = DateTime::createFromFormat('Y-m-d', trim($row['date'] ?? ''), new DateTimeZone('Australia/Brisbane'))
             ?: DateTime::createFromFormat('d/m/Y', trim($row['date'] ?? ''), new DateTimeZone('Australia/Brisbane'))
             ?: new DateTime('today', new DateTimeZone('Australia/Brisbane'));

        $data = [
            'receipt_number' => trim($row['number'] ?? ''),
            'date_iso'       => $date->format('Y-m-d'),
            'date_long'      => $date->format('j F Y'),
            'client'         => trim($row['client'] ?? ''),
            'amount'         => format_aud($row['amount'] ?? 0),
            'amount_words'   => amount_to_words_aud($row['amount'] ?? 0),
            'description'    => trim($row['description'] ?? 'Payment received'),
        ];

        $html = apply_placeholders($receiptTpl, $data);

        // Filename: "Cash Receipt {{receipt_number}}.pdf"
        $safeNum = preg_replace('/[^A-Za-z0-9_\-]/', '_', $data['receipt_number'] ?: 'REC');
        $file = $outDir . "/Cash Receipt {$safeNum}.pdf";
        $number = trim($row['number'] ?? '');
        $amountRaw = $row['amount'] ?? null;
        if ($number === '' || $amountRaw === '' || $amountRaw === null) {
            $messages[] = "Invoice row " . ($index+1) . " missing required fields (date/number/amount). Skipped.";
            $index++;
            continue;
        }
        try {
            render_pdf_to_file($html, $file, 'A4', 'landscape');
            $generated[] = basename($file);
        } catch (Throwable $e) {
            $messages[] = "Receipt {$data['receipt_number']}: " . $e->getMessage();
        }
    }

    if (!$invRows && !$recRows) {
        $messages[] = "Nothing to generate—please add at least one row.";
    }
}

/* ---------- UI ---------- */
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>Batch Invoice & Receipt PDF Generator</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">

  <!-- Bootstrap 5 (CDN) -->
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">

  <!-- Flatpickr (date picker) -->
  <link href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css" rel="stylesheet">
  <link rel="icon" type="image/svg+xml" href="assets/invoice-receipt-icon.svg">
  <style>
    body { padding: 20px; }
    .row-actions { white-space: nowrap; }
    .table input { min-width: 140px; }
    .sticky-actions { position: sticky; bottom: 0; background: #fff; padding: 10px 0; border-top: 1px solid #ddd; }
    .small-help { font-size: 0.9rem; color: #666; }
    .details-card summary {
      cursor: pointer;
      display: flex;
      align-items: center;
      gap: .5rem;
      padding: .625rem .75rem;
      border: 1px solid #e5e7eb;
      border-radius: .5rem;
      background: #f8fafc;
      user-select: none;
    }
    .details-card[open] > summary {
      border-bottom-left-radius: 0;
      border-bottom-right-radius: 0;
      border-bottom: none;
    }
    .details-card summary::-webkit-details-marker { display: none; } /* hide default arrow */
    .badge-ph { font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace; }
    /* Drag handle + row visuals */
    .grip-cell { width:32px; }
    .grip {
      cursor: grab;
      border: 0;
      background: transparent;
      font-size: 18px;
      line-height: 1;
      padding: 0 4px;
      user-select: none;
    }
    tr.dragging { opacity: .6; }
  </style>
</head>
<body>
<div class="container">
  <div class="d-flex align-items-center gap-2 mb-3">
  <img src="assets/invoice-receipt-icon.svg" alt="" width="36" height="36" class="flex-shrink-0">
  <h1 class="mb-0">Batch Invoice &amp; Receipt PDF Generator</h1>
</div>
  <p class="text-muted">Timezone: Australia/Brisbane – dates are rendered locally.</p>

  <?php if (!empty($messages)): ?>
    <div class="alert alert-warning">
      <?php foreach ($messages as $m): ?>
        <div><?= htmlspecialchars($m) ?></div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <?php if (!empty($generated)): ?>
    <div class="alert alert-success">
      <div class="mb-2">Generated files (in <code>output/</code>):</div>
      <ul class="mb-0">
        <?php foreach ($generated as $g): ?>
          <li>
            <a href="<?= 'output/' . rawurlencode($g) ?>" target="_blank" rel="noopener">
              <?= htmlspecialchars($g) ?>
            </a>
          </li>
        <?php endforeach; ?>
      </ul>
    </div>
  <?php endif; ?>

  <form method="post">
    <ul class="nav nav-tabs" id="docTabs" role="tablist">
      <li class="nav-item" role="presentation">
        <button class="nav-link active" id="invoice-tab" data-bs-toggle="tab" data-bs-target="#invoice" type="button" role="tab">Invoices</button>
      </li>
      <li class="nav-item" role="presentation">
        <button class="nav-link" id="receipt-tab" data-bs-toggle="tab" data-bs-target="#receipt" type="button" role="tab">Receipts</button>
      </li>
    </ul>

    <div class="tab-content border border-top-0 p-3">
      <!-- Invoices -->
      <div class="tab-pane fade show active" id="invoice" role="tabpanel" aria-labelledby="invoice-tab">
        <div class="d-flex justify-content-between align-items-center mb-2">
          <div>
            <button type="button" class="btn btn-sm btn-primary" id="inv-add-row">Add row</button>
            <button type="button" class="btn btn-sm btn-outline-secondary" id="inv-dup-row">Duplicate last row</button>
          </div>
          <div class="small-help">Fields: Date, Number, Client, Amount, Description.</div>
        </div>
        <div class="table-responsive">
          <table class="table table-sm align-middle" id="inv-table">
            <thead class="table-light">
              <tr>
                <th style="width:32px;" aria-label="Reorder">↕︎</th>
                <th style="width: 170px;">Date <span class="text-danger" title="Required">*</span></th>
                <th style="width: 160px;">Invoice # <span class="text-danger" title="Required">*</span></th>
                <th>Client</th>
                <th style="width: 160px;">Amount (AUD) <span class="text-danger" title="Required">*</span></th>
                <th>Description</th>
                <th class="text-end" style="width: 1%;">Actions</th>
              </tr>
            </thead>
            <tbody></tbody>
          </table>
        </div>
      </div>

      <!-- Receipts -->
      <div class="tab-pane fade" id="receipt" role="tabpanel" aria-labelledby="receipt-tab">
        <div class="d-flex justify-content-between align-items-center mb-2">
          <div>
            <button type="button" class="btn btn-sm btn-primary" id="rec-add-row">Add row</button>
            <button type="button" class="btn btn-sm btn-outline-secondary" id="rec-dup-row">Duplicate last row</button>
          </div>
          <div class="small-help">Fields: Date, Number, Client, Amount, Description.</div>
        </div>
        <div class="table-responsive">
          <table class="table table-sm align-middle" id="rec-table">
            <thead class="table-light">
              <tr>
                <th style="width:32px;" aria-label="Reorder">↕︎</th>
                <th style="width: 170px;">Date <span class="text-danger" title="Required">*</span></th>
                <th style="width: 160px;">Receipt # <span class="text-danger" title="Required">*</span></th>
                <th>Client</th>
                <th style="width: 160px;">Amount (AUD) <span class="text-danger" title="Required">*</span></th>
                <th>Description</th>
                <th class="text-end" style="width: 1%;">Actions</th>
              </tr>
            </thead>
            <tbody></tbody>
          </table>
        </div>
      </div>
    </div>

    <div class="sticky-actions mt-3">
      <button type="submit" class="btn btn-success">Generate PDFs</button>
      <button type="button" class="btn btn-outline-secondary" id="prefill-demo">Prefill weekly demo (3 rows each)</button>
    </div>
  </form>

  <hr class="my-4">
<details class="details-card">
  <summary>
    <strong>Templates &amp; Placeholders</strong>
    <span class="text-muted">(put your files in <code>templates/</code>)</span>
  </summary>

  <div class="card mt-2">
  <div class="card-body">
    <p class="mb-3">Use these tokens in <code>templates/invoice.html</code> &amp; <code>templates/receipt.html</code>. They will be replaced server-side:</p>

    <div class="row g-3">
      <div class="col-md-4">
        <div class="border rounded p-3 h-100">
          <div class="fw-semibold mb-2">Common</div>
          <ul class="list-unstyled mb-0 small">
            <li><span class="badge text-bg-light badge-ph">{{date_iso}}</span> <span class="text-muted">2025-09-01</span></li>
            <li><span class="badge text-bg-light badge-ph">{{date_long}}</span> <span class="text-muted">1 September 2025</span></li>
            <li><span class="badge text-bg-light badge-ph">{{client}}</span></li>
            <li><span class="badge text-bg-light badge-ph">{{amount}}</span> <span class="text-muted">A$120.00</span></li>
            <li><span class="badge text-bg-light badge-ph">{{description}}</span></li>
          </ul>
        </div>
      </div>

      <div class="col-md-4">
        <div class="border rounded p-3 h-100">
          <div class="fw-semibold mb-2">Invoice only</div>
          <ul class="list-unstyled mb-0 small">
            <li><span class="badge text-bg-light badge-ph">{{invoice_number}}</span></li>
            <li><span class="badge text-bg-light badge-ph">{{due_date_iso}}</span> <span class="text-muted">2025-09-08</span></li>
            <li><span class="badge text-bg-light badge-ph">{{due_date_long}}</span> <span class="text-muted">8 September 2025</span></li>
            <!-- Optional if you decide to expose amount in words on invoices too: -->
            <!-- <li><span class="badge text-bg-light badge-ph">{{amount_words}}</span></li> -->
          </ul>
        </div>
      </div>

      <div class="col-md-4">
        <div class="border rounded p-3 h-100">
          <div class="fw-semibold mb-2">Receipt only</div>
          <ul class="list-unstyled mb-0 small">
            <li><span class="badge text-bg-light badge-ph">{{receipt_number}}</span></li>
            <li><span class="badge text-bg-light badge-ph">{{amount_words}}</span> <span class="text-muted">“Two hundred dollars”</span></li>
          </ul>
        </div>
      </div>
    </div>

    <hr class="my-3">

    <div class="row g-3">
      <div class="col-md-6">
        <div class="border rounded p-3 h-100">
          <div class="fw-semibold">Minimal <code>invoice.html</code> example</div>
          <p class="text-muted mb-2 small">Shows number, date, due date, client, description, and total.</p>
          <pre class="small mb-0"><code>&lt;!doctype html&gt;
          &lt;html&gt;
          &lt;head&gt;
            &lt;meta charset="utf-8"&gt;
            &lt;title&gt;Invoice {{invoice_number}}&lt;/title&gt;
            &lt;style&gt;
              @page { size: A4 portrait; margin: 12mm; }
              body { font-family: DejaVu Sans, Arial, sans-serif; font-size: 12px; margin: 0; }
              .row { display:flex; justify-content:space-between; gap:16px; align-items:flex-start; }
              .h { margin: 0 0 6px 0; }
              .box { border:1px solid #e5e7eb; border-radius:12px; padding:16px; }
              .muted { color:#64748b; }
              .total { font-weight:700; font-size:14px; }
              hr { border:0; border-top:1px solid #e5e7eb; margin:16px 0; }
            &lt;/style&gt;
          &lt;/head&gt;
          &lt;body&gt;
            &lt;div class="row"&gt;
              &lt;div&gt;
                &lt;h2 class="h"&gt;INVOICE&lt;/h2&gt;
                &lt;div&gt;&lt;strong&gt;Invoice #:&lt;/strong&gt; {{invoice_number}}&lt;/div&gt;
                &lt;div&gt;&lt;strong&gt;Date:&lt;/strong&gt; {{date_long}}&lt;/div&gt;
                &lt;div&gt;&lt;strong&gt;Due:&lt;/strong&gt; {{due_date_long}}&lt;/div&gt;
              &lt;/div&gt;
              &lt;div class="box"&gt;
                &lt;div class="muted"&gt;Bill To&lt;/div&gt;
                &lt;div&gt;{{client}}&lt;/div&gt;
              &lt;/div&gt;
            &lt;/div&gt;
            &lt;hr&gt;
            &lt;p&gt;&lt;strong&gt;Description:&lt;/strong&gt; {{description}}&lt;/p&gt;
            &lt;p class="total"&gt;Total: {{amount}}&lt;/p&gt;
          &lt;/body&gt;
          &lt;/html&gt;</code></pre>
        </div>
      </div>

      <div class="col-md-6">
        <div class="border rounded p-3 h-100">
          <div class="fw-semibold">Minimal <code>receipt.html</code> example</div>
          <p class="text-muted mb-2 small">Landscape A4, includes amount in words.</p>
          <pre class="small mb-0"><code>&lt;!doctype html&gt;
          &lt;html&gt;
          &lt;head&gt;
            &lt;meta charset="utf-8"&gt;
            &lt;title&gt;Receipt {{receipt_number}}&lt;/title&gt;
            &lt;style&gt;
              @page { size: A4 landscape; margin: 10mm; }
              html, body { margin:0; padding:0; width:297mm; min-height:210mm; box-sizing:border-box; }
              body { font-family: DejaVu Sans, Arial, sans-serif; font-size: 12px; }
              .row { display:flex; justify-content:space-between; gap:16px; align-items:flex-start; }
              .h { margin: 0 0 6px 0; }
              .box { border:1px solid #e5e7eb; border-radius:12px; padding:16px; }
              .muted { color:#64748b; }
              .total { font-weight:700; font-size:14px; }
              hr { border:0; border-top:1px solid #e5e7eb; margin:16px 0; }
              img { max-width:100%; height:auto; }
              table { width:100%; table-layout:fixed; }
            &lt;/style&gt;
          &lt;/head&gt;
          &lt;body&gt;
            &lt;div class="row"&gt;
              &lt;div&gt;
                &lt;h2 class="h"&gt;RECEIPT&lt;/h2&gt;
                &lt;div&gt;&lt;strong&gt;Receipt #:&lt;/strong&gt; {{receipt_number}}&lt;/div&gt;
                &lt;div&gt;&lt;strong&gt;Date:&lt;/strong&gt; {{date_long}}&lt;/div&gt;
              &lt;/div&gt;
              &lt;div class="box"&gt;
                &lt;div class="muted"&gt;Client&lt;/div&gt;
                &lt;div&gt;{{client}}&lt;/div&gt;
              &lt;/div&gt;
            &lt;/div&gt;
            &lt;hr&gt;
            &lt;p&gt;&lt;strong&gt;Description:&lt;/strong&gt; {{description}}&lt;/p&gt;
            &lt;p class="total"&gt;Amount: {{amount}}&lt;/p&gt;
            &lt;p&gt;&lt;em&gt;Amount in words: {{amount_words}}&lt;/em&gt;&lt;/p&gt;
          &lt;/body&gt;
          &lt;/html&gt;</code></pre>
                  </div>
                </div>
              </div>
            </div>
          </div>

</div>
 </div>
  </div>
</details>

<!-- JS: Bootstrap + flatpickr -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
<script>
(function(){
  // --- Utilities ---
  function makeDateInput(name, required=true){ 
    const inp = document.createElement('input');
    inp.type = 'text';
    inp.className = 'form-control datepick';
    inp.name = name;
    inp.placeholder = 'YYYY-MM-DD';
    if (required) inp.required = true;
    return inp;
  }
  function makeInput(type, name, placeholder='', required=false){
    const inp = document.createElement('input');
    inp.type = type;
    inp.className = 'form-control';
    inp.name = name;
    if (placeholder) inp.placeholder = placeholder;
    if (required) inp.required = true;
    if (type === 'number') { inp.step = '0.01'; inp.min = '0'; }
    return inp;
  }

  function makeGripCell(tr){
    const td = document.createElement('td');
    td.className = 'grip-cell';
    const btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'grip';
    btn.setAttribute('aria-label', 'Drag to reorder');
    btn.textContent = '⠿'; // grip glyph
    // Enable drag only while grabbing the handle
    btn.addEventListener('mousedown', () => { tr._dragEnabled = true; tr.draggable = true; });
    btn.addEventListener('mouseup',   () => { tr._dragEnabled = false; tr.draggable = false; });
    td.appendChild(btn);
    return td;
  }

  function makeActionsCell(){
    const td = document.createElement('td');
    td.className = 'text-end row-actions';
    td.innerHTML = `
      <div class="btn-group btn-group-sm" role="group">
        <button type="button" class="btn btn-outline-danger del-row" title="Remove">Remove</button>
      </div>`;
    return td;
  }

  function initDatepickers(scope){
    (scope || document).querySelectorAll('.datepick').forEach(el => {
      if (el._flatpickr) return;
      flatpickr(el, { dateFormat: 'Y-m-d', allowInput: true });
    });
  }

  // --- Row builder ---
  function addRow(tableBody, group, preset){
    const idx = tableBody.querySelectorAll('tr').length;
    const tr = document.createElement('tr');

    tr.appendChild(makeGripCell(tr));

    const tdDate = document.createElement('td');
    tdDate.appendChild(makeDateInput(`${group}[${idx}][date]`, true));

    const tdNum = document.createElement('td');
    tdNum.appendChild(makeInput('text', `${group}[${idx}][number]`, group==='invoice'?'INV-1001':'REC-1001', true));

    const tdClient = document.createElement('td');
    tdClient.appendChild(makeInput('text', `${group}[${idx}][client]`, 'Acme Pty Ltd'));

    const tdAmt = document.createElement('td');
    tdAmt.appendChild(makeInput('number', `${group}[${idx}][amount]`, '120.00', true));

    const tdDesc = document.createElement('td');
    tdDesc.appendChild(makeInput('text', `${group}[${idx}][description]`, group==='invoice'?'Weekly service fee':'Payment received'));

    const tdAct = makeActionsCell();

    tr.appendChild(tdDate);
    tr.appendChild(tdNum);
    tr.appendChild(tdClient);
    tr.appendChild(tdAmt);
    tr.appendChild(tdDesc);
    tr.appendChild(tdAct);

    tableBody.appendChild(tr);

    if (preset){
      tr.querySelector(`[name="${group}[${idx}][date]"]`).value = preset.date || '';
      tr.querySelector(`[name="${group}[${idx}][number]"]`).value = preset.number || '';
      tr.querySelector(`[name="${group}[${idx}][client]"]`).value = preset.client || '';
      tr.querySelector(`[name="${group}[${idx}][amount]"]`).value = preset.amount || '';
      tr.querySelector(`[name="${group}[${idx}][description]"]`).value = preset.description || '';
    }
    initDatepickers(tr);
  }

  function dupLastRow(tableBody, group){
    const rows = tableBody.querySelectorAll('tr');
    const last = rows[rows.length - 1];
    if (!last) { addRow(tableBody, group); return; }
    const getVal = sel => (last.querySelector(sel)?.value || '');
    const preset = {
      date: getVal('input[name$="[date]"]'),
      number: getVal('input[name$="[number]"]'),
      client: getVal('input[name$="[client]"]'),
      amount: getVal('input[name$="[amount]"]'),
      description: getVal('input[name$="[description]"]'),
    };
    addRow(tableBody, group, preset);
  }

  // Keep PHP indexes in the same order as visible rows
  function reindexTable(tableBody, group){
    const rows = tableBody.querySelectorAll('tr');
    rows.forEach((tr, i) => {
      tr.querySelectorAll('input').forEach(inp => {
        const m = inp.name.match(/\[(date|number|client|amount|description)\]$/);
        if (m) inp.name = `${group}[${i}][${m[1]}]`;
      });
    });
  }

  // --- Drag & drop (native HTML5) on a table body ---
  function enableDnD(tableBody){
    tableBody.addEventListener('dragstart', (e) => {
      const tr = e.target.closest('tr');
      if (!tr || !tr._dragEnabled) { e.preventDefault(); return; }
      tr.classList.add('dragging');
      e.dataTransfer.effectAllowed = 'move';
      // Some browsers need data to start DnD
      e.dataTransfer.setData('text/plain', 'drag');
    });

    tableBody.addEventListener('dragend', (e) => {
      const tr = e.target.closest('tr');
      if (tr) {
        tr.classList.remove('dragging');
        tr._dragEnabled = false;
        tr.draggable = false;
      }
    });

    tableBody.addEventListener('dragover', (e) => {
      e.preventDefault();
      const dragging = tableBody.querySelector('tr.dragging');
      if (!dragging) return;

      // Find the row we are hovering over and insert before/after by midpoint
      const rows = [...tableBody.querySelectorAll('tr:not(.dragging)')];
      let insertBefore = null;
      for (const row of rows) {
        const rect = row.getBoundingClientRect();
        const midpoint = rect.top + rect.height / 2;
        if (e.clientY < midpoint) { insertBefore = row; break; }
      }
      if (insertBefore) tableBody.insertBefore(dragging, insertBefore);
      else tableBody.appendChild(dragging);
    });
  }

  // --- Wire up both tables ---
  const invBody = document.querySelector('#inv-table tbody');
  const invAdd = document.getElementById('inv-add-row');
  const invDup = document.getElementById('inv-dup-row');

  invAdd.addEventListener('click', () => addRow(invBody, 'invoice'));
  invDup.addEventListener('click', () => dupLastRow(invBody, 'invoice'));
  invBody.addEventListener('click', e => {
    if (e.target.classList.contains('del-row')) e.target.closest('tr').remove();
  });
  enableDnD(invBody);

  const recBody = document.querySelector('#rec-table tbody');
  const recAdd = document.getElementById('rec-add-row');
  const recDup = document.getElementById('rec-dup-row');

  recAdd.addEventListener('click', () => addRow(recBody, 'receipt'));
  recDup.addEventListener('click', () => dupLastRow(recBody, 'receipt'));
  recBody.addEventListener('click', e => {
    if (e.target.classList.contains('del-row')) e.target.closest('tr').remove();
  });
  enableDnD(recBody);

  // Form validation + reindex before submit
  const form = document.querySelector('form');
  form.setAttribute('novalidate', 'novalidate');
  form.addEventListener('submit', (e) => {
    reindexTable(invBody, 'invoice');
    reindexTable(recBody, 'receipt');

    if (!form.checkValidity()) {
      e.preventDefault();
      e.stopPropagation();
      form.classList.add('was-validated');
    }
  });

  // Demo + initial blank rows (unchanged)
  document.getElementById('prefill-demo').addEventListener('click', () => {
    invBody.innerHTML = '';
    recBody.innerHTML = '';
    const today = new Date();
    const dow = today.getDay();
    const daysToMon = (1 - dow + 7) % 7;
    const start = new Date(today.getFullYear(), today.getMonth(), today.getDate() + daysToMon);
    function fmt(d){ const z=m=>m<10?'0'+m:m; return d.getFullYear()+'-'+z(d.getMonth()+1)+'-'+z(d.getDate()); }
    for (let i=0;i<3;i++){
      const d = new Date(start.getFullYear(), start.getMonth(), start.getDate() + 7*i);
      addRow(invBody, 'invoice', {
        date: fmt(d), number: 'INV-10' + (i+1),
        client: 'Acme Pty Ltd', amount: (120 + i*5).toFixed(2),
        description: 'Weekly service fee'
      });
      addRow(recBody, 'receipt', {
        date: fmt(d), number: 'REC-20' + (i+1),
        client: 'Acme Pty Ltd', amount: (120 + i*5).toFixed(2),
        description: 'Payment received'
      });
    }
  });

  // Start with one blank row each
  addRow(invBody, 'invoice');
  addRow(recBody, 'receipt');

  initDatepickers(document);
})();
</script>
</body>
</html>

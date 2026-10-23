<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Haven Suites Investor Report</title>
<style>
@page { size: A4 portrait; margin: 16mm 12mm 19mm; }
body { margin:0; font-family:DejaVu Sans, Arial, sans-serif; font-size:8.3px; line-height:1.4; color:#25364b; }
.actions {display:none} .sheet {width:100%}
.masthead {width:100%; border-bottom:3px solid #ec914d; margin-bottom:13px; padding-bottom:10px;}
.header-table {width:100%;border:0;margin:0} .header-table td {border:0;padding:0;vertical-align:middle}
.brand-logo {width:230px;height:auto} .brand-fallback {font-size:20px;font-weight:bold;color:#1d4484}
.report-tag {text-align:right} .eyebrow {font-size:8px;letter-spacing:1px;color:#ec914d;font-weight:bold}
.report-title {font-size:15px;color:#1d4484;font-weight:bold} .period {color:#64748b;font-size:8px}
.identity {background:#f3f7fc;border-left:4px solid #1d4484;padding:10px 12px;margin:12px 0}
.identity .name {font-size:13px;color:#1d4484;font-weight:bold}.identity .detail {font-size:8px;color:#64748b}
h2 {font-size:10px;color:#1d4484;border-bottom:1px solid #d9e3ee;padding-bottom:5px;margin:17px 0 8px;page-break-after:avoid}
.metric-table {width:100%;border-collapse:separate;border-spacing:5px;margin:0 0 10px;table-layout:fixed}
.metric-table td {border:1px solid #d9e3ee;border-top:3px solid #1d4484;padding:9px;vertical-align:top;width:33.33%}
.metric-table td.accent {border-top-color:#ec914d;background:#fffaf6}
.metric .label {font-size:7px;color:#607187}.metric .value {font-size:11px;font-weight:bold;color:#1d4484;margin-top:4px}
table.data {width:100%;border-collapse:collapse;font-size:7.1px;margin:5px 0 12px;table-layout:auto}
table.data thead {display:table-header-group} table.data th {background:#1d4484;color:white;text-align:left}
table.data th,table.data td {border:1px solid #d9e3ee;padding:5px 4px;vertical-align:top;word-wrap:break-word}
table.data tr {page-break-inside:avoid}table.data tbody tr:nth-child(even){background:#f5f8fc}
.right{text-align:right;white-space:nowrap}.total td{font-weight:bold;background:#e9f1fb;color:#1d4484}
.warning{background:#fff8eb;border:1px solid #e5ad55;padding:9px;margin:8px 0}
.note{font-size:7.5px;color:#64748b;border-left:3px solid #ec914d;padding-left:8px;margin-top:13px}
.footer {font-size:7px;color:#718096;border-top:1px solid #d9e3ee;margin-top:16px;padding-top:7px}
.footer a{color:inherit;text-decoration:none}
</style></head><body>
<?php $fmt = static fn($c) => number_format($c/100,2); $unitLabel = trim(($selected['property_name'] ?? '') . ' / ' . ($selected['unit_number'] ?? ''), ' /'); ?>

<main class="sheet">
<header class="masthead"><table class="header-table"><tr><td style="width:52%">
<?php if (!empty($logoDataUri)): ?><img class="brand-logo" src="<?= esc($logoDataUri, 'attr') ?>" alt="Haven Suites">
<?php else: ?><div class="brand-fallback">HAVEN SUITES</div><?php endif; ?>
</td><td><div class="report-tag"><div class="eyebrow">INVESTOR FINANCIAL REPORT</div><div class="report-title">Owner Statement</div><div class="period">KES · <?= esc($fromMonth) ?> to <?= esc($toMonth) ?></div></div></td></tr></table></header>
<section class="identity"><div class="name"><?= esc($unitLabel) ?></div><div class="detail">Reporting period: <?= esc($fromMonth) ?> to <?= esc($toMonth) ?> · Currency: Kenyan Shillings (KES)</div></section>
<?php if ($issues): ?><div class="warning"><strong>PRELIMINARY — REVIEW REQUIRED</strong><ul><?php foreach ($issues as $issue): ?><li><?= esc($issue) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
<h2>Financial summary</h2><table class="metric-table"><tr>
<?php $metricLabels = ['revenue'=>'Booking revenue','expenses'=>'Operating expenses','fee'=>'Management fee','net'=>'Net investor entitlement','remitted'=>'Owner remittances','remaining'=>'Report-period difference']; $metricKeys = array_keys($metricLabels); ?>
<?php foreach ($metricKeys as $i=>$key): ?>
<?php if ($i === 3): ?></tr><tr><?php endif; ?>
<td class="<?= in_array($key, ['net','remaining'], true) ? 'accent' : '' ?>"><div class="metric"><div class="label"><?= esc($metricLabels[$key]) ?></div><div class="value">KES <?= $fmt($totals[$key]) ?></div></div></td>
<?php endforeach; ?></tr></table>
<h2>Monthly summary</h2><table class="data"><thead><tr><th>Month</th><th class="right">Nights</th><th class="right">Revenue</th><th class="right">Expenses</th><th class="right">Fee</th><th class="right">Net payable</th><th class="right">Remitted</th><th class="right">Difference</th></tr></thead><tbody><?php foreach ($months as $month=>$row): ?><tr><td><?= esc($month) ?></td><td class="right"><?= (int)$row['nights'] ?></td><?php foreach (['revenue','expenses','fee','net','remitted','remaining'] as $key): ?><td class="right"><?= $fmt($row[$key]) ?></td><?php endforeach; ?></tr><?php endforeach; ?><tr class="total"><td>TOTAL</td><td class="right"><?= (int)$totals['nights'] ?></td><?php foreach (['revenue','expenses','fee','net','remitted','remaining'] as $key): ?><td class="right"><?= $fmt($totals[$key]) ?></td><?php endforeach; ?></tr></tbody></table>
<h2>Booking details by stay month</h2><table class="data"><thead><tr><th>Month</th><th>Booking</th><th>Guest</th><th>Check-in</th><th>Check-out</th><th class="right">Nights</th><th class="right">Revenue</th></tr></thead><tbody><?php foreach ($bookings as $b): ?><tr><td><?= esc($b['month']) ?></td><td>#<?= (int)$b['id'] ?></td><td><?= esc($b['guest']) ?></td><td><?= esc($b['check_in']) ?></td><td><?= esc($b['check_out']) ?></td><td class="right"><?= (int)$b['nights'] ?></td><td class="right"><?= esc($b['revenue']) ?></td></tr><?php endforeach; ?><?php if (!$bookings): ?><tr><td colspan="7">No eligible bookings.</td></tr><?php endif; ?></tbody></table>
<h2>Operating expenses</h2><table class="data"><thead><tr><th>Date</th><th>Category</th><th>Description</th><th class="right">Amount</th></tr></thead><tbody><?php foreach ($expenses as $e): ?><tr><td><?= esc($e['date']) ?></td><td><?= esc($e['category']) ?></td><td><?= esc($e['description']) ?></td><td class="right"><?= esc($e['amount']) ?></td></tr><?php endforeach; ?><?php if (!$expenses): ?><tr><td colspan="4">No expenses recorded.</td></tr><?php endif; ?></tbody></table>
<h2>Owner remittances</h2><table class="data"><thead><tr><th>Accounting month</th><th>Payment date</th><th>Method</th><th>Reference</th><th class="right">Amount</th></tr></thead><tbody><?php foreach ($remittances as $p): ?><tr><td><?= esc($p['month']) ?></td><td><?= esc($p['date']) ?></td><td><?= esc($p['method']) ?></td><td><?= esc($p['reference'] ?? '') ?></td><td class="right"><?= esc($p['amount']) ?></td></tr><?php endforeach; ?><?php if (!$remittances): ?><tr><td colspan="5">No remittances allocated to this period.</td></tr><?php endif; ?></tbody></table>
<p class="note"><strong>Accounting basis:</strong> Booking revenue is allocated to elapsed stay nights, expenses to expense date, and remittances to their assigned accounting month. Report-period difference excludes opening balances and carried deficits. This report is not the official cash-based owner remittance ledger.</p>
<footer class="footer"><span>HAVEN SUITES · Investor financial report<br>Phone: <a href="tel:+254703898693">0703 898 693</a> &nbsp;|&nbsp; Email: <a href="mailto:info@havensuitesbnb.com">info@havensuitesbnb.com</a></span><span>Generated <?= esc(date('d M Y')) ?></span></footer>
</main>
</body></html>

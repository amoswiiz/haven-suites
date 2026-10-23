<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Haven Suites Investor Report</title>
<style>
@page { size: A4; margin: 17mm 13mm 18mm; }
* { box-sizing: border-box; }
body { margin: 0; font: 10.5px/1.5 Arial, Helvetica, sans-serif; color: #25364b; background: #fff; }
:root { --navy:#1d4484; --orange:#ec914d; --ink:#25364b; --line:#d9e3ee; }
.actions { max-width: 1000px; margin: 12px auto 18px; display:flex; gap:10px; }
.actions button { background:var(--navy); color:white; border:0; padding:10px 18px; border-radius:5px; cursor:pointer; }
.sheet { max-width: 1000px; margin:0 auto; }
.masthead { display:flex; align-items:center; justify-content:space-between; gap:22px; border-bottom:3px solid var(--orange); padding:0 0 13px; margin-bottom:18px; }
.brand-logo { display:block; width:265px; max-width:100%; height:auto; }
.brand-fallback { display:none; color:var(--navy); font-weight:800; font-size:23px; }
.report-tag { text-align:right; border-left:2px solid var(--line); padding-left:16px; }
.eyebrow { color:var(--orange); font-weight:bold; font-size:10px; letter-spacing:1.5px; text-transform:uppercase; }
.report-title { font-size:18px; font-weight:800; color:var(--navy); margin-top:4px; }
.period { color:#65758b; margin-top:3px; }
.identity { padding:13px 15px; background:#f3f7fc; border-left:4px solid var(--navy); margin:0 0 18px; }
.identity .name { font-size:17px; font-weight:700; color:var(--navy); }
.identity .detail { color:#5e7086; margin-top:3px; }
h2 { font-size:12px; letter-spacing:.2px; color:var(--navy); border-bottom:1px solid var(--line); padding-bottom:7px; margin:23px 0 11px; break-after:avoid; }
.grid { display:grid; grid-template-columns:repeat(3,minmax(0,1fr)); gap:9px; margin:12px 0 18px; }
.metric { border:1px solid var(--line); border-top:3px solid var(--navy); border-radius:3px; padding:10px; break-inside:avoid; }
.metric.accent { border-top-color:var(--orange); background:#fffaf6; }
.metric .label { color:#607187; font-size:9.5px; }
.metric .value { color:var(--navy); font-size:16px; font-weight:800; margin-top:5px; }
table { border-collapse:collapse; width:100%; font-size:9.4px; margin:7px 0 16px; }
thead { display:table-header-group; }
th { background:var(--navy); color:#fff; text-align:left; font-weight:700; }
td,th { padding:7px 6px; border:1px solid var(--line); vertical-align:top; overflow-wrap:anywhere; }
tr { break-inside:avoid; page-break-inside:avoid; }
tbody tr:nth-child(even) { background:#f5f8fc; }
.right { text-align:right; white-space:nowrap; }
.total td { font-weight:800; background:#e9f1fb; color:var(--navy); border-top:2px solid var(--navy); }
.warning { border:1px solid #e5ad55; background:#fff8eb; padding:12px; margin:12px 0; }
.warning strong { color:#92581a; }
.note { font-size:9.5px; color:#64748b; margin-top:17px; border-left:3px solid var(--orange); padding-left:10px; }
.footer a { color:inherit; text-decoration:none; }
.footer { margin-top:24px; border-top:1px solid var(--line); padding-top:9px; color:#718096; font-size:9px; display:flex; justify-content:space-between; }
@media print {
 .actions { display:none !important; }
 .sheet { max-width:none; }
 body { print-color-adjust:exact; -webkit-print-color-adjust:exact; }
 h2 { break-after:avoid-page; }
 .masthead, .identity, .grid, .note { break-inside:avoid; }
}
@media screen and (max-width:650px) { .masthead{flex-direction:column;align-items:flex-start}.report-tag{text-align:left;border-left:0;padding:0}.grid{grid-template-columns:repeat(2,1fr)}.sheet{padding:0 12px} }
</style></head><body>
<?php $fmt = static fn($c) => number_format($c/100,2); $unitLabel = trim(($selected['property_name'] ?? '') . ' / ' . ($selected['unit_number'] ?? ''), ' /'); ?>
<div class="actions"><button onclick="window.print()">Print / Save as PDF</button></div>
<main class="sheet">
<header class="masthead">
  <div><img class="brand-logo" src="<?= esc(base_url('assets/img/haven-suites-logo.png'), 'attr') ?>" alt="Haven Suites — BNBs and STR Homes" onerror="this.style.display='none';this.nextElementSibling.style.display='block'"><span class="brand-fallback">HAVEN SUITES</span></div>
  <div class="report-tag"><div class="eyebrow">Investor financial report</div><div class="report-title">Owner Statement</div><div class="period">KES · <?= esc($fromMonth) ?> to <?= esc($toMonth) ?></div></div>
</header>
<section class="identity"><div class="name"><?= esc($unitLabel) ?></div><div class="detail">Reporting period: <?= esc($fromMonth) ?> to <?= esc($toMonth) ?> · Currency: Kenyan Shillings (KES)</div></section>
<?php if ($issues): ?><div class="warning"><strong>PRELIMINARY — REVIEW REQUIRED</strong><ul><?php foreach ($issues as $issue): ?><li><?= esc($issue) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
<h2>Financial summary</h2><div class="grid">
<?php foreach (['revenue'=>'Booking revenue','expenses'=>'Operating expenses','fee'=>'Management fee','net'=>'Net investor entitlement','remitted'=>'Owner remittances','remaining'=>'Report-period difference'] as $key=>$label): ?><div class="metric <?= in_array($key, ['net', 'remaining'], true) ? 'accent' : '' ?>"><div class="label"><?= esc($label) ?></div><div class="value">KES <?= $fmt($totals[$key]) ?></div></div><?php endforeach; ?></div>
<h2>Monthly summary</h2><table><thead><tr><th>Month</th><th class="right">Nights</th><th class="right">Revenue</th><th class="right">Expenses</th><th class="right">Fee</th><th class="right">Net payable</th><th class="right">Remitted</th><th class="right">Difference</th></tr></thead><tbody><?php foreach ($months as $month=>$row): ?><tr><td><?= esc($month) ?></td><td class="right"><?= (int)$row['nights'] ?></td><?php foreach (['revenue','expenses','fee','net','remitted','remaining'] as $key): ?><td class="right"><?= $fmt($row[$key]) ?></td><?php endforeach; ?></tr><?php endforeach; ?><tr class="total"><td>TOTAL</td><td class="right"><?= (int)$totals['nights'] ?></td><?php foreach (['revenue','expenses','fee','net','remitted','remaining'] as $key): ?><td class="right"><?= $fmt($totals[$key]) ?></td><?php endforeach; ?></tr></tbody></table>
<h2>Booking details by stay month</h2><table><thead><tr><th>Month</th><th>Booking</th><th>Guest</th><th>Check-in</th><th>Check-out</th><th class="right">Nights</th><th class="right">Revenue</th></tr></thead><tbody><?php foreach ($bookings as $b): ?><tr><td><?= esc($b['month']) ?></td><td>#<?= (int)$b['id'] ?></td><td><?= esc($b['guest']) ?></td><td><?= esc($b['check_in']) ?></td><td><?= esc($b['check_out']) ?></td><td class="right"><?= (int)$b['nights'] ?></td><td class="right"><?= esc($b['revenue']) ?></td></tr><?php endforeach; ?><?php if (!$bookings): ?><tr><td colspan="7">No eligible bookings.</td></tr><?php endif; ?></tbody></table>
<h2>Operating expenses</h2><table><thead><tr><th>Date</th><th>Category</th><th>Description</th><th class="right">Amount</th></tr></thead><tbody><?php foreach ($expenses as $e): ?><tr><td><?= esc($e['date']) ?></td><td><?= esc($e['category']) ?></td><td><?= esc($e['description']) ?></td><td class="right"><?= esc($e['amount']) ?></td></tr><?php endforeach; ?><?php if (!$expenses): ?><tr><td colspan="4">No expenses recorded.</td></tr><?php endif; ?></tbody></table>
<h2>Owner remittances</h2><table><thead><tr><th>Accounting month</th><th>Payment date</th><th>Method</th><th>Reference</th><th class="right">Amount</th></tr></thead><tbody><?php foreach ($remittances as $p): ?><tr><td><?= esc($p['month']) ?></td><td><?= esc($p['date']) ?></td><td><?= esc($p['method']) ?></td><td><?= esc($p['reference'] ?? '') ?></td><td class="right"><?= esc($p['amount']) ?></td></tr><?php endforeach; ?><?php if (!$remittances): ?><tr><td colspan="5">No remittances allocated to this period.</td></tr><?php endif; ?></tbody></table>
<p class="note"><strong>Accounting basis:</strong> Booking revenue is allocated to elapsed stay nights, expenses to expense date, and remittances to their assigned accounting month. Report-period difference excludes opening balances and carried deficits. This report is not the official cash-based owner remittance ledger.</p>
<footer class="footer"><span>HAVEN SUITES · Investor financial report<br>Phone: <a href="tel:+254703898693">0703 898 693</a> &nbsp;|&nbsp; Email: <a href="mailto:info@havensuitesbnb.com">info@havensuitesbnb.com</a></span><span>Generated <?= esc(date('d M Y')) ?></span></footer>
</main>
</body></html>

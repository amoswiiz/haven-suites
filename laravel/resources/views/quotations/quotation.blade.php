<?php
/**
 * Haven Suites quotation display template (CodeIgniter 4)
 * Install: app/Views/quotations/quotation.php
 *
 * Required controller data: $quote associative array. All text is escaped.
 * Do not pass sensitive guest identity documents into public views.
 */
$quote = $quote ?? [];
$h = static fn ($value) => esc((string) ($value ?? ''));
$money = static fn ($value) => 'KES ' . number_format((float) ($value ?? 0), 0);
$number = $quote['number'] ?? 'DRAFT';
$issued = $quote['issued_date'] ?? '';
$valid = $quote['valid_until'] ?? '';
$property = $quote['property_name'] ?? '';
$location = $quote['location'] ?? '';
$checkin = $quote['checkin'] ?? '';
$checkout = $quote['checkout'] ?? '';
$checkinTime = $quote['checkin_time'] ?? '';
$checkoutTime = $quote['checkout_time'] ?? '';
$nights = (int) ($quote['nights'] ?? 0);
$rate = (float) ($quote['nightly_rate'] ?? 0);
$total = (float) ($quote['total'] ?? ($nights * $rate));
$depositPct = (float) ($quote['deposit_percent'] ?? 50);
$deposit = (float) ($quote['deposit_amount'] ?? round($total * $depositPct / 100));
$paid = (float) ($quote['confirmed_paid'] ?? 0);
$balance = max(0, $total - $paid);
$payment = $quote['payment_method'] ?? [];
$agent = $quote['agent_name'] ?? '';
$phone = $quote['contact_phone'] ?? '';
$logo = $quote['logo_url'] ?? base_url('assets/images/logo.png');
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= $h($number) ?> | Haven Suites</title>
<style>
*{box-sizing:border-box}body{margin:0;background:#f1f3f6;color:#243253;font:16px/1.5 Arial,Helvetica,sans-serif}.sheet{max-width:940px;margin:28px auto;padding:16px;background:#f1f3f6;border-radius:26px}.header{background:#243253;color:#fff;padding:24px 30px;border-radius:20px 20px 0 0;display:flex;align-items:center;gap:17px}.logo{width:60px;height:60px;object-fit:contain}.brand{font-size:29px;font-weight:700;letter-spacing:.5px}.tagline{color:#f3a45a;font-size:13px;letter-spacing:.8px}.content{background:#fff;padding:27px 30px 30px;border-radius:0 0 20px 20px}.row{display:flex;justify-content:space-between;gap:20px;align-items:flex-start}.muted{color:#6c7c95}.label{font-size:13px;color:#6c7c95;text-transform:uppercase;letter-spacing:.3px}.quote-number{font-size:24px;font-weight:700}.section{border-top:1px solid #dce3ed;margin-top:23px;padding-top:22px}.property{font-size:22px;font-weight:700;margin:5px 0 0}.dates{display:grid;grid-template-columns:1fr 1fr;gap:20px;margin-top:24px}.date-value{font-size:20px;font-weight:700}.charge{font-size:18px}.total{margin-top:15px;background:#eef2f7;border-radius:11px;padding:14px 17px;font-size:21px;font-weight:700}.payment{background:#eef5fc;border-radius:12px;padding:18px;margin-top:17px}.payment-grid{display:grid;grid-template-columns:1fr 1fr;gap:20px}.payment-title{font-weight:700;margin-bottom:9px}.payment-line{display:flex;justify-content:space-between;gap:12px}.small{font-size:13px}.footer{display:flex;justify-content:space-between;gap:20px;margin-top:21px;color:#61728c;font-size:14px}.agent{font-weight:700;color:#243253}.actions{max-width:940px;margin:12px auto 28px;display:flex;gap:10px;flex-wrap:wrap}.btn{background:#243253;color:white;text-decoration:none;padding:10px 17px;border-radius:8px;border:0;cursor:pointer;font:inherit}.btn.secondary{background:#e2e7ef;color:#243253}@media(max-width:620px){.sheet{margin:0;padding:8px;border-radius:0}.header{padding:18px}.brand{font-size:23px}.content{padding:21px 18px}.payment-grid{grid-template-columns:1fr}.footer{flex-direction:column;gap:5px}.quote-number{font-size:20px}.row{gap:10px}}@media print{body,.sheet{background:#fff}.sheet{margin:0;padding:0;max-width:none}.actions{display:none}.header,.total,.payment{-webkit-print-color-adjust:exact;print-color-adjust:exact}}
</style>
</head>
<body>
<div class="sheet" id="quotation-card">
  <header class="header">
    <img class="logo" src="<?= $h($logo) ?>" alt="Haven Suites logo" onerror="this.style.display='none'">
    <div><div class="brand">HAVEN SUITES</div><div class="tagline">BNBs AND STR HOMES</div></div>
  </header>
  <main class="content">
    <div class="row">
      <div><div class="label">Accommodation Quotation</div><div class="quote-number"><?= $h($number) ?></div></div>
      <div style="text-align:right" class="muted"><div><?= $h($issued) ?></div><?php if ($valid !== ''): ?><div class="small">Valid until <?= $h($valid) ?></div><?php endif; ?></div>
    </div>
    <div class="section"><div class="label">Property</div><div class="property"><?= $h($property) ?></div><div class="muted"><?= $h($location) ?></div>
      <div class="dates"><div><div class="label">Check-in</div><div class="date-value"><?= $h($checkin) ?></div><div class="muted small"><?= $h($checkinTime) ?></div></div><div><div class="label">Check-out</div><div class="date-value"><?= $h($checkout) ?></div><div class="muted small"><?= $h($checkoutTime) ?></div></div></div>
    </div>
    <div class="section"><div class="row charge"><span><?= $h($nights) ?> night<?= $nights === 1 ? '' : 's' ?> × <?= $h($money($rate)) ?></span><strong><?= $h($money($total)) ?></strong></div>
      <div class="row total"><span>TOTAL AMOUNT</span><span><?= $h($money($total)) ?></span></div>
      <div class="row" style="margin-top:14px"><span>Commitment deposit (<?= $h($depositPct) ?>%)</span><strong><?= $h($money($deposit)) ?></strong></div>
      <div class="row"><span>Confirmed payments</span><strong><?= $h($money($paid)) ?></strong></div>
      <div class="row"><span>Outstanding balance</span><strong><?= $h($money($balance)) ?></strong></div>
    </div>
    <div class="payment"><div class="payment-grid"><div><div class="payment-title">PAYMENT DETAILS<?= !empty($payment['type']) ? ' (' . $h($payment['type']) . ')' : '' ?></div>
      <?php foreach (($payment['details'] ?? []) as $label => $value): ?><div class="payment-line"><span><?= $h($label) ?>:</span><strong><?= $h($value) ?></strong></div><?php endforeach; ?></div>
      <div class="small muted"><?= $h($quote['payment_instructions'] ?? 'Kindly forward your payment confirmation message for verification. A receipt will be issued after confirmation.') ?></div></div></div>
    <?php if (!empty($is_public)): ?>
    <div class="section" style="background:#f7f9fd;padding:18px;border-radius:12px">
      <strong>SUBMIT PAYMENT CONFIRMATION</strong>
      <p class="small muted">After paying, paste your complete M-Pesa SMS. Your payment remains unverified until our team checks it.</p>
      <?php if (!empty($notice)): ?><p style="color:#14733a"><?= $h($notice) ?></p><?php endif; ?>
      <?php if (!empty($error)): ?><p style="color:#ad2525"><?= $h($error) ?></p><?php endif; ?>
      <?php if (haven_can_access(esc(site_url('q/'.$token.'/payment'),'attr'), 'POST')): ?><form method="post" action="<?= esc(site_url('q/'.$token.'/payment'),'attr') ?>">
        <?= csrf_field() ?>
        <label for="amount">Amount paid (KES)</label>
        <input id="amount" name="amount" type="number" min="1" step="0.01" required style="display:block;width:100%;padding:12px;margin:6px 0 12px;border:1px solid #ccd5e1;border-radius:8px">
        <label for="mpesa_message">M-Pesa confirmation message</label>
        <textarea id="mpesa_message" name="mpesa_message" minlength="20" maxlength="2000" required rows="4" placeholder="Paste your complete M-Pesa SMS here" style="display:block;width:100%;padding:12px;margin:6px 0 12px;border:1px solid #ccd5e1;border-radius:8px"></textarea>
        <button type="submit" class="btn">Submit for Verification</button>
      </form><?php endif; ?>
    </div>
    <div class="section"><strong>GUEST VERIFICATION (KYC)</strong><p class="small muted">Please complete your guest verification form before check-in.</p><a class="btn" href="<?= esc($quote['kyc_url'] ?? '#','attr') ?>" target="_blank" rel="noopener noreferrer">Complete Guest Verification Form</a></div>
    <?php endif; ?>
    <div class="footer"><div><?php if ($phone): ?>☎ <?= $h($phone) ?><?php endif; ?><?php if ($agent): ?><div>Prepared by: <span class="agent"><?= $h($agent) ?></span></div><?php endif; ?></div><div><?= $h($quote['terms'] ?? 'A commitment deposit is required to reserve the apartment. Balance is due immediately upon arrival.') ?></div></div>
  </main>
</div>
<div class="actions"><button class="btn" onclick="window.print()">Print / Save PDF</button><?php if (!empty($quote['public_url'])): ?><a class="btn" target="_blank" rel="noopener" href="<?= esc('https://wa.me/?text='.rawurlencode('Haven Suites quotation: '.$quote['public_url']),'attr') ?>">Share via WhatsApp</a><button class="btn secondary" onclick="navigator.clipboard.writeText(<?= json_encode((string)$quote['public_url'], JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT) ?>)">Copy quotation link</button><?php endif; ?></div>
</body></html>

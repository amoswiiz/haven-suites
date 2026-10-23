@extends('layouts/main')
@section('content')

<div class="container-fluid py-3">
<div class="card border-0 shadow-sm"><div class="card-header hs-head py-3"><h1 class="h4 mb-1">Haven Suites Quotations</h1><div class="small">Choose a reservation to preview or issue its quotation</div></div>
<div class="card-body"><div class="alert alert-info small">Guest payment submissions are reviewed separately. A quotation is not proof of payment.</div>
<div class="table-responsive"><table class="table table-hover align-middle"><thead><tr><th>Reservation</th><th>Guest</th><th>Apartment</th><th>Stay</th><th class="text-end">Amount</th><th>Quotations issued</th><th>Action</th></tr></thead><tbody>
<?php foreach ($reservations as $r): $count=(int)($issued[(int)$r['id']]['issued_count']??0); ?>
<tr><td>#<?= (int)$r['id'] ?></td><td><?= esc($r['guest_name']??'') ?></td><td><?= esc(trim(($r['property_name']??'Apartment').' / '.($r['unit_number']??''))) ?></td>
<td><?= esc($r['check_in']??'') ?> – <?= esc($r['check_out']??'') ?></td><td class="text-end">KES <?= number_format((float)$r['total_amount'],2) ?></td>
<td><?= $count ?></td><td><?php if (haven_can_access(site_url('reservations/'.(int)$r['id'].'/quotation'), 'GET')): ?><a class="btn btn-hs btn-sm" href="<?= site_url('reservations/'.(int)$r['id'].'/quotation') ?>">Preview</a><?php endif; ?></td></tr>
<?php endforeach; ?>
<?php if (!$reservations): ?><tr><td colspan="7" class="text-center text-muted py-4">No active reservations found.</td></tr><?php endif; ?>
</tbody></table></div><div class="small text-muted">Issued quotations are counted here. Existing public links cannot be reconstructed from stored token hashes; issue a new link if the original was lost.</div>
<div class="mt-3"><?php if (haven_can_access(site_url('reservations'), 'GET')): ?><a href="<?= site_url('reservations') ?>" class="btn btn-outline-secondary">Back to Reservations</a><?php endif; ?>
<?php if(haven_auth()->user()?->can('finance.manage')): ?><?php if (haven_can_access(site_url('quotations/payment-claims'), 'GET')): ?><a href="<?= site_url('quotations/payment-claims') ?>" class="btn btn-outline-primary">Payment confirmations</a><?php endif; ?><?php endif; ?></div>
</div></div></div>

@endsection

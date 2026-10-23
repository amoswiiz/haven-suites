@extends('layouts/main')
@section('content')
<div class="d-flex justify-content-between align-items-center flex-wrap gap-3 mb-4">
  <div><h3 class="fw-bold mb-1">Customers</h3><p class="text-muted mb-0">Haven Suites customer directory and KYC records.</p></div>
  <a class="btn btn-primary" href="<?= site_url('customers/create') ?>">+ Add Customer</a>
</div>
<div class="row g-3 mb-4">
  <div class="col-md-4"><div class="hs-stat-card"><div class="hs-stat-label">Registered Customers</div><div class="fs-4 fw-bold"><?= count($customers) ?></div></div></div>
</div>
<div class="hs-panel mb-3"><label for="customer-search" class="form-label">Search customers</label><input type="search" id="customer-search" class="form-control" placeholder="Search name, phone or email"><div id="customer-count" class="small text-muted mt-2" aria-live="polite"></div></div>
<div class="hs-panel"><div class="table-responsive"><table class="table table-hover align-middle"><thead><tr><th>Customer</th><th>Phone</th><th>Email</th><th>Alternative Contact</th><th>Added</th><th></th></tr></thead><tbody>
<?php foreach ($customers as $customer): ?>
<tr class="customer-row"><td class="fw-semibold"><?= esc($customer['full_name']) ?></td><td><?= esc($customer['primary_phone']) ?></td><td><?= esc($customer['email'] ?: '—') ?></td><td><?= esc($customer['alternative_contact'] ?: '—') ?></td><td><?= esc($customer['created_at'] ?: '—') ?></td><td><a class="btn btn-sm btn-outline-primary" href="<?= site_url('customers/' . (int) $customer['id']) ?>">View</a></td></tr>
<?php endforeach; ?>
<?php if (!$customers): ?><tr><td colspan="6" class="text-center text-muted py-4">No customers registered yet.</td></tr><?php endif; ?>
<tr id="customer-empty" style="display:none"><td colspan="6" class="text-center text-muted py-4">No customers match your search.</td></tr>
</tbody></table></div></div>
<script>
(function(){const input=document.getElementById('customer-search');const rows=Array.from(document.querySelectorAll('.customer-row'));const count=document.getElementById('customer-count');const empty=document.getElementById('customer-empty');function update(){const q=input.value.trim().toLowerCase();let shown=0;rows.forEach(row=>{const match=row.textContent.toLowerCase().includes(q);row.style.display=match?'':'none';if(match)shown++;});empty.style.display=rows.length&&shown===0?'':'none';count.textContent='Showing '+shown+' of '+rows.length+' customers';}input.addEventListener('input',update);update();})();
</script>
@endsection

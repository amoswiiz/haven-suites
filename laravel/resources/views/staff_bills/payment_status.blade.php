@if($bill->status === 'rejected')
    Not payable
@elseif($bill->status === 'returned')
    Correction needed
@elseif($bill->status !== 'approved')
    Awaiting approval
@elseif($bill->payer === 'company')
    Company payment reconciled
@elseif((int) round($paid * 100) >= (int) $bill->amount_minor)
    {{ $bill->payer === 'personal' ? 'Reimbursed' : 'Supplier paid' }}
@elseif($paid > 0)
    {{ $bill->payer === 'personal' ? 'Partially reimbursed' : 'Partially paid' }}
@else
    {{ $bill->payer === 'personal' ? 'Awaiting reimbursement' : 'Supplier unpaid' }}
@endif

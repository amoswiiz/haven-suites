<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class StandaloneQuotationController extends Controller
{
    public function create(): View
    {
        $settings = DB::connection('tenant')->table('invoice_settings')->where('id', 1)->first();

        return view('quotations.create', ['settings' => $settings]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'customer_name' => 'required|string|max:180', 'customer_email' => 'nullable|email|max:180',
            'customer_phone' => 'nullable|string|max:60', 'customer_address' => 'nullable|string|max:1000',
            'issue_date' => 'required|date_format:Y-m-d', 'valid_until' => 'required|date_format:Y-m-d|after_or_equal:issue_date',
            'notes' => 'nullable|string|max:5000', 'terms' => 'nullable|string|max:5000',
            'items' => 'required|array|min:1|max:30', 'items.*.description' => 'required|string|max:1000',
            'items.*.quantity' => ['required', 'numeric', 'min:0.01', 'max:100000', 'regex:/^\d+(?:\.\d{1,2})?$/'],
            'items.*.price' => ['required', 'numeric', 'min:0', 'max:100000000', 'regex:/^\d+(?:\.\d{1,2})?$/'],
        ]);
        $total = 0;
        $items = [];
        foreach ($data['items'] as $item) {
            $line = (int) round((int) round((float) $item['quantity'] * 100) * (int) round((float) $item['price'] * 100) / 100);
            $total += $line;
            $items[] = ['description' => trim($item['description']), 'quantity' => $item['quantity'], 'price' => $item['price'], 'total_minor' => $line];
        }
        if ($total < 1 || $total > 999999999999) {
            throw ValidationException::withMessages(['items' => 'Quotation total must be between 0.01 and 9,999,999,999.99.']);
        }
        $tenant = app('currentTenant');
        $settings = (array) DB::connection('tenant')->table('invoice_settings')->where('id', 1)->first();
        $snapshot = [...$data, 'items' => $items, 'business_name' => $tenant->name, 'business' => $settings];
        $id = DB::transaction(function () use ($tenant, $data, $total, $snapshot): int {
            $id = DB::table('independent_quotations')->insertGetId([
                'tenant_id' => $tenant->id, 'customer_name' => $data['customer_name'],
                'issue_date' => $data['issue_date'], 'valid_until' => $data['valid_until'],
                'currency' => $tenant->currency ?: 'KES', 'total_minor' => $total,
                'snapshot' => json_encode($snapshot, JSON_THROW_ON_ERROR), 'created_by' => auth()->id(),
                'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('independent_quotations')->where('id', $id)->update(['number' => 'QT-'.substr($data['issue_date'], 0, 4).'-S'.str_pad((string) $id, 6, '0', STR_PAD_LEFT)]);

            return $id;
        });

        return redirect()->to(site_url('quotations/independent/'.$id))->with('success', 'Quotation saved. No booking, invoice or payment was created.');
    }

    public function show(string $tenant, int $id): View
    {
        $quotation = DB::table('independent_quotations')->where('tenant_id', app('currentTenant')->id)->where('id', $id)->first();
        abort_unless($quotation, 404);

        return view('quotations.independent', ['quotation' => $quotation, 'snapshot' => json_decode($quotation->snapshot, true, 512, JSON_THROW_ON_ERROR)]);
    }
}

<?php
namespace App\Http\Controllers\Haven;
class CleanerPaymentDetails extends BaseController
{
    public function show(int $id) {
        $db = db_connect();
        $payment = $db->table('cleaner_payments cp')->select('cp.*,s.full_name AS cleaner_name')->join('staff s','s.id=cp.staff_id','left')->where('cp.id',$id)->get()->getRowArray();
        abort_unless($payment,404);
        $allocations = $db->table('cleaner_payment_allocations a')->select('a.*,h.completed_at,h.bedrooms_cleaned,h.cleaning_type,u.unit_number,p.name AS property_name')->join('housekeeping h','h.id=a.housekeeping_id')->join('units u','u.id=h.unit_id')->join('properties p','p.id=u.property_id')->where('a.payment_id',$id)->get()->getResultArray();
        return haven_view('cleaner_payments/show',compact('payment','allocations'));
    }
}

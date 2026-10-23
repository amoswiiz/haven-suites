<?php
namespace App\Http\Controllers\Haven;
use App\Support\Migration\PageNotFoundException;
class Quotations extends BaseController {
 public function index() {
  if (!haven_auth()->user()?->can('reservations.view')) return redirect()->to(site_url('dashboard'));
  $db = db_connect();
  $reservations = $db->table('reservations AS r')
   ->select('r.id, r.guest_name, r.check_in, r.check_out, r.total_amount, r.status, u.unit_number, p.name AS property_name')
   ->join('units AS u', 'u.id = r.unit_id', 'left')
   ->join('properties AS p', 'p.id = u.property_id', 'left')
   ->where('r.status !=', 'cancelled')->orderBy('r.id', 'DESC')->limit(100)->get()->getResultArray();
  $issued = $db->table('haven_quotations')->select('reservation_id, COUNT(*) AS issued_count, MAX(created_at) AS last_issued')
   ->groupBy('reservation_id')->get()->getResultArray();
  $byReservation = [];
  foreach ($issued as $row) $byReservation[(int) $row['reservation_id']] = $row;
  return haven_view('quotations/index', ['reservations' => $reservations, 'issued' => $byReservation,
    'canIssue' => haven_auth()->user()?->can('reservations.manage') ?? false]);
 }
 private function reservationQuote(int $id): array {
  $db=db_connect();
  $r=$db->table('reservations r')->select('r.*,u.unit_number,u.bedrooms,p.name property_name,p.location property_location')->join('units u','u.id=r.unit_id','left')->join('properties p','p.id=u.property_id','left')->where('r.id',$id)->get()->getRowArray();
  if (!$r || $r['status']==='cancelled') throw PageNotFoundException::forPageNotFound('Reservation unavailable');
  $in=new \DateTimeImmutable($r['check_in']);$out=new \DateTimeImmutable($r['check_out']);$n=(int)$in->diff($out)->format('%r%a');$total=round((float)$r['total_amount'],2);
  if($n<1 || $total<=0) throw new \RuntimeException('Invalid reservation amount or dates');
  $user=haven_auth()->user();$staff=$db->table('staff')->select('full_name')->where('user_id',$user->id)->get()->getRowArray();
  return ['number'=>'QT-'.date('Y').'-R'.$id.'-'.date('His'),'issued_date'=>date('d M Y'),'valid_until'=>date('d M Y',strtotime('+4 days')),'property_name'=>trim(($r['bedrooms']??'').' Bedroom — '.($r['property_name']??'Apartment').' / '.($r['unit_number']??'')),'location'=>$r['property_location']??'','checkin'=>$in->format('d M Y'),'checkout'=>$out->format('d M Y'),'checkin_time'=>'From 12:00 PM','checkout_time'=>'By 10:00 AM','nights'=>$n,'nightly_rate'=>$total/$n,'total'=>$total,'deposit_percent'=>50,'deposit_amount'=>round($total*.5,2),'confirmed_paid'=>0,'payment_method'=>['type'=>'M-Pesa','details'=>['Paybill Number'=>'247247','Account Number'=>'898 694','Account Name'=>'HAVEN SUITES']],'payment_instructions'=>'Pay using the details shown and submit your M-Pesa confirmation below for verification.','agent_name'=>$staff['full_name']??($user->username??'Staff'),'contact_phone'=>'0702 808 694','terms'=>'A 50% commitment deposit is required. Balance due immediately upon arrival.','logo_url'=>base_url('assets/images/logo.png'),'kyc_url'=>'https://tinyurl.com/yc4tffy5'];
 }
 public function preview(int $id) {if(!haven_auth()->user()?->can('reservations.view'))return redirect()->to(site_url('dashboard'));return haven_view('quotations/quotation',['quote'=>$this->reservationQuote($id),'is_public'=>false]);}
 public function issue(int $id) {
  if(!haven_auth()->user()?->can('reservations.manage'))return redirect()->to(site_url('dashboard'))->with('error','Not authorized');
  $q=$this->reservationQuote($id);$token=bin2hex(random_bytes(24));$db=db_connect();
  $ok=$db->table('haven_quotations')->insert(['reservation_id'=>$id,'token_hash'=>hash('sha256',$token),'snapshot_json'=>json_encode($q,JSON_THROW_ON_ERROR),'created_by'=>haven_auth()->id(),'status'=>'issued','expires_at'=>date('Y-m-d H:i:s',strtotime('+4 days')),'created_at'=>date('Y-m-d H:i:s')]);
  if(!$ok)return redirect()->back()->with('error','Quotation not saved');
  return redirect()->to(site_url('q/'.$token));
 }
 private function getPublic(string $token): ?array {
  if(!preg_match('/^[a-f0-9]{48}$/D',$token))return null;
  return db_connect()->table('haven_quotations')->where('token_hash',hash('sha256',$token))->where('status','issued')->where('expires_at >=',date('Y-m-d H:i:s'))->get()->getRowArray();
 }
 public function publicView(string $token) {
  $row=$this->getPublic($token);if(!$row)throw PageNotFoundException::forPageNotFound('Quotation expired or unavailable');
  $q=json_decode($row['snapshot_json'],true,512,JSON_THROW_ON_ERROR);$q['public_url']=site_url('q/'.$token);
  return haven_view('quotations/quotation',['quote'=>$q,'is_public'=>true,'token'=>$token,'notice'=>haven_session()->getFlashdata('notice'),'error'=>haven_session()->getFlashdata('error')]);
 }
 public function submitPayment(string $token) {
  $row=$this->getPublic($token);if(!$row)throw PageNotFoundException::forPageNotFound('Quotation expired or unavailable');
  $back=site_url('q/'.$token);$amount=trim((string)$this->request->getPost('amount'));$message=trim((string)$this->request->getPost('mpesa_message'));
  if(!is_numeric($amount)||(float)$amount<=0||(float)$amount>10000000||strlen($message)<20||strlen($message)>2000)return redirect()->to($back)->with('error','Enter a valid amount and full M-Pesa message.');
  // Require transaction code at start of SMS; never treat this as verified payment.
  if(!preg_match('/^([A-Z0-9]{10,15})\b/i',$message,$match))return redirect()->to($back)->with('error','Paste the full M-Pesa SMS beginning with its transaction code.');
  $code=strtoupper($match[1]);$db=db_connect();
  try {$db->table('haven_quote_payment_claims')->insert(['quotation_id'=>$row['id'],'amount'=>round((float)$amount,2),'mpesa_code'=>$code,'mpesa_message'=>$message,'status'=>'pending','submitted_at'=>date('Y-m-d H:i:s')]);}
  catch(\Throwable $e){return redirect()->to($back)->with('error','This transaction may already have been submitted. Contact Haven Suites if you need assistance.');}
  return redirect()->to($back)->with('notice','Payment confirmation received for review. This does not mean payment is verified.');
 }
 public function claims() {
  if(!haven_auth()->user()?->can('finance.manage'))return redirect()->to(site_url('dashboard'));
  $rows=db_connect()->table('haven_quote_payment_claims c')->select('c.*,q.reservation_id')->join('haven_quotations q','q.id=c.quotation_id')->orderBy('c.id','DESC')->limit(200)->get()->getResultArray();
  return haven_view('quotations/claims',['claims'=>$rows]);
 }
 public function review(int $id) {
  if(!haven_auth()->user()?->can('finance.manage'))return redirect()->to(site_url('dashboard'));
  $decision=(string)$this->request->getPost('decision');if(!in_array($decision,['reviewed','rejected'],true))return redirect()->back()->with('error','Invalid decision');
  db_connect()->table('haven_quote_payment_claims')->where('id',$id)->where('status','pending')->update(['status'=>$decision,'reviewed_by'=>haven_auth()->id(),'reviewed_at'=>date('Y-m-d H:i:s')]);
  return redirect()->to(site_url('quotations/payment-claims'))->with('success','Claim marked '.$decision.'. No payment has been recorded in accounting.');
 }
}

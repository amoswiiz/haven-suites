<?php
namespace App\Http\Controllers\Haven;

use App\Services\OwnerRemittanceCalculator;

class OwnerStatementClosings extends BaseController
{
    private function permitted(): bool
    {
        $u = haven_auth()->user();
        return $u !== null && $u->can('finance.manage');
    }
    private function month($value): ?string
    {
        $m = trim((string)$value);
        return preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $m) ? $m : null;
    }
    private function back(int $unitId, ?string $month): string
    {
        return site_url('owner-remittances') . '?unit_id=' . $unitId . '&month=' . rawurlencode($month ?? date('Y-m'));
    }
    public function opening()
    {
        if (!$this->permitted()) return haven_service('response')->setStatusCode(403)->setContent('Permission denied.');
        $unitId = (int)$this->request->getPost('unit_id');
        $month = $this->month($this->request->getPost('effective_month'));
        $amount = trim((string)$this->request->getPost('amount'));
        $notes = trim((string)$this->request->getPost('notes'));
        $back = $this->back($unitId, $month);
        if ($unitId <= 0 || !$month || !preg_match('/^-?\d{1,10}(?:\.\d{1,2})?$/', $amount) || strlen($notes)>10000) {
            return redirect()->to($back)->with('error','Invalid opening balance details.');
        }
        $db = db_connect();
        $db->transBegin();
        try {
            if (!$db->query('SELECT id FROM units WHERE id = ? FOR UPDATE', [$unitId])->getRowArray()) throw new \RuntimeException('Unit not found.');
            if ($db->table('unit_opening_balances')->where('unit_id',$unitId)->countAllResults()) throw new \RuntimeException('Opening balance already exists. Reconciliation adjustment required; do not overwrite.');
            if ($db->table('owner_statement_closings')->where('unit_id',$unitId)->countAllResults()) throw new \RuntimeException('Cannot set opening balance after a statement has been approved.');
            if (!$db->table('unit_opening_balances')->insert([
                'unit_id'=>$unitId,'effective_month'=>$month.'-01','amount'=>$amount,
                'notes'=>$notes ?: null,'recorded_by'=>(int)haven_auth()->user()->id,
                'created_at'=>date('Y-m-d H:i:s'),
            ]) || $db->transStatus() === false) throw new \RuntimeException('Could not save opening balance.');
            $db->transCommit();
            return redirect()->to($back)->with('success','Opening balance saved.');
        } catch (\Throwable $e) {
            $db->transRollback();
            return redirect()->to($back)->with('error',$e->getMessage());
        }
    }
    public function approve()
    {
        if (!$this->permitted()) return haven_service('response')->setStatusCode(403)->setContent('Permission denied.');
        $unitId = (int)$this->request->getPost('unit_id');
        $month = $this->month($this->request->getPost('month'));
        $back = $this->back($unitId,$month);
        if ($unitId <= 0 || !$month) return redirect()->to($back)->with('error','Invalid unit or month.');
        $db = db_connect();
        $db->transBegin();
        try {
            if (!$db->query('SELECT id FROM units WHERE id = ? FOR UPDATE', [$unitId])->getRowArray()) throw new \RuntimeException('Unit not found.');
            if ($db->table('owner_statement_closings')->where('unit_id',$unitId)->where('period_month',$month.'-01')->countAllResults()) throw new \RuntimeException('Statement already approved.');
            // Approve months in order so the opening balance is based on an approved prior month.
            $last = $db->table('owner_statement_closings')->selectMax('period_month','last_month')->where('unit_id',$unitId)->get()->getRowArray();
            if (!empty($last['last_month'])) {
                $expected = date('Y-m',strtotime($last['last_month'].' +1 month'));
                if ($month !== $expected) throw new \RuntimeException('Next approvable month is '.$expected.'.');
            }
            if ($month >= date('Y-m')) throw new \RuntimeException('Approve completed past months only.');
            $report = (new OwnerRemittanceCalculator())->calculate($unitId,$month);
            if ($report['issues']) throw new \RuntimeException('Resolve report issues first: '.implode('; ',$report['issues']));
            $json = json_encode($report, JSON_THROW_ON_ERROR);
            if (!$db->table('owner_statement_closings')->insert([
                'unit_id'=>$unitId,'period_month'=>$month.'-01',
                'statement_json'=>$json,'statement_hash'=>hash('sha256',$json),
                'approved_by'=>(int)haven_auth()->user()->id,'approved_at'=>date('Y-m-d H:i:s'),
            ]) || $db->transStatus() === false) throw new \RuntimeException('Could not approve statement.');

            // Lock the approved accounting month in the same transaction.
            $now = date('Y-m-d H:i:s');
            $actor = (int)haven_auth()->user()->id;
            $existingLock = $db->table('accounting_period_locks')
                ->where('unit_id', $unitId)->where('period_month', $month.'-01')
                ->get()->getRowArray();
            if ($existingLock && (int)$existingLock['is_locked'] === 1) {
                throw new \RuntimeException('This accounting period is already locked.');
            }
            $lockData = [
                'is_locked'=>1, 'locked_by'=>$actor, 'locked_at'=>$now,
                'reopened_by'=>null, 'reopened_at'=>null, 'reason'=>null, 'updated_at'=>$now,
            ];
            $ok = $existingLock
                ? $db->table('accounting_period_locks')->where('id',(int)$existingLock['id'])->update($lockData)
                : $db->table('accounting_period_locks')->insert($lockData + [
                    'unit_id'=>$unitId, 'period_month'=>$month.'-01', 'created_at'=>$now,
                ]);
            if (!$ok || !$db->table('accounting_period_lock_events')->insert([
                'unit_id'=>$unitId,'period_month'=>$month.'-01',
                'action'=>'locked','actor_id'=>$actor,
                'reason'=>'Monthly statement approved','created_at'=>$now,
            ]) || $db->transStatus() === false) {
                throw new \RuntimeException('Could not lock approved accounting month.');
            }
            $db->transCommit();
            return redirect()->to($back)->with('success','Statement approved and accounting period locked.');
        } catch (\Throwable $e) {
            $db->transRollback();
            return redirect()->to($back)->with('error',$e->getMessage());
        }
    }
    public function index()
    {
        if (!$this->permitted()) return haven_service('response')->setStatusCode(403)->setContent('Permission denied.');
        $db = db_connect();
        $units = $db->table('units u')->select('u.id,u.unit_number,p.name AS property_name')
            ->join('properties p','p.id=u.property_id','left')->orderBy('p.name')->orderBy('u.unit_number')->get()->getResultArray();
        $unitId = (int)$this->request->getGet('unit_id');
        $month = $this->month($this->request->getGet('month')) ?? date('Y-m',strtotime('first day of last month'));
        $opening = $unitId ? $db->table('unit_opening_balances')->where('unit_id',$unitId)->get()->getRowArray() : null;
        $snapshot = $unitId ? $db->table('owner_statement_closings')->where('unit_id',$unitId)->where('period_month',$month.'-01')->get()->getRowArray() : null;
        $current = null;
        $error = null;
        if ($unitId) {
            try { $current = (new OwnerRemittanceCalculator())->calculate($unitId,$month); }
            catch (\Throwable $e) { $error = $e->getMessage(); }
        }
        $user = haven_auth()->user();
        $canReopen = $user !== null && $user->can('finance.manage')
            && ($user->inGroup('superadmin') || $user->inGroup('admin'));
        $periodLocked = false;
        $isLatestApproved = false;
        $revisions = [];
        if ($unitId) {
            $period = $month . '-01';
            $lock = $db->table('accounting_period_locks')->where('unit_id',$unitId)
                ->where('period_month',$period)->get()->getRowArray();
            $periodLocked = $lock && (int)$lock['is_locked'] === 1;
            $later = $db->table('owner_statement_closings')->where('unit_id',$unitId)
                ->where('period_month >',$period)->countAllResults();
            $isLatestApproved = $later === 0;
            $revisions = $db->table('owner_statement_revision_history')
                ->where('unit_id',$unitId)->where('period_month',$period)
                ->orderBy('id','DESC')->get()->getResultArray();
        }
        return haven_view('owner_remittances/closing', compact('units','unitId','month','opening','snapshot','current','error',
            'canReopen','periodLocked','isLatestApproved','revisions') + ['pageTitle'=>'Owner Statement Closing']);
    }
}

<?php
namespace App\Controllers;

class OwnerAccountingReopening extends BaseController
{
    private function canReopen(): bool
    {
        $user = auth()->user();
        return $user !== null && $user->can('finance.manage')
            && ($user->inGroup('superadmin') || $user->inGroup('admin'));
    }

    public function reopen()
    {
        if (!$this->canReopen()) {
            return service('response')->setStatusCode(403)->setBody('Only finance administrators can reopen accounting periods.');
        }
        $unitId = filter_var($this->request->getPost('unit_id'), FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]);
        $month = trim((string)$this->request->getPost('month'));
        $reason = trim((string)$this->request->getPost('reason'));
        $back = site_url('owner-statement-closing') . '?unit_id=' . (int)$unitId . '&month=' . rawurlencode($month);
        if (!$unitId || !preg_match('/^\d{4}-(0[1-9]|1[0-2])$/D', $month)
            || mb_strlen($reason) < 15 || mb_strlen($reason) > 5000) {
            return redirect()->to($back)->with('error', 'Choose a valid month and provide a reason of 15–5000 characters.');
        }
        $db = db_connect();
        $period = $month . '-01';
        $db->transBegin();
        try {
            // All guarded writers must lock the unit row before checking the period.
            if (!$db->query('SELECT id FROM units WHERE id = ? FOR UPDATE', [$unitId])->getRowArray()) {
                throw new \RuntimeException('Unit not found.');
            }
            $lock = $db->table('accounting_period_locks')->where('unit_id',$unitId)
                ->where('period_month',$period)->get()->getRowArray();
            if (!$lock || (int)$lock['is_locked'] !== 1) {
                throw new \RuntimeException('This month is not locked.');
            }
            $snapshot = $db->table('owner_statement_closings')->where('unit_id',$unitId)
                ->where('period_month',$period)->get()->getRowArray();
            if (!$snapshot) {
                throw new \RuntimeException('No approved snapshot exists for this period.');
            }
            // Reopen only the most recent approved month; older revisions can invalidate carry-forward balances.
            $later = $db->table('owner_statement_closings')->where('unit_id',$unitId)
                ->where('period_month >',$period)->countAllResults();
            if ($later > 0) {
                throw new \RuntimeException('Reopen later approved months first, working backwards.');
            }
            if (!hash_equals((string)$snapshot['statement_hash'], hash('sha256', (string)$snapshot['statement_json']))) {
                throw new \RuntimeException('Approved snapshot integrity check failed.');
            }
            $now = date('Y-m-d H:i:s');
            $actor = (int)auth()->user()->id;
            $history = [
                'unit_id'=>$unitId,'period_month'=>$period,
                'statement_json'=>$snapshot['statement_json'],'statement_hash'=>$snapshot['statement_hash'],
                'approved_by'=>$snapshot['approved_by'],'approved_at'=>$snapshot['approved_at'],
                'reopened_by'=>$actor,'reopened_at'=>$now,'reopen_reason'=>$reason,
            ];
            if (!$db->table('owner_statement_revision_history')->insert($history)) {
                throw new \RuntimeException('Could not preserve the original approved statement.');
            }
            if (!$db->table('owner_statement_closings')->where('id',$snapshot['id'])->delete()) {
                throw new \RuntimeException('Could not release the current approved snapshot.');
            }
            if (!$db->table('accounting_period_locks')->where('id',$lock['id'])->update([
                'is_locked'=>0,'reopened_by'=>$actor,'reopened_at'=>$now,
                'reason'=>$reason,'updated_at'=>$now,
            ])) {
                throw new \RuntimeException('Could not reopen accounting period.');
            }
            if (!$db->table('accounting_period_lock_events')->insert([
                'unit_id'=>$unitId,'period_month'=>$period,'action'=>'reopened',
                'actor_id'=>$actor,'reason'=>$reason,'created_at'=>$now,
            ]) || $db->transStatus() === false) {
                throw new \RuntimeException('Could not record reopening audit event.');
            }
            $db->transCommit();
            return redirect()->to($back)->with('success', 'Month reopened. The previous approved statement is archived. Make corrections, then approve the month again.');
        } catch (\Throwable $e) {
            $db->transRollback();
            log_message('error', 'Accounting reopening failed: {message}', ['message'=>$e->getMessage()]);
            return redirect()->to($back)->with('error', $e->getMessage());
        }
    }
}

<?php

namespace App\Controllers;

use CodeIgniter\HTTP\ResponseInterface;

class HostAgreements extends BaseController
{
    private function allowed(string $permission): bool
    {
        $user = auth()->user();
        return $user !== null && $user->can($permission);
    }

    private function forbidden(): ResponseInterface
    {
        return service('response')->setStatusCode(403)->setBody('Permission denied.');
    }

    private function units(): array
    {
        return db_connect()->table('units u')
            ->select('u.id, u.unit_number, p.name AS property_name')
            ->join('properties p', 'p.id = u.property_id', 'left')
            ->orderBy('p.name', 'ASC')->orderBy('u.unit_number', 'ASC')
            ->get()->getResultArray();
    }

    public function index()
    {
        if (!$this->allowed('finance.view')) return $this->forbidden();

        $db = db_connect();
        $unitId = (int) $this->request->getGet('unit_id');
        $query = $db->table('unit_host_agreements a')
            ->select('a.*, u.unit_number, p.name AS property_name')
            ->join('units u', 'u.id = a.unit_id', 'inner')
            ->join('properties p', 'p.id = u.property_id', 'left');
        if ($unitId > 0) $query->where('a.unit_id', $unitId);

        return view('host_agreements/index', [
            'pageTitle' => 'Host Agreements',
            'agreements' => $query->orderBy('a.effective_from', 'DESC')->orderBy('a.id', 'DESC')->get()->getResultArray(),
            'units' => $this->units(),
            'unitId' => $unitId,
            'canManage' => $this->allowed('finance.manage'),
        ]);
    }

    public function create()
    {
        if (!$this->allowed('finance.manage')) return $this->forbidden();
        return view('host_agreements/form', [
            'pageTitle' => 'New Host Agreement',
            'agreement' => null,
            'units' => $this->units(),
            'action' => site_url('host-agreements'),
        ]);
    }

    public function edit(int $id)
    {
        if (!$this->allowed('finance.manage')) return $this->forbidden();
        $agreement = db_connect()->table('unit_host_agreements')->where('id', $id)->get()->getRowArray();
        if (!$agreement) return service('response')->setStatusCode(404)->setBody('Agreement not found.');
        return view('host_agreements/form', [
            'pageTitle' => 'Edit Host Agreement',
            'agreement' => $agreement,
            'units' => $this->units(),
            'action' => site_url('host-agreements/' . $id),
        ]);
    }

    public function store()
    {
        return $this->save(null);
    }

    public function update(int $id)
    {
        return $this->save($id);
    }

    private function validDate(string $date): bool
    {
        $d = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        return $d !== false && $d->format('Y-m-d') === $date;
    }

    private function save(?int $id)
    {
        if (!$this->allowed('finance.manage')) return $this->forbidden();

        $unitId = (int) $this->request->getPost('unit_id');
        $name = trim((string) $this->request->getPost('host_name'));
        $phone = trim((string) $this->request->getPost('host_phone'));
        $rateInput = trim((string) $this->request->getPost('commission_rate'));
        $from = trim((string) $this->request->getPost('effective_from'));
        $to = trim((string) $this->request->getPost('effective_to'));
        $notes = trim((string) $this->request->getPost('notes'));

        if ($unitId < 1 || $name === '' || mb_strlen($name) > 150 || mb_strlen($phone) > 50
            || mb_strlen($notes) > 10000 || !$this->validDate($from)
            || ($to !== '' && (!$this->validDate($to) || $to < $from))
            || !preg_match('/^\d{1,3}(?:\.\d{1,2})?$/', $rateInput)
            || (float) $rateInput > 100) {
            return redirect()->back()->withInput()->with('error', 'Check the unit, host, commission rate and agreement dates.');
        }

        $db = db_connect();
        $db->transBegin();
        try {
            // Serialize agreement writes for the same unit, including first-time agreements.
            $unit = $db->query('SELECT id FROM units WHERE id = ? FOR UPDATE', [$unitId])->getRowArray();
            if (!$unit) throw new \RuntimeException('Unit not found.');

            if ($id !== null) {
                $existing = $db->query('SELECT id, unit_id FROM unit_host_agreements WHERE id = ? FOR UPDATE', [$id])->getRowArray();
                if (!$existing) throw new \RuntimeException('Agreement not found.');
                // Keep agreement history attached to the original unit.
                if ((int) $existing['unit_id'] !== $unitId) {
                    throw new \RuntimeException('The unit cannot be changed on an existing agreement.');
                }
            }

            $sql = 'SELECT id FROM unit_host_agreements WHERE unit_id = ? '
                . 'AND (effective_to IS NULL OR effective_to >= ?) '
                . 'AND (? IS NULL OR effective_from <= ?)';
            $params = [$unitId, $from, $to === '' ? null : $to, $to === '' ? null : $to];
            if ($id !== null) {
                $sql .= ' AND id <> ?';
                $params[] = $id;
            }
            $sql .= ' LIMIT 1';
            if ($db->query($sql, $params)->getRowArray()) {
                throw new \RuntimeException('This agreement overlaps another agreement for the same unit. Close the previous period first.');
            }

            $now = date('Y-m-d H:i:s');
            $data = [
                'unit_id' => $unitId,
                'host_name' => $name,
                'host_phone' => $phone !== '' ? $phone : null,
                'commission_rate' => number_format((float) $rateInput, 2, '.', ''),
                'effective_from' => $from,
                'effective_to' => $to !== '' ? $to : null,
                'notes' => $notes !== '' ? $notes : null,
                'updated_at' => $now,
            ];
            if ($id === null) {
                $data['created_at'] = $now;
                $ok = $db->table('unit_host_agreements')->insert($data);
            } else {
                $ok = $db->table('unit_host_agreements')->where('id', $id)->update($data);
            }
            if (!$ok || $db->transStatus() === false) throw new \RuntimeException('Unable to save agreement.');
            $db->transCommit();
            return redirect()->to(site_url('host-agreements'))->with('success', 'Host agreement saved.');
        } catch (\Throwable $e) {
            $db->transRollback();
            log_message('error', 'Host agreement save failed: {message}', ['message' => $e->getMessage()]);
            return redirect()->back()->withInput()->with('error', $e->getMessage());
        }
    }
}

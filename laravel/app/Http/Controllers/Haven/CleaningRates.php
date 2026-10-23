<?php

namespace App\Http\Controllers\Haven;

use App\Models\Haven\CleaningRateModel;

class CleaningRates extends BaseController
{
    private function authorized(): bool
    {
        $user = haven_auth()->user();

        return $user !== null
            && $user->can('settings.manage');
    }

    private function forbidden()
    {
        return haven_service('response')
            ->setStatusCode(403)
            ->setContent('Permission denied.');
    }

    public function index()
    {
        if (!$this->authorized()) {
            return $this->forbidden();
        }

        $model = new CleaningRateModel();

        return haven_view('cleaning_rates/index', [
            'pageTitle' => 'Cleaning Rates',
            'rates' => $model
                ->orderBy('bedrooms', 'ASC')
                ->findAll(),
        ]);
    }

    public function update()
    {
        if (!$this->authorized()) {
            return $this->forbidden();
        }

        $model = new CleaningRateModel();
        $submitted = $this->request->getPost('rates');

        if (!is_array($submitted)) {
            return redirect()->back()
                ->with('error', 'No cleaning rates were submitted.');
        }

        $rates = $model->findAll();
        $updates = [];

        foreach ($rates as $rate) {
            $id = (int) $rate['id'];

            if (!array_key_exists($id, $submitted)) {
                return redirect()->back()
                    ->with('error', 'A cleaning rate is missing.');
            }

            $amount = $submitted[$id];

            if (
                !is_scalar($amount) ||
                !preg_match(
                    '/^\d{1,8}(\.\d{1,2})?$/',
                    trim((string) $amount)
                )
            ) {
                return redirect()->back()
                    ->with(
                        'error',
                        'Enter a valid amount with up to two decimal places.'
                    );
            }

            $updates[] = [
                'id' => $id,
                'amount' => number_format(
                    (float) $amount,
                    2,
                    '.',
                    ''
                ),
            ];
        }

        $db = db_connect();

        $db->transBegin();

        try {
            foreach ($updates as $update) {
                if (!$model->update(
                    $update['id'],
                    ['amount' => $update['amount']]
                )) {
                    throw new \RuntimeException(
                        'Unable to update cleaning rate.'
                    );
                }
            }

            if ($db->transStatus() === false) {
                throw new \RuntimeException(
                    'Database transaction failed.'
                );
            }

            $db->transCommit();
        } catch (\Throwable $e) {
            $db->transRollback();

            log_message('error', 'Cleaning rates update: {error}', [
                'error' => $e->getMessage(),
            ]);

            return redirect()->back()
                ->with('error', 'Unable to save cleaning rates.');
        }

        return redirect()->to(site_url('settings/cleaning-rates'))
            ->with('success', 'Cleaning rates updated successfully.');
    }
}

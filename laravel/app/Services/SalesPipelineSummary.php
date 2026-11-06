<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

class SalesPipelineSummary
{
    public function summary(): array
    {
        abort_unless(auth()->user()?->active && auth()->user()->can('sales.view'), 403);
        $labels = config('sales.stages');
        $rows = DB::table('sales_leads')->where('tenant_id', app('currentTenant')->id)
            ->selectRaw('stage, COUNT(*) AS total, SUM(CASE WHEN stage NOT IN (?, ?) AND follow_up_at < ? THEN 1 ELSE 0 END) AS overdue',
                ['won', 'lost', now()->format('Y-m-d H:i:s')])
            ->groupBy('stage')->get()->keyBy('stage');
        $stages = [];
        $open = 0;
        $overdue = 0;
        foreach ($labels as $key => $label) {
            $count = (int) ($rows->get($key)?->total ?? 0);
            $stages[] = ['key' => $key, 'label' => $label, 'count' => $count];
            if (! in_array($key, ['won', 'lost'], true)) {
                $open += $count;
                $overdue += (int) ($rows->get($key)?->overdue ?? 0);
            }
        }

        return ['stages' => $stages, 'total' => array_sum(array_column($stages, 'count')),
            'openCount' => $open, 'overdueCount' => $overdue];
    }
}

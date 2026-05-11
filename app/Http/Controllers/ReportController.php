<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Reception;
use App\Models\Employee;
use App\Exports\ReportsExport;
use Maatwebsite\Excel\Facades\Excel;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;

class ReportController extends Controller
{
    public function index(Request $request)
    {
        $filterType = $request->get('filter_type', 'daily');
        $shift = $request->get('shift', '');
        $plant_filter = $request->get('plant', '');
        $group_filter = $request->get('group', '');
        $operator_name = trim($request->get('operator_name', ''));
        $job_today = $request->get('job_today', '');
        $date = $request->get('date', Carbon::today()->format('Y-m-d'));

        // Default range: 7 days ago until today if not specified
        $default_start = Carbon::parse($date)->subDays(7)->format('Y-m-d');
        $start_date = $request->get('start_date', $default_start);
        $end_date = $request->get('end_date', $date);

        $month = $request->get('month', Carbon::now()->format('Y-m'));
        $start_month = $request->get('start_month', $month);
        $end_month = $request->get('end_month', $month);

        $year = $request->get('year', Carbon::now()->format('Y'));

        $receptions = $this->getFilteredReceptions($filterType, $shift, $plant_filter, $group_filter, $job_today, $start_date, $end_date, $start_month, $end_month, $year, $operator_name);

        // Calculate Rankings based on the JOIN results to ensure perfect sync
        $operatorRanking = $receptions->groupBy('emp_plant')
            ->map(function ($plantGroup) {
                return $plantGroup->groupBy('employee_id')
                    ->map(function ($employeeGroup) {
                        return [
                            'name' => $employeeGroup->first()->emp_name ?? 'Unknown',
                            'production' => $employeeGroup->sum('production_count'),
                            'ritase' => $employeeGroup->sum('ritase_result'),
                        ];
                    })
                    ->sortByDesc(function ($item) {
                        return $item['production'] + $item['ritase'];
                    })
                    ->values()
                    ->toArray();
            })
            ->toArray(); // 🔥 PENTING

        $plantRanking = $receptions->groupBy('emp_plant')
            ->map(function ($group, $key) {
                return [
                    'name' => $key ?: 'Unknown',
                    'count' => $group->sum('production_count'),
                ];
            })
            ->sortByDesc('count');

        $groupRanking = $receptions->groupBy('emp_plant')
            ->map(function ($plantGroup) {
                return $plantGroup->groupBy('emp_group')
                    ->map(function ($group, $key) {
                        return [
                            'name' => $key ?: 'Unknown',
                            'production' => $group->sum('production_count'),
                            'ritase' => $group->sum('ritase_result'),
                        ];
                    })
                    ->values() // 🔥 PENTING: ubah ke array indexed
                    ->toArray(); // 🔥 PENTING
            })
            ->toArray(); // 🔥 PENTING

        // Get unique employee names for autocomplete (Production only)
        $all_employee_names = Cache::remember('all_employee_names', 300, function () {
            return Employee::whereIn('plant', ['B', 'H', 'I', 'T'])
                ->orderBy('name')
                ->distinct()
                ->pluck('name');
        });

        $all_jobs = Reception::select('job_today')
            ->whereNotNull('job_today')
            ->distinct()
            ->orderBy('job_today')
            ->pluck('job_today');

        return view('reports.index', compact(
            'receptions',
            'operatorRanking',
            'plantRanking',
            'groupRanking',
            'all_employee_names',
            'filterType',
            'shift',
            'plant_filter',
            'group_filter',
            'all_jobs',
            'operator_name',
            'job_today',
            'date',
            'start_date',
            'end_date',
            'month',
            'start_month',
            'end_month',
            'year'
        ));
    }

    private function getFilteredReceptions($filterType, $shift, $plant, $group, $job_today, $start_date, $end_date, $start_month, $end_month, $year, $operator_name = '')
    {
        // COMPLIANCE: Use explicit Joins and Table-qualified columns
        $query = Reception::query()
            ->leftJoin('employees', 'receptions.employee_id', '=', 'employees.employee_id')
            ->select(
                'receptions.id',
                'receptions.employee_id',
                'receptions.shift',
                'receptions.ritase_result',
                'receptions.production_count',
                'receptions.date',
                'receptions.job_today',
                'receptions.notes',
                'receptions.photo',
                'employees.name as emp_name',
                'employees.plant as emp_plant',
                'employees.group as emp_group'
            );

        // 1. Date Filtering (applied to receptions.date)
        if ($filterType === 'daily') {
            $query->whereBetween('receptions.date', [$start_date, $end_date]);
        } elseif ($filterType === 'monthly') {
            $start = Carbon::parse($start_month . '-01')->startOfMonth();
            $end = Carbon::parse($end_month . '-01')->endOfMonth();
            $query->whereBetween('receptions.date', [$start, $end]);
        } elseif ($filterType === 'yearly') {
            $query->whereYear('receptions.date', $year);
        }

        // 2. Independent Column Filters (verified against correct tables)
        if ($shift) {
            $query->where('receptions.shift', $shift);
        }

        if ($plant) {
            $query->where('employees.plant', $plant); // Plant from employees table
        }

        if ($group) {
            $query->where('employees.group', $group); // Group from employees table
        }

        if ($operator_name) {
            $query->where(function ($q) use ($operator_name) {
                $term = '%' . strtolower($operator_name) . '%';
                $q->whereRaw('LOWER(employees.name) LIKE ?', [$term]) // Name from employees table
                    ->orWhereRaw('LOWER(receptions.employee_id) LIKE ?', [$term]); // ID from receptions table
            });
        }
        if ($job_today) {
            $query->where('receptions.job_today', 'like', '%' . $job_today . '%');
        }
        return $query->orderBy('receptions.date', 'desc')
            ->orderBy('receptions.created_at', 'desc')
            ->limit(1000) // Safety limit to prevent memory exhaustion
            ->get();
    }

    public function exportExcel(Request $request)
    {
        $filterType = $request->get('filter_type', 'daily');
        $shift = $request->get('shift', '');
        $plant_filter = $request->get('plant', '');
        $group_filter = $request->get('group', '');

        $date = $request->get('date', Carbon::today()->format('Y-m-d'));
        $start_date = $request->get('start_date', $date);
        $end_date = $request->get('end_date', $date);

        $month = $request->get('month', Carbon::now()->format('Y-m'));
        $start_month = $request->get('start_month', $month);
        $end_month = $request->get('end_month', $month);

        $year = $request->get('year', Carbon::now()->format('Y'));

        $receptions = $this->getFilteredReceptions($filterType, $shift, $plant_filter, $group_filter, $start_date, $end_date, $start_month, $end_month, $year);


        return Excel::download(
            new \App\Exports\ProductionExport($receptions),
            'laporan_produksi_' . $filterType . '_' . date('Y-m-d') . '.xlsx'
        );
    }
}

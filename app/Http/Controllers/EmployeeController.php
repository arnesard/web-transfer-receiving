<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Employee;

class EmployeeController extends Controller
{
    public function index()
    {
        // Hanya tampilkan karyawan produksi (B, H, I, T) agar tidak campur dengan Transfer Rak
        $employees = Employee::whereIn('plant', ['B', 'H', 'I', 'T'])
            ->orderBy('name')
            ->get();
        return view('employees.index', compact('employees'));
    }

    public function create()
    {
        return view('employees.create');
    }

    public function store(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'employee_id' => 'required|string|max:50|unique:employees',
            'plant' => 'required|in:B,H,I,T',
            'group' => 'required|in:A,B,C,D',
            'department' => 'required|string|max:255',
            'position' => 'required|string|max:255',
            'default_status' => 'required|in:Team Leader,Operator,Driver Forklift',
            'primary_job_type' => 'required|in:Scan,Strapping,Tempel Stiker,Susun Tire,Pressing',
            'phone' => 'nullable|string|max:20',
            'address' => 'nullable|string|max:1000',
            'hire_date' => 'required|date',
        ]);

        Employee::create($request->all());

        return redirect()->route('employees.index')
            ->with('success', 'Karyawan berhasil ditambahkan!');
    }

    public function edit(Employee $employee)
    {
        return view('employees.edit', compact('employee'));
    }

    public function update(Request $request, Employee $employee)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'employee_id' => 'required|string|max:50|unique:employees,employee_id,' . $employee->id,
            'plant' => 'required|in:B,H,I,T',
            'group' => 'required|in:A,B,C,D',
            'department' => 'required|string|max:255',
            'position' => 'required|string|max:255',
            'default_status' => 'required|in:Team Leader,Operator,Driver Forklift',
            'primary_job_type' => 'required|in:Scan,Strapping,Tempel Stiker,Susun Tire,Pressing',
            'phone' => 'nullable|string|max:20',
            'address' => 'nullable|string|max:1000',
            'hire_date' => 'required|date',
        ]);

        $employee->update($request->all());

        return redirect()->route('employees.index')
            ->with('success', 'Data karyawan berhasil diperbarui!');
    }

    public function destroy(Employee $employee)
    {
        $employee->delete();

        return redirect()->route('employees.index')
            ->with('success', 'Karyawan berhasil dihapus!');
    }

}

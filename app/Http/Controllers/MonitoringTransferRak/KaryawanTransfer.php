<?php

namespace App\Http\Controllers\MonitoringTransferRak;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\MonitoringTransferRak\Driver;
use Illuminate\Http\Request;

class KaryawanTransfer extends Controller
{
    public function index()
    {
        $data = Employee::where('plant', 'TR')->orderBy('name')->get();
        $drivers = Driver::orderBy('nama_karyawan')->get();
        return view('MonitoringTransferRak.karyawan', compact('data', 'drivers'));
    }

    public function store(Request $request)
    {
        $request->validate([
            'name' => 'required',
            'employee_id' => 'required|unique:employees,employee_id'
        ]);

        Employee::create([
            'name' => $request->name,
            'employee_id' => $request->employee_id,
            'plant' => 'TR',
            'group' => 'TR',
            'department' => 'LOGISTIC',
            'position' => 'OPERATOR TR',
            'default_status' => 'Operator',
            'primary_job_type' => 'Scan',
            'hire_date' => now()
        ]);

        return back()->with('success', 'Karyawan berhasil ditambahkan!');
    }

    public function update(Request $request, $id)
    {
        $request->validate([
            'name' => 'required',
            'employee_id' => 'required|unique:employees,employee_id,' . $id
        ]);

        $k = Employee::where('plant', 'TR')->findOrFail($id);
        $k->update([
            'name' => $request->name,
            'employee_id' => $request->employee_id
        ]);

        return back()->with('success', 'Karyawan berhasil diupdate!');
    }

    public function destroy($id)
    {
        $k = Employee::where('plant', 'TR')->findOrFail($id);
        $k->delete();

        return back()->with('success', 'Karyawan berhasil dihapus!');
    }

    // --- MANAJEMEN SUPIR ---
    public function storeSupir(Request $request)
    {
        $request->validate(['nama_karyawan' => 'required']);
        Driver::create([
            'nama_karyawan' => $request->nama_karyawan,
            'employee_id' => 'DRV-' . time()
        ]);
        return back()->with('success', 'Supir berhasil ditambahkan!');
    }

    public function updateSupir(Request $request, $id)
    {
        $request->validate(['nama_karyawan' => 'required']);
        $d = Driver::findOrFail($id);
        $d->update(['nama_karyawan' => $request->nama_karyawan]);
        return back()->with('success', 'Nama supir berhasil diupdate!');
    }

    public function destroySupir($id)
    {
        $d = Driver::findOrFail($id);
        $d->delete();
        return back()->with('success', 'Supir berhasil dihapus!');
    }
}

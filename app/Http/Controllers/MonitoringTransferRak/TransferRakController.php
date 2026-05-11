<?php

namespace App\Http\Controllers\MonitoringTransferRak;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use App\Models\Employee;
use App\Models\MonitoringTransferRak\TransferRak;
use App\Models\MonitoringTransferRak\TransferRakDetail;
use App\Models\MonitoringTransferRak\Driver;
use App\Models\MonitoringTransferRak\Vehicle;

class TransferRakController extends Controller
{
    /**
     * Halaman utama input transfer rak
     */
    public function index()
    {
        $karyawan = Employee::where('plant', 'TR')->orderBy('name')->get();
        return view('MonitoringTransferRak.monitoring', compact('karyawan'));
    }

    /**
     * API: Cari/list supir (untuk autocomplete)
     */
    public function getDrivers(Request $request)
    {
        $search = $request->get('q', '');
        $drivers = Driver::when($search, function ($query) use ($search) {
            $query->where('nama_karyawan', 'like', "%{$search}%");
        })
            ->orderBy('nama_karyawan')
            ->limit(20)
            ->get(['id', 'nama_karyawan']);

        return response()->json($drivers);
    }

    /**
     * Mulai transfer baru: simpan header, return transfer_rak_id
     */
    public function start(Request $request)
    {
        try {
            $validated = $request->validate([
                'id_karyawan'  => 'required|integer|exists:employees,id',
                'nama_supir'   => 'required|string|max:255',
                'nama_kendaraan' => 'required|string|max:255',
                'lokasi_asal'    => 'required|string|max:255',
                'catatan' => 'nullable|string|max:1000',
            ]);

            $catatan = $validated['catatan'] ?? null;

            // Find-or-create supir by nama
            $driver = Driver::firstOrCreate(
                ['nama_karyawan' => trim($validated['nama_supir'])]
            );

            // Find-or-create kendaraan by nama
            $vehicle = Vehicle::firstOrCreate(
                ['nama_kendaraan' => trim($validated['nama_kendaraan'])]
            );

            // Cek apakah mobil ini sudah ada transfer yang sedang proses (multi-operator join)
            $existingActive = TransferRak::where('id_mobil', $vehicle->id)
                ->whereIn('status', ['proses'])
                ->first();

            if ($existingActive) {
                $totalScanned = TransferRakDetail::where('transfer_rak_id', $existingActive->id)->count();

                return response()->json([
                    'success'      => true,
                    'transfer_id'  => $existingActive->id,
                    'joined'       => true,
                    'total_sudah'  => $totalScanned,
                    'message'      => 'Bergabung ke transfer yang sedang berjalan',
                ]);
            }

            // Cek apakah mobil ini masih punya kiriman yang belum diterima semua
            // Tapi kalau statusnya 'selesai' (belum diterima), kita kasih izin buat BUKA KEMBALI (Re-open)
            $activeTransfer = TransferRak::where('id_mobil', $vehicle->id)
                ->whereIn('status', ['selesai', 'diterima', 'sebagian'])
                ->whereNull('waktu_diterima')
                ->first();

            if ($activeTransfer) {
                if ($activeTransfer->status === 'selesai' && $activeTransfer->tipe === 'transfer') {
                    // KASUS: Salah klik selesai / mau nambah rak
                    $activeTransfer->update(['status' => 'proses']); // Balikin ke proses
                    $totalScanned = TransferRakDetail::where('transfer_rak_id', $activeTransfer->id)->count();

                    return response()->json([
                        'success'      => true,
                        'transfer_id'  => $activeTransfer->id,
                        'joined'       => true,
                        'reopened'     => true,
                        'total_sudah'  => $totalScanned,
                        'message'      => 'Membuka kembali transfer LB1 untuk ditambah rak.',
                    ]);
                }

                // Kalau statusnya 'sebagian', berarti mobil sudah jalan dan bongkar di gudang 1.
                // Ini mending jangan ditambahin biar datanya gak rancu.
                $totalRak = TransferRakDetail::where('transfer_rak_id', $activeTransfer->id)->count();
                $sudahDiterima = TransferRakDetail::where('transfer_rak_id', $activeTransfer->id)
                    ->whereNotNull('waktu_diterima')->count();
                return response()->json([
                    'success' => false,
                    'error'   => 'Kendaraan "' . $vehicle->nama_kendaraan . '" sedang dalam proses bongkar (' . $sudahDiterima . '/' . $totalRak . ' rak diterima). Selesaikan penerimaan dulu.',
                ], 422);
            }

            // Buat record transfer baru
            $transfer = TransferRak::create([
                'user_id'     => Auth::id(),
                'id_karyawan' => $validated['id_karyawan'],
                'id_supir'    => $driver->id,
                'id_mobil'    => $vehicle->id,
                'lokasi_asal' => $validated['lokasi_asal'],
                'waktu_mulai' => now(),
                'status'      => 'proses',
                'catatan' => $catatan,
            ]);

            return response()->json([
                'success'      => true,
                'transfer_id'  => $transfer->id,
                'joined'       => false,
                'message'      => 'Transfer dimulai',
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Scan satu rak
     */
    public function scan(Request $request)
    {
        try {
            $validated = $request->validate([
                'transfer_rak_id' => 'required|integer|exists:transfer_raks,id',
                'kode_rak'        => 'required|string|max:100',
                'id_karyawan'     => 'required|integer|exists:employees,id',
            ]);

            $transfer = TransferRak::find($validated['transfer_rak_id']);

            if (!$transfer || $transfer->status !== 'proses') {
                return response()->json([
                    'success' => false,
                    'error'   => 'Transfer tidak aktif atau tidak ditemukan',
                ], 404);
            }

            // Cek duplikat
            $exists = TransferRakDetail::where('transfer_rak_id', $validated['transfer_rak_id'])
                ->where('kode_rak', $validated['kode_rak'])
                ->exists();

            if ($exists) {
                return response()->json([
                    'success' => false,
                    'error'   => '❌ Rak sudah discan sebelumnya!',
                    'duplicate' => true,
                ], 400);
            }

            $detail = TransferRakDetail::create([
                'transfer_rak_id'      => $validated['transfer_rak_id'],
                'kode_rak'             => $validated['kode_rak'],
                'id_karyawan_pengirim' => $validated['id_karyawan'],
                'waktu_scan'           => now(),
            ]);

            // Update total_rak realtime
            $transfer->increment('total_rak');

            $total = $transfer->fresh()->total_rak;
            return response()->json([
                'success'   => true,
                'kode_rak'  => $detail->kode_rak,
                'waktu_scan' => $detail->waktu_scan->format('H:i:s'),
                'total'     => $total,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Selesaikan transfer
     */
    public function finish(Request $request)
    {
        try {
            $validated = $request->validate([
                'transfer_rak_id' => 'required|integer|exists:transfer_raks,id',
            ]);

            $transfer = TransferRak::find($validated['transfer_rak_id']);

            if (!$transfer) {
                return response()->json([
                    'success' => false,
                    'error'   => 'Transfer tidak ditemukan',
                ], 404);
            }

            $totalScanned = TransferRakDetail::where('transfer_rak_id', $validated['transfer_rak_id'])->count();

            $transfer->update([
                'waktu_selesai' => now(),
                'total_rak'     => $totalScanned,
                'status'        => 'selesai',
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Transfer selesai',
                'total'   => $totalScanned,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * API: Scan Kendaraan saat Penerimaan
     */
    public function scanMobilPenerima(Request $request)
    {
        try {
            $validated = $request->validate([
                'nama_kendaraan' => 'required|string|max:255',
            ]);

            $vehicle = Vehicle::where('nama_kendaraan', trim($validated['nama_kendaraan']))->first();
            if (!$vehicle) {
                return response()->json(['success' => false, 'error' => 'Kendaraan tidak ditemukan di sistem'], 404);
            }

            $transfer = TransferRak::with(['karyawan', 'supir', 'details'])
                ->where('id_mobil', $vehicle->id)
                ->whereIn('status', ['selesai', 'sebagian'])
                ->latest()
                ->first();

            if (!$transfer) {
                return response()->json(['success' => false, 'error' => 'Tidak ada pengiriman aktif (belum diterima) untuk kendaraan ini'], 404);
            }

            // Ambil semua nama pengirim unik dari detail rak
            $pengirimIds = TransferRakDetail::where('transfer_rak_id', $transfer->id)
                ->whereNotNull('id_karyawan_pengirim')
                ->distinct()
                ->pluck('id_karyawan_pengirim');

            $namaPengirim = \App\Models\Employee::whereIn('id', $pengirimIds)->pluck('name')->toArray();

            // Kalo gak ada di detail, pake pengirim utama
            if (empty($namaPengirim)) {
                $namaPengirim = [$transfer->karyawan->name ?? '-'];
            }

            $totalRak = $transfer->details->count();
            $sudahDiterima = $transfer->details->whereNotNull('waktu_diterima')->count();
            $sisaRak = $totalRak - $sudahDiterima;

            // List rak yang belum diterima
            $belumDiterima = $transfer->details->whereNull('waktu_diterima')->map(fn($d) => [
                'id' => $d->id,
                'kode_rak' => $d->kode_rak,
                'waktu_scan' => $d->waktu_scan ? $d->waktu_scan->format('H:i:s') : '-',
            ])->values();

            return response()->json([
                'success' => true,
                'transfer' => [
                    'id' => $transfer->id,
                    'tipe' => $transfer->tipe ?? 'transfer',
                    'pengirim' => implode(', ', $namaPengirim),
                    'supir' => $transfer->supir->nama_karyawan ?? '-',
                    'lokasi_asal' => $transfer->lokasi_asal ?? '-',
                    'waktu_mulai' => $transfer->waktu_mulai->format('d/m/Y H:i'),
                    'total_rak' => $totalRak,
                    'sudah_diterima' => $sudahDiterima,
                    'sisa_rak' => $sisaRak,
                    'catatan' => $transfer->catatan ?? '-',
                    'jumlah_rak_kosong' => $transfer->jumlah_rak_kosong,
                    'jumlah_palet_kosong' => $transfer->jumlah_palet_kosong,
                    'belum_diterima' => $belumDiterima,
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Scan rak saat penerimaan (partial)
     */
    public function scanTerima(Request $request)
    {
        try {
            $validated = $request->validate([
                'transfer_rak_id' => 'required|integer|exists:transfer_raks,id',
                'kode_rak'        => 'required|string|max:100',
            ]);

            $detail = TransferRakDetail::where('transfer_rak_id', $validated['transfer_rak_id'])
                ->where('kode_rak', $validated['kode_rak'])
                ->first();

            if (!$detail) {
                return response()->json([
                    'success' => false,
                    'error'   => 'Rak "' . $validated['kode_rak'] . '" tidak ditemukan dalam pengiriman ini',
                ], 404);
            }

            if ($detail->waktu_diterima) {
                return response()->json([
                    'success' => false,
                    'error'   => 'Rak "' . $validated['kode_rak'] . '" sudah diterima sebelumnya di ' . $detail->lokasi_diterima,
                    'duplicate' => true,
                ], 400);
            }

            // Hitung progress
            $totalRak = TransferRakDetail::where('transfer_rak_id', $validated['transfer_rak_id'])->count();
            $sudahDiterima = TransferRakDetail::where('transfer_rak_id', $validated['transfer_rak_id'])
                ->whereNotNull('waktu_diterima')->count();

            return response()->json([
                'success'   => true,
                'detail_id' => $detail->id,
                'kode_rak'  => $detail->kode_rak,
                'total_rak' => $totalRak,
                'sudah_diterima' => $sudahDiterima,
                'sisa'      => $totalRak - $sudahDiterima,
            ]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Selesaikan penerimaan (partial — terima rak yg sudah di-scan)
     */
    public function terima(Request $request)
    {
        try {
            $validated = $request->validate([
                'transfer_rak_id'      => 'required|integer|exists:transfer_raks,id',
                'lokasi_tujuan'        => 'required|string|max:255',
                'id_karyawan_penerima' => 'required|integer|exists:employees,id',
                'kode_rak_list'        => 'nullable|array', // Jadi nullable
                'kode_rak_list.*'      => 'string',
            ]);

            $transfer = TransferRak::find($validated['transfer_rak_id']);
            if (!$transfer || !in_array($transfer->status, ['selesai', 'sebagian'])) {
                return response()->json(['success' => false, 'error' => 'Data transfer tidak valid'], 400);
            }

            if ($transfer->tipe === 'rak_kosong') {
                // Flow Rak Kosong: Langsung selesaikan semua
                $transfer->update([
                    'status'              => 'diterima',
                    'lokasi_tujuan'       => $validated['lokasi_tujuan'],
                    'id_karyawan_penerima' => $validated['id_karyawan_penerima'],
                    'waktu_diterima'      => now(),
                ]);
                return response()->json([
                    'success'        => true,
                    'message'        => 'Penerimaan rak/palet kosong berhasil diselesaikan!',
                    'fully_received' => true,
                ]);
            }

            // Flow Rak Isi (Partial):
            $kodeList = $validated['kode_rak_list'] ?? [];
            if (empty($kodeList)) {
                return response()->json(['success' => false, 'error' => 'Silakan scan rak terlebih dahulu'], 422);
            }

            $countUpdated = 0;
            foreach ($kodeList as $kodeRak) {
                $updated = TransferRakDetail::where('transfer_rak_id', $validated['transfer_rak_id'])
                    ->where('kode_rak', $kodeRak)
                    ->whereNull('waktu_diterima')
                    ->update([
                        'lokasi_diterima' => $validated['lokasi_tujuan'],
                        'id_penerima'     => $validated['id_karyawan_penerima'],
                        'waktu_diterima'  => now(),
                    ]);
                $countUpdated += $updated;
            }

            // Cek progress: semua sudah diterima?
            $totalRak = TransferRakDetail::where('transfer_rak_id', $validated['transfer_rak_id'])->count();
            $totalDiterima = TransferRakDetail::where('transfer_rak_id', $validated['transfer_rak_id'])
                ->whereNotNull('waktu_diterima')->count();

            if ($totalDiterima >= $totalRak) {
                // Semua rak sudah diterima
                $transfer->update([
                    'status'              => 'diterima',
                    'lokasi_tujuan'       => $validated['lokasi_tujuan'],
                    'id_karyawan_penerima' => $validated['id_karyawan_penerima'],
                    'waktu_diterima'      => now(),
                ]);
                $message = 'Semua rak sudah diterima! Transfer selesai.';
            } else {
                // Masih ada sisa
                $transfer->update(['status' => 'sebagian']);
                $sisa = $totalRak - $totalDiterima;
                $message = $countUpdated . ' rak diterima di ' . $validated['lokasi_tujuan'] . '. Sisa ' . $sisa . ' rak belum diterima.';
            }

            return response()->json([
                'success'        => true,
                'message'        => $message,
                'diterima'       => $countUpdated,
                'total_diterima' => $totalDiterima,
                'total_rak'      => $totalRak,
                'sisa'           => $totalRak - $totalDiterima,
                'fully_received' => $totalDiterima >= $totalRak,
            ]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Mulai transfer rak/palet kosong (input manual quantity, langsung selesai)
     */
    public function startKosong(Request $request)
    {
        try {
            $validated = $request->validate([
                'id_karyawan'        => 'required|integer|exists:employees,id',
                'nama_supir'         => 'required|string|max:255',
                'nama_kendaraan'     => 'required|string|max:255',
                'lokasi_asal'        => 'required|string|max:255',
                'jumlah_rak_kosong'  => 'nullable|integer|min:0',
                'jumlah_palet_kosong' => 'nullable|integer|min:0',
                'catatan'            => 'nullable|string|max:1000',
            ]);

            $jmlRak   = $validated['jumlah_rak_kosong'] ?? 0;
            $jmlPalet = $validated['jumlah_palet_kosong'] ?? 0;

            if ($jmlRak <= 0 && $jmlPalet <= 0) {
                return response()->json([
                    'success' => false,
                    'error'   => 'Minimal isi salah satu: jumlah rak atau palet kosong',
                ], 422);
            }

            // Find-or-create supir by nama
            $driver = Driver::firstOrCreate(
                ['nama_karyawan' => trim($validated['nama_supir'])]
            );

            // Find-or-create kendaraan by nama
            $vehicle = Vehicle::firstOrCreate(
                ['nama_kendaraan' => trim($validated['nama_kendaraan'])]
            );

            // Cek apakah mobil ini sedang dalam proses (untuk join rak isi)
            $existingProses = TransferRak::where('id_mobil', $vehicle->id)->where('status', 'proses')->first();
            if ($existingProses) {
                return response()->json([
                    'success' => false,
                    'error'   => 'Mobil ini sedang melakukan scan RAK ISI (proses). Selesaikan dulu scan rak isi baru bisa input rak kosong.',
                ], 422);
            }

            // Cek apakah mobil ini sudah ada kiriman rak kosong yang belum diterima (untuk join rak kosong)
            $existingKosong = TransferRak::where('id_mobil', $vehicle->id)
                ->where('tipe', 'rak_kosong')
                ->whereIn('status', ['selesai', 'sebagian'])
                ->first();

            if ($existingKosong) {
                $existingKosong->increment('jumlah_rak_kosong', $jmlRak);
                $existingKosong->increment('jumlah_palet_kosong', $jmlPalet);
                // Opsional: update catatan
                if ($validated['catatan']) {
                    $existingKosong->update(['catatan' => $existingKosong->catatan . " | " . $validated['catatan']]);
                }

                return response()->json([
                    'success' => true,
                    'transfer_id' => $existingKosong->id,
                    'joined' => true,
                    'message' => 'Kuantitas ditambahkan ke kiriman rak kosong yang sudah ada di mobil ini.',
                ]);
            }

            // Cek apakah mobil ini masih punya kiriman RAK ISI yang belum diterima semua
            $activeTransfer = TransferRak::where('id_mobil', $vehicle->id)
                ->where('tipe', 'transfer')
                ->whereIn('status', ['selesai', 'sebagian'])
                ->first();

            if ($activeTransfer) {
                return response()->json([
                    'success' => false,
                    'error'   => 'Mobil ini masih membawa RAK ISI yang belum diterima semua. Selesaikan penerimaan rak isi dulu.',
                ], 422);
            }

            // Buat record transfer rak kosong baru
            $transfer = TransferRak::create([
                'tipe'                => 'rak_kosong',
                'user_id'             => Auth::id(),
                'id_karyawan'         => $validated['id_karyawan'],
                'id_supir'            => $driver->id,
                'id_mobil'            => $vehicle->id,
                'lokasi_asal'         => $validated['lokasi_asal'],
                'jumlah_rak_kosong'   => $jmlRak,
                'jumlah_palet_kosong' => $jmlPalet,
                'waktu_mulai'         => now(),
                'waktu_selesai'       => now(),
                'status'              => 'selesai',
                'catatan'             => $validated['catatan'] ?? null,
            ]);

            return response()->json([
                'success' => true,
                'transfer_id' => $transfer->id,
                'joined' => false,
                'message' => 'Transfer rak/palet kosong berhasil dikirim',
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Batalkan transfer
     */
    public function cancel(Request $request)
    {

        try {
            $validated = $request->validate([
                'transfer_rak_id' => 'required|integer|exists:transfer_raks,id',
            ]);

            $transfer = TransferRak::find($validated['transfer_rak_id']);

            if ($transfer) {
                $transfer->update(['status' => 'batal']);
            }

            return response()->json(['success' => true]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Halaman dashboard
     */
    public function dashboard()
    {
        return view('MonitoringTransferRak.dashboard');
    }

    /**
     * API data untuk dashboard (KPI, trend, top operator, activity)
     */
    public function dashboardData(Request $request)
    {
        $range = $request->get('range', 'today');

        [$startDate, $endDate] = match ($range) {
            'week'  => [now()->startOfWeek(), now()->endOfDay()],
            'month' => [now()->startOfMonth(), now()->endOfDay()],
            default => [now()->startOfDay(), now()->endOfDay()],
        };

        // ── KPI ──
        $selesai = TransferRak::whereBetween('created_at', [$startDate, $endDate])
            ->whereIn('status', ['selesai', 'diterima', 'sebagian']);
        $totalRak        = (clone $selesai)->sum('total_rak');
        $transferSelesai = (clone $selesai)->count();
        $transferBatal   = TransferRak::whereBetween('created_at', [$startDate, $endDate])
            ->where('status', 'batal')->count();
        $totalDone       = $transferSelesai + $transferBatal;
        $completionRate  = $totalDone > 0 ? round(($transferSelesai / $totalDone) * 100) : 0;
        $sedangProses    = TransferRak::where('status', 'proses')->count();

        // Rata-rata durasi (menit)
        $avgDurasi = TransferRak::whereBetween('created_at', [$startDate, $endDate])
            ->whereIn('status', ['selesai', 'diterima', 'sebagian'])
            ->get()
            ->avg(fn($t) => $t->waktu_mulai && $t->waktu_selesai
                ? $t->waktu_mulai->diffInMinutes($t->waktu_selesai)
                : null);

        // ── TREND 7 HARI TERAKHIR ──
        $trendRaw = TransferRak::selectRaw('DATE(created_at) as tgl, SUM(total_rak) as total')
            ->whereIn('status', ['selesai', 'diterima', 'sebagian'])
            ->where('created_at', '>=', now()->subDays(6)->startOfDay())
            ->groupBy('tgl')
            ->orderBy('tgl')
            ->pluck('total', 'tgl');

        $trend = collect(range(6, 0))->map(function ($i) use ($trendRaw) {
            $date = now()->subDays($i)->format('Y-m-d');
            return [
                'date'  => now()->subDays($i)->format('d/m'),
                'total' => (int) ($trendRaw[$date] ?? 0),
            ];
        });

        // ── TOP 5 OPERATOR ──
        $topOperators = TransferRak::select(
            'id_karyawan',
            DB::raw('SUM(total_rak) as total_rak'),
            DB::raw('COUNT(*) as jumlah')
        )
            ->with('karyawan:id,name')
            ->whereBetween('created_at', [$startDate, $endDate])
            ->whereIn('status', ['selesai', 'diterima', 'sebagian'])
            ->whereNull('waktu_diterima')
            ->groupBy('id_karyawan')
            ->orderByDesc('total_rak')
            ->limit(5)
            ->get()
            ->map(fn($t) => [
                'nama'  => $t->karyawan?->name ?? 'Unknown',
                'total' => (int) $t->total_rak,
                'count' => (int) $t->jumlah,
            ]);

        // ── ACTIVITY FEED (10 terbaru) ──
        $activity = TransferRak::with([
            'karyawan:id,name',
            'penerima:id,name', // Tambahkan relasi penerima
            'supir:id,nama_karyawan',
            'mobil:id,nama_kendaraan',
        ])
            ->orderByDesc('updated_at') // Urutkan berdasarkan aktivitas terakhir
            ->limit(10)
            ->get()
            ->map(function ($t) {
                // Ambil semua nama pengirim unik dari detail (kalo ada)
                $details = \App\Models\MonitoringTransferRak\TransferRakDetail::where('transfer_rak_id', $t->id)
                    ->with(['karyawanPengirim', 'penerima'])
                    ->get();

                $senderNames = $details->pluck('karyawanPengirim.name')->filter()->unique()->values()->toArray();
                $senders = !empty($senderNames) ? implode(', ', $senderNames) : ($t->karyawan?->name ?? '-');

                // Ambil semua nama penerima unik dari detail
                $receiverNames = $details->pluck('penerima.name')->filter()->unique()->values()->toArray();
                $receivers = !empty($receiverNames) ? implode(', ', $receiverNames) : ($t->penerima?->name ?? '-');

                return [
                    'tipe'      => $t->tipe,
                    'operator_kirim'   => $senders,
                    'operator_terima'  => $receivers,
                    'supir'     => $t->supir?->nama_karyawan ?? '-',
                    'mobil'     => $t->mobil?->nama_kendaraan ?? '-',
                    'total_rak' => ($t->tipe === 'rak_kosong') ? $t->jumlah_rak_kosong : $t->total_rak,
                    'total_palet' => $t->jumlah_palet_kosong,
                    'status'    => $t->status,
                    'jam_kirim' => $t->waktu_mulai ? $t->waktu_mulai->format('H:i') : '-',
                    'jam_terima' => $t->waktu_diterima ? $t->waktu_diterima->format('H:i') : '-',
                    'tgl'       => $t->created_at->format('d/m'),
                ];
            });

        return response()->json([
            'kpi' => [
                'total_rak'        => (int) $totalRak,
                'transfer_selesai' => $transferSelesai,
                'completion_rate'  => $completionRate,
                'avg_durasi'       => $avgDurasi ? round($avgDurasi) . ' mnt' : '-',
                'sedang_proses'    => $sedangProses,
            ],
            'trend'         => $trend,
            'top_operators' => $topOperators,
            'activity'      => $activity,
        ]);
    }
}

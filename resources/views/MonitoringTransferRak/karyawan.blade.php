@extends('MonitoringTransferRak.app')

@section('title', 'Manajemen Karyawan & Supir')

@push('styles')
    <style>
        .page-wrap { padding: 16px; color: #e2e8f0; position: relative; }
        .page-title { font-size: 18px; font-weight: 800; margin-bottom: 20px; color: #64c8ff; display: flex; align-items: center; gap: 10px; }
        
        /* TABS */
        .tabs { display: flex; gap: 8px; margin-bottom: 20px; background: rgba(15, 23, 42, 0.4); padding: 5px; border-radius: 12px; border: 1px solid rgba(255, 255, 255, 0.05); }
        .tab-btn { flex: 1; padding: 10px; border: none; border-radius: 8px; background: transparent; color: #64748b; font-weight: 700; cursor: pointer; transition: all 0.3s; }
        .tab-btn.active { background: rgba(59, 130, 246, 0.2); color: #64c8ff; border: 1px solid rgba(59, 130, 246, 0.3); }
        .tab-content { display: none; animation: fadeIn 0.3s ease; }
        .tab-content.active { display: block; }
        @keyframes fadeIn { from { opacity: 0; transform: translateY(5px); } to { opacity: 1; transform: translateY(0); } }

        .card { background: rgba(30, 41, 59, 0.5); border: 1px solid rgba(255, 255, 255, 0.05); border-radius: 16px; padding: 16px; margin-bottom: 20px; box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1); }
        .form-row { display: flex; gap: 10px; flex-wrap: wrap; }
        .input { flex: 1; min-width: 150px; padding: 12px 16px; border-radius: 10px; border: 1px solid rgba(59, 130, 246, 0.2); background: rgba(15, 23, 42, 0.8); color: #e2e8f0; outline: none; transition: border 0.3s; font-size: 14px; }
        .input:focus { border-color: #3b82f6; }
        .btn-add { padding: 10px 20px; border: none; border-radius: 10px; background: linear-gradient(135deg, #3b82f6, #6366f1); color: white; font-weight: 700; cursor: pointer; }
        
        .list { display: flex; flex-direction: column; gap: 8px; }
        .item { display: flex; justify-content: space-between; align-items: center; padding: 12px 16px; border-radius: 12px; background: rgba(255, 255, 255, 0.03); border: 1px solid rgba(255, 255, 255, 0.03); transition: all 0.2s; }
        .item:hover { background: rgba(255, 255, 255, 0.05); border-color: rgba(59, 130, 246, 0.2); }
        
        .edit-form { display: flex; gap: 8px; flex: 1; flex-wrap: wrap; }
        .btn-update { padding: 8px 14px; border: none; border-radius: 8px; background: rgba(34, 197, 94, 0.1); color: #4ade80; font-weight: 600; font-size: 12px; cursor: pointer; }
        .btn-del { width: 36px; height: 36px; border: none; border-radius: 8px; background: rgba(239, 68, 68, 0.1); color: #f87171; display: flex; align-items: center; justify-content: center; cursor: pointer; margin-left: 8px; flex-shrink: 0; }
        
        /* Modal Fix */
        .modal { z-index: 2050 !important; }
        .modal-backdrop { z-index: 2040 !important; }
        .modal-content { background: #1e293b !important; border: 1px solid rgba(255,255,255,0.1) !important; color: #f1f5f9 !important; }

        .empty { text-align: center; color: #64748b; padding: 40px; font-size: 14px; }
        
        @media (max-width: 600px) {
            .form-row { flex-direction: column; }
            .btn-add { width: 100%; }
        }
    </style>
@endpush

@section('content')
<div class="page-wrap">
    <div class="page-title">
        <i data-lucide="database"></i> Master Data Transfer Rak
    </div>

    {{-- TABS --}}
    <div class="tabs">
        <button id="btn-tab-karyawan" class="tab-btn active" onclick="showTab('karyawan')">Karyawan Transfer</button>
        <button id="btn-tab-supir" class="tab-btn" onclick="showTab('supir')">Daftar Supir</button>
    </div>

    @if(session('success'))
        <div style="background: rgba(34,197,94,0.1); color: #4ade80; padding: 12px; border-radius: 10px; margin-bottom: 20px; border: 1px solid rgba(34,197,94,0.2);">
            <i data-lucide="check-circle" style="width: 16px; vertical-align: middle;"></i> {{ session('success') }}
        </div>
    @endif
    
    @if($errors->any())
        <div style="background: rgba(239,68,68,0.1); color: #f87171; padding: 12px; border-radius: 10px; margin-bottom: 20px; border: 1px solid rgba(239,68,68,0.2);">
            {{ $errors->first() }}
        </div>
    @endif

    {{-- TAB KARYAWAN --}}
    <div id="tab-karyawan" class="tab-content active">
        <div class="card">
            <h6 style="font-size: 13px; color: #94a3b8; margin-bottom: 12px;">Tambah Karyawan Baru</h6>
            <form method="POST" action="{{ route('karyawan.store') }}" class="form-row">
                @csrf
                <input name="employee_id" placeholder="ID Karyawan..." class="input" style="flex: 0.4;" required>
                <input name="name" placeholder="Nama lengkap..." class="input" required>
                <button class="btn-add">＋ TAMBAH</button>
            </form>
        </div>

        <div class="list">
            @forelse ($data as $k)
                <div class="item">
                    <form method="POST" action="{{ route('karyawan.update', $k->id) }}" class="edit-form">
                        @csrf
                        @method('PUT')
                        <input name="employee_id" value="{{ $k->employee_id }}" class="input" style="flex: 0.3; padding: 8px 12px; font-size: 13px;">
                        <input name="name" value="{{ $k->name }}" class="input" style="padding: 8px 12px; font-size: 13px;">
                        <button class="btn-update">UPDATE</button>
                    </form>
                    <button class="btn-del" onclick="confirmDelete('karyawan', {{ $k->id }}, '{{ addslashes($k->name) }}')">
                        <i data-lucide="trash-2" style="width: 16px;"></i>
                    </button>
                </div>
            @empty
                <div class="empty">Belum ada data karyawan.</div>
            @endforelse
        </div>
    </div>

    {{-- TAB SUPIR --}}
    <div id="tab-supir" class="tab-content">
        <div class="card">
            <h6 style="font-size: 13px; color: #94a3b8; margin-bottom: 12px;">Tambah Supir Baru</h6>
            <form method="POST" action="{{ route('supir.store') }}" class="form-row">
                @csrf
                <input name="nama_karyawan" placeholder="Nama supir..." class="input" required>
                <button class="btn-add" style="background: linear-gradient(135deg, #10b981, #059669);">＋ TAMBAH</button>
            </form>
        </div>

        <div class="list">
            @forelse ($drivers as $d)
                <div class="item">
                    <form method="POST" action="{{ route('supir.update', $d->id) }}" class="edit-form">
                        @csrf
                        @method('PUT')
                        <input name="nama_karyawan" value="{{ $d->nama_karyawan }}" class="input" style="padding: 8px 12px; font-size: 13px;">
                        <button class="btn-update">UPDATE</button>
                    </form>
                    <button class="btn-del" onclick="confirmDelete('supir', {{ $d->id }}, '{{ addslashes($d->nama_karyawan) }}')">
                        <i data-lucide="trash-2" style="width: 16px;"></i>
                    </button>
                </div>
            @empty
                <div class="empty">Belum ada data supir.</div>
            @endforelse
        </div>
    </div>
</div>

{{-- MODAL DELETE --}}
<div class="modal fade" id="deleteModal" tabindex="-1" aria-hidden="true" style="display: none;">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content shadow-lg">
            <div class="modal-header border-0">
                <h5 class="modal-title fw-bold">Hapus Data</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" style="color: #cbd5e1;">
                Apakah Anda yakin ingin menghapus <strong id="delName" style="color: #fff;"></strong>?
                <p style="font-size: 12px; color: #ef4444; margin-top: 10px;">*Tindakan ini tidak dapat dibatalkan.</p>
            </div>
            <div class="modal-footer border-0">
                <button type="button" class="btn btn-link text-slate-400 text-decoration-none" data-bs-dismiss="modal">Batal</button>
                <form id="delForm" method="POST" class="m-0">
                    @csrf
                    @method('DELETE')
                    <button class="btn btn-danger rounded-pill px-4 fw-bold">Hapus Sekarang</button>
                </form>
            </div>
        </div>
    </div>
</div>
@endsection

@push('scripts')
    <script>
        function showTab(tabName) {
            // Update buttons
            document.querySelectorAll('.tab-btn').forEach(btn => {
                btn.classList.remove('active');
            });
            document.getElementById('btn-tab-' + tabName).classList.add('active');
            
            // Update content
            document.querySelectorAll('.tab-content').forEach(content => {
                content.classList.remove('active');
            });
            document.getElementById('tab-' + tabName).classList.add('active');
            
            localStorage.setItem('activeTabTransfer', tabName);
        }

        // Restore active tab
        document.addEventListener('DOMContentLoaded', () => {
            const lastTab = localStorage.getItem('activeTabTransfer') || 'karyawan';
            showTab(lastTab);
            if(window.lucide) lucide.createIcons();
        });

        function confirmDelete(type, id, name) {
            const form = document.getElementById('delForm');
            const nameEl = document.getElementById('delName');
            const baseUrl = "{{ url('transfer-rak/karyawan') }}";
            
            nameEl.textContent = name;
            if(type === 'karyawan') {
                form.action = `${baseUrl}/delete/${id}`;
            } else {
                form.action = `${baseUrl}/supir/delete/${id}`;
            }
            
            const modalEl = document.getElementById('deleteModal');
            const modal = bootstrap.Modal.getOrCreateInstance(modalEl);
            modal.show();
        }
    </script>
@endpush

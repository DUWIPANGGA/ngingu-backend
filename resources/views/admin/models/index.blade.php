@extends('layouts.admin')

@section('title', 'Model ML & Dataset')
@section('header_title', 'Model ML & Dataset')

@section('content')
<div class="flex flex-wrap items-center justify-between gap-4 mb-6">
    <div>
        <h3 class="text-lg font-extrabold text-[#1F2937]">Manajemen Model ML</h3>
        <p class="text-sm text-gray-400">Upload versi model, switch versi aktif, rollback, dan kelola dataset berlabel (FR-09.2.2 — FR-09.2.3)</p>
    </div>
    <form action="{{ route('admin.models.rollback') }}" method="POST" onsubmit="return confirm('Rollback ke versi model sebelumnya?')">
        @csrf
        <button type="submit" class="bg-white border border-gray-200 hover:bg-gray-50 text-gray-600 text-sm font-bold px-4 py-2.5 rounded-xl shadow-sm transition-colors flex items-center gap-2">
            <i class="fa-solid fa-rotate-left"></i> Rollback Versi Aktif
        </button>
    </form>
</div>

<div class="grid grid-cols-1 xl:grid-cols-3 gap-6 mb-8">
    <!-- Daftar versi -->
    <div class="xl:col-span-2 bg-white rounded-2xl shadow-sm border border-gray-200 overflow-hidden">
        <div class="px-5 py-4 border-b border-gray-100">
            <h4 class="font-bold text-sm text-[#0F767E] uppercase tracking-wider">Versi Model</h4>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="bg-gray-50 border-b border-gray-200 text-left text-xs font-bold text-gray-400 uppercase tracking-wider">
                        <th class="px-5 py-3.5">Versi</th>
                        <th class="px-5 py-3.5">Akurasi</th>
                        <th class="px-5 py-3.5">Diunggah</th>
                        <th class="px-5 py-3.5 text-center">Status</th>
                        <th class="px-5 py-3.5 text-right">Aksi</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @forelse($models as $model)
                        <tr class="hover:bg-gray-50 transition-colors {{ $model->is_active ? 'bg-emerald-50/40' : '' }}">
                            <td class="px-5 py-4">
                                <div class="font-bold text-[#1F2937]">{{ $model->version }}</div>
                                <div class="text-xs text-gray-400">{{ $model->filename }}</div>
                            </td>
                            <td class="px-5 py-4 text-gray-500">
                                {{ $model->accuracy_pct !== null ? $model->accuracy_pct.'%' : '—' }}
                            </td>
                            <td class="px-5 py-4 text-xs text-gray-400">
                                {{ $model->created_at->format('d/m/Y H:i') }}<br>oleh {{ $model->uploader?->name ?? '—' }}
                            </td>
                            <td class="px-5 py-4 text-center">
                                @if ($model->is_active)
                                    <span class="px-2.5 py-1 rounded-full text-[11px] font-bold bg-emerald-100 text-emerald-700">AKTIF</span>
                                @else
                                    <span class="px-2.5 py-1 rounded-full text-[11px] font-bold bg-gray-100 text-gray-500">nonaktif</span>
                                @endif
                            </td>
                            <td class="px-5 py-4">
                                <div class="flex items-center justify-end gap-1.5">
                                    @unless ($model->is_active)
                                        <form action="{{ route('admin.models.activate', $model) }}" method="POST" onsubmit="return confirm('Jadikan versi ini aktif?')">
                                            @csrf
                                            <button type="submit" class="text-[#0F767E] hover:bg-teal-50 px-2.5 py-2 rounded-lg transition-colors" title="Aktifkan">
                                                <i class="fa-solid fa-bolt"></i>
                                            </button>
                                        </form>
                                        <form action="{{ route('admin.models.destroy', $model) }}" method="POST" onsubmit="return confirm('Hapus versi ini?')">
                                            @csrf
                                            @method('DELETE')
                                            <button type="submit" class="text-red-500 hover:bg-red-50 px-2.5 py-2 rounded-lg transition-colors" title="Hapus">
                                                <i class="fa-solid fa-trash"></i>
                                            </button>
                                        </form>
                                    @else
                                        <span class="text-xs text-gray-400 pr-2">dipakai</span>
                                    @endunless
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="px-5 py-10 text-center text-gray-400">
                                <i class="fa-solid fa-microchip text-3xl block mb-2 text-gray-300"></i>
                                Belum ada versi model yang diunggah.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <!-- Upload model -->
    <div class="bg-white rounded-2xl shadow-sm border border-gray-200 p-6">
        <h4 class="font-bold text-sm text-[#0F767E] uppercase tracking-wider mb-4">Upload Versi Baru</h4>
        <form action="{{ route('admin.models.store') }}" method="POST" enctype="multipart/form-data">
            @csrf
            <div class="space-y-4">
                <div>
                    <label class="block text-xs font-bold text-gray-500 mb-1.5">Versi *</label>
                    <input type="text" name="version" value="{{ old('version') }}" placeholder="mis. v1.2.0" required
                           class="w-full border border-gray-200 rounded-xl px-4 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-teal-500/40">
                </div>
                <div>
                    <label class="block text-xs font-bold text-gray-500 mb-1.5">File Model * (.h5/.onnx/.pt/.zip)</label>
                    <input type="file" name="model_file" required
                           class="w-full border border-dashed border-gray-300 rounded-xl px-4 py-2.5 text-sm file:mr-3 file:rounded-lg file:border-0 file:bg-teal-50 file:text-[#0F767E] file:font-bold file:px-3 file:py-1.5">
                </div>
                <div>
                    <label class="block text-xs font-bold text-gray-500 mb-1.5">Akurasi (%)</label>
                    <input type="number" name="accuracy_pct" min="0" max="100" step="0.01" value="{{ old('accuracy_pct') }}"
                           class="w-full border border-gray-200 rounded-xl px-4 py-2.5 text-sm focus:outline-none">
                </div>
                <div>
                    <label class="block text-xs font-bold text-gray-500 mb-1.5">Label Penyakit (satu per baris)</label>
                    <textarea name="disease_labels" rows="4" placeholder="White Spot (Ich)&#10;Columnaris"
                              class="w-full border border-gray-200 rounded-xl px-4 py-2.5 text-sm focus:outline-none">{{ old('disease_labels') }}</textarea>
                </div>
            </div>
            <button type="submit" class="mt-5 w-full bg-[#0F767E] hover:bg-[#0a5258] text-white text-sm font-bold px-4 py-2.5 rounded-xl transition-colors">
                <i class="fa-solid fa-upload"></i> Unggah Model
            </button>
        </form>
    </div>
</div>

<!-- Dataset -->
<div class="grid grid-cols-1 xl:grid-cols-3 gap-6">
    <div class="xl:col-span-2 bg-white rounded-2xl shadow-sm border border-gray-200 overflow-hidden">
        <div class="px-5 py-4 border-b border-gray-100">
            <h4 class="font-bold text-sm text-[#0F767E] uppercase tracking-wider">Dataset Training Berlabel</h4>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="bg-gray-50 border-b border-gray-200 text-left text-xs font-bold text-gray-400 uppercase tracking-wider">
                        <th class="px-5 py-3.5">File</th>
                        <th class="px-5 py-3.5">Label</th>
                        <th class="px-5 py-3.5">Penyakit</th>
                        <th class="px-5 py-3.5">Diunggah</th>
                        <th class="px-5 py-3.5 text-right">Aksi</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @forelse($datasets as $dataset)
                        <tr class="hover:bg-gray-50 transition-colors">
                            <td class="px-5 py-4 font-medium text-gray-700">{{ $dataset->filename }}</td>
                            <td class="px-5 py-4">
                                <span class="px-2.5 py-1 rounded-full text-[11px] font-bold bg-teal-50 text-[#0F767E]">{{ $dataset->label }}</span>
                            </td>
                            <td class="px-5 py-4 text-gray-500 text-xs">{{ $dataset->disease?->name ?? '—' }}</td>
                            <td class="px-5 py-4 text-xs text-gray-400">{{ $dataset->created_at->format('d/m/Y H:i') }}</td>
                            <td class="px-5 py-4 text-right">
                                <form action="{{ route('admin.datasets.destroy', $dataset) }}" method="POST" onsubmit="return confirm('Hapus dataset ini?')">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="text-red-500 hover:bg-red-50 px-2.5 py-2 rounded-lg transition-colors" title="Hapus">
                                        <i class="fa-solid fa-trash"></i>
                                    </button>
                                </form>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="px-5 py-10 text-center text-gray-400">
                                <i class="fa-solid fa-images text-3xl block mb-2 text-gray-300"></i>
                                Belum ada dataset terunggah.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="px-5 py-4 border-t border-gray-100">
            {{ $datasets->links() }}
        </div>
    </div>

    <div class="bg-white rounded-2xl shadow-sm border border-gray-200 p-6">
        <h4 class="font-bold text-sm text-[#0F767E] uppercase tracking-wider mb-4">Upload Gambar Berlabel</h4>
        <form action="{{ route('admin.datasets.store') }}" method="POST" enctype="multipart/form-data">
            @csrf
            <div class="space-y-4">
                <div>
                    <label class="block text-xs font-bold text-gray-500 mb-1.5">Gambar (jpg/png/webp, maks 50 file)</label>
                    <input type="file" name="images[]" multiple required accept=".jpg,.jpeg,.png,.webp"
                           class="w-full border border-dashed border-gray-300 rounded-xl px-4 py-2.5 text-sm file:mr-3 file:rounded-lg file:border-0 file:bg-teal-50 file:text-[#0F767E] file:font-bold file:px-3 file:py-1.5">
                </div>
                <div>
                    <label class="block text-xs font-bold text-gray-500 mb-1.5">Kaitkan ke Penyakit</label>
                    <select name="disease_id" class="w-full border border-gray-200 rounded-xl px-4 py-2.5 text-sm bg-white focus:outline-none">
                        <option value="">— tanpa penyakit —</option>
                        @foreach (\App\Models\Disease::orderBy('name')->pluck('name', 'id') as $id => $name)
                            <option value="{{ $id }}" @selected(old('disease_id') == $id)>{{ $name }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="block text-xs font-bold text-gray-500 mb-1.5">Label (wajib jika tanpa penyakit)</label>
                    <input type="text" name="label" value="{{ old('label') }}" placeholder="mis. white_spot"
                           class="w-full border border-gray-200 rounded-xl px-4 py-2.5 text-sm focus:outline-none">
                </div>
            </div>
            <button type="submit" class="mt-5 w-full bg-[#0F767E] hover:bg-[#0a5258] text-white text-sm font-bold px-4 py-2.5 rounded-xl transition-colors">
                <i class="fa-solid fa-cloud-arrow-up"></i> Unggah Dataset
            </button>
        </form>
    </div>
</div>
@endsection

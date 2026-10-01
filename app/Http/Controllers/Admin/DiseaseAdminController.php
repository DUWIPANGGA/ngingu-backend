<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Disease;
use App\Models\DiseasePrevention;
use App\Models\DiseaseTreatment;
use App\Support\Audit;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * FR-09.2.1 — CRUD ensiklopedia penyakit (termasuk treatment & prevention).
 */
class DiseaseAdminController extends Controller
{
    public function index(Request $request)
    {
        $diseases = Disease::withCount(['treatments', 'preventions'])
            ->when($request->filled('q'), function ($q) use ($request) {
                $q->where(function ($q) use ($request) {
                    $q->where('name', 'like', "%{$request->q}%")
                        ->orWhere('name_en', 'like', "%{$request->q}%");
                });
            })
            ->when($request->filled('category'), fn ($q) => $q->where('category', $request->category))
            ->when($request->filled('status'), function ($q) use ($request) {
                $q->where('is_published', $request->status === 'published');
            })
            ->orderBy('name')
            ->paginate(12)
            ->withQueryString();

        return view('admin.diseases.index', compact('diseases'));
    }

    public function create()
    {
        return view('admin.diseases.create');
    }

    public function store(Request $request)
    {
        $data = $this->validateData($request);
        $data['slug'] = $this->uniqueSlug($data['name']);
        $data['created_by'] = auth()->id();
        $data['is_published'] = $request->boolean('is_published');

        $disease = Disease::create($data);
        $this->syncGuides($disease, $request);

        Audit::log('disease.created', 'Penyakit "'.$disease->name.'" ditambahkan ke ensiklopedia.', $disease);

        return redirect()->route('admin.diseases.index')
            ->with('success', 'Penyakit "'.$disease->name.'" berhasil ditambahkan.');
    }

    public function edit(Disease $disease)
    {
        $disease->load(['treatments', 'preventions']);

        return view('admin.diseases.edit', compact('disease'));
    }

    public function update(Request $request, Disease $disease)
    {
        $data = $this->validateData($request, $disease);

        if ($disease->name !== $data['name']) {
            $data['slug'] = $this->uniqueSlug($data['name'], $disease);
        }

        $data['is_published'] = $request->boolean('is_published');

        $disease->update($data);
        $this->syncGuides($disease, $request);

        Audit::log('disease.updated', 'Penyakit "'.$disease->name.'" diperbarui.', $disease);

        return redirect()->route('admin.diseases.index')
            ->with('success', 'Penyakit "'.$disease->name.'" berhasil diperbarui.');
    }

    public function destroy(Disease $disease)
    {
        $name = $disease->name;
        $disease->delete();
        Audit::log('disease.deleted', 'Penyakit "'.$name.'" dihapus dari ensiklopedia.');

        return redirect()->route('admin.diseases.index')
            ->with('success', 'Penyakit "'.$name.'" berhasil dihapus.');
    }

    private function validateData(Request $request, ?Disease $disease = null): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:255', Rule::unique('diseases', 'name')->ignore($disease?->id)],
            'name_en' => 'nullable|string|max:255',
            'category' => 'required|in:bacteria,virus,parasite,fungi,other',
            'severity' => 'required|in:ringan,sedang,berat,kritis',
            'description' => 'nullable|string',
            'causes' => 'nullable|string',
            'symptoms' => 'nullable|string',
            'recovery_days_min' => 'nullable|integer|min:0|max:999',
            'recovery_days_max' => 'nullable|integer|min:0|max:999',
            'is_published' => 'sometimes|boolean',
            'treatments' => 'sometimes|array',
            'treatments.*.title' => 'required|string|max:255',
            'treatments.*.description' => 'nullable|string',
            'treatments.*.duration_days' => 'nullable|integer|min:0|max:999',
            'preventions' => 'sometimes|array',
            'preventions.*.description' => 'required|string',
        ], [
            'name.required' => 'Nama penyakit wajib diisi.',
            'name.unique' => 'Nama penyakit sudah ada di ensiklopedia.',
            'category.required' => 'Kategori wajib dipilih.',
            'severity.required' => 'Tingkat keparahan wajib dipilih.',
            'treatments.*.title.required' => 'Judul langkah pengobatan wajib diisi.',
            'preventions.*.description.required' => 'Deskripsi pencegahan wajib diisi.',
        ]);
    }

    /**
     * Sinkronisasi langkah pengobatan & pencegahan dari input berulang.
     */
    private function syncGuides(Disease $disease, Request $request): void
    {
        $treatments = collect($request->input('treatments', []))
            ->filter(fn ($row) => trim((string) ($row['title'] ?? '')) !== '');

        $existingTreatmentIds = $disease->treatments()->pluck('id')->all();
        $keptTreatmentIds = [];

        foreach ($treatments->values() as $index => $row) {
            $attributes = [
                'step_order' => $index + 1,
                'title' => $row['title'],
                'description' => $row['description'] ?? null,
                'duration_days' => $row['duration_days'] ?? null,
            ];

            if (! empty($row['id']) && in_array((int) $row['id'], $existingTreatmentIds, true)) {
                DiseaseTreatment::whereKey($row['id'])->update($attributes);
                $keptTreatmentIds[] = (int) $row['id'];
            } else {
                $keptTreatmentIds[] = $disease->treatments()->create($attributes)->id;
            }
        }

        DiseaseTreatment::where('disease_id', $disease->id)
            ->when($keptTreatmentIds !== [], fn ($q) => $q->whereNotIn('id', $keptTreatmentIds))
            ->delete();

        $preventions = collect($request->input('preventions', []))
            ->filter(fn ($row) => trim((string) ($row['description'] ?? '')) !== '');

        $existingPreventionIds = $disease->preventions()->pluck('id')->all();
        $keptPreventionIds = [];

        foreach ($preventions->values() as $index => $row) {
            $attributes = [
                'step_order' => $index + 1,
                'description' => $row['description'],
            ];

            if (! empty($row['id']) && in_array((int) $row['id'], $existingPreventionIds, true)) {
                DiseasePrevention::whereKey($row['id'])->update($attributes);
                $keptPreventionIds[] = (int) $row['id'];
            } else {
                $keptPreventionIds[] = $disease->preventions()->create($attributes)->id;
            }
        }

        DiseasePrevention::where('disease_id', $disease->id)
            ->when($keptPreventionIds !== [], fn ($q) => $q->whereNotIn('id', $keptPreventionIds))
            ->delete();
    }

    private function uniqueSlug(string $name, ?Disease $ignore = null): string
    {
        $base = Str::slug($name) ?: 'penyakit';
        $slug = $base;
        $i = 2;

        while (Disease::where('slug', $slug)->when($ignore, fn ($q) => $q->where('id', '!=', $ignore->id))->exists()) {
            $slug = $base.'-'.$i++;
        }

        return $slug;
    }
}

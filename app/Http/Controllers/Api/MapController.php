<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Peta sebaran penyakit (API.md §7.11, FR-07.3).
 */
class MapController extends Controller
{
    use ApiResponse;

    /**
     * GET /api/map/heatmap (publik).
     */
    public function heatmap(Request $request): JsonResponse
    {
        $data = $request->validate([
            'days' => 'sometimes|integer|min:1|max:365',
            'province' => 'sometimes|string|max:80',
            'disease_id' => 'sometimes|integer|exists:diseases,id',
        ]);

        $days = (int) ($data['days'] ?? 30);

        $rows = DB::table('fish_scans')
            ->join('users', 'users.id', '=', 'fish_scans.user_id')
            ->join('scan_results', 'scan_results.scan_id', '=', 'fish_scans.id')
            ->join('diseases', 'diseases.id', '=', 'scan_results.disease_id')
            ->leftJoin('user_ponds', 'user_ponds.id', '=', 'fish_scans.pond_id')
            ->where('fish_scans.status', 'done')
            ->where('fish_scans.created_at', '>=', now()->subDays($days))
            ->when(! empty($data['province']), fn ($q) => $q->where('users.province', $data['province']))
            ->when(! empty($data['disease_id']), fn ($q) => $q->where('scan_results.disease_id', $data['disease_id']))
            ->whereNotNull('users.province')
            ->get([
                'users.province as province',
                'diseases.id as disease_id',
                'diseases.name as disease_name',
                'user_ponds.location_lat as lat',
                'user_ponds.location_lng as lng',
                'fish_scans.location_lat as scan_lat',
                'fish_scans.location_lng as scan_lng',
            ]);

        $map = [];

        foreach ($rows as $row) {
            $key = $row->province;
            $map[$key] ??= ['province' => $key, 'cases' => 0, 'lats' => [], 'lngs' => [], 'diseases' => []];
            $map[$key]['cases']++;

            // Utamakan koordinat GPS saat scan dilakukan; lokasi kolam
            // menjadi cadangan bila scan tidak membawa koordinat.
            $lat = $row->scan_lat ?? $row->lat;
            $lng = $row->scan_lng ?? $row->lng;

            if ($lat !== null && $lng !== null) {
                $map[$key]['lats'][] = (float) $lat;
                $map[$key]['lngs'][] = (float) $lng;
            }

            $map[$key]['diseases'][$row->disease_id] = [
                'name' => $row->disease_name,
                'count' => ($map[$key]['diseases'][$row->disease_id]['count'] ?? 0) + 1,
            ];
        }

        $heatmap = collect($map)
            ->map(function (array $entry) {
                $top = collect($entry['diseases'])->sortByDesc('count')->first();

                return [
                    'province' => $entry['province'],
                    'lat' => $entry['lats'] !== [] ? round(array_sum($entry['lats']) / count($entry['lats']), 4) : null,
                    'lng' => $entry['lngs'] !== [] ? round(array_sum($entry['lngs']) / count($entry['lngs']), 4) : null,
                    'cases' => $entry['cases'],
                    'top_disease' => $top['name'] ?? null,
                ];
            })
            ->sortByDesc('cases')
            ->values();

        return $this->ok($heatmap);
    }
}

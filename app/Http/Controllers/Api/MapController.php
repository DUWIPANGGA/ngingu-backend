<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Peta sebaran penyakit (API.md §7.11 heatmap, §7.13 pins, FR-07.3).
 */
class MapController extends Controller
{
    use ApiResponse;

    /**
     * GET /api/map/pins (publik) — GIS: setiap scan individu yang memiliki
     * koordinat, digunakan mobile untuk menandai lokasi tepat di peta.
     * Koordinat yang dipakai adalah GPS saat scan dilakukan; koordinat
     * tambak menjadi cadangan bila scan tidak membawa GPS.
     *
     * Filter opsional: `days` (default 30), `disease_id`.
     *
     * Response item:
     *  { id, lat, lng, pond_name, location_label, disease_id, disease_name,
     *    severity, confidence_score, fish_type, scanned_at }
     */
    public function pins(Request $request): JsonResponse
    {
        $data = $request->validate([
            'days'       => 'sometimes|integer|min:1|max:365',
            'disease_id' => 'sometimes|integer|exists:diseases,id',
        ]);

        $days = (int) ($data['days'] ?? 30);

        $pins = \Illuminate\Support\Facades\DB::table('fish_scans')
            ->leftJoin('user_ponds', 'user_ponds.id', '=', 'fish_scans.pond_id')
            ->join('scan_results', 'scan_results.scan_id', '=', 'fish_scans.id')
            ->join('diseases', 'diseases.id', '=', 'scan_results.disease_id')
            ->where('fish_scans.status', 'done')
            ->where('fish_scans.created_at', '>=', now()->subDays($days))
            ->where(function ($q) {
                $q->where(function ($q2) {
                    $q2->whereNotNull('fish_scans.location_lat')
                        ->whereNotNull('fish_scans.location_lng');
                })->orWhere(function ($q2) {
                    $q2->whereNotNull('user_ponds.location_lat')
                        ->whereNotNull('user_ponds.location_lng');
                });
            })
            ->when(! empty($data['disease_id']), fn ($q) => $q->where('scan_results.disease_id', $data['disease_id']))
            ->orderByDesc('fish_scans.created_at')
            ->get([
                'fish_scans.id          as id',
                'fish_scans.location_lat  as scan_lat',
                'fish_scans.location_lng  as scan_lng',
                'user_ponds.location_lat  as pond_lat',
                'user_ponds.location_lng  as pond_lng',
                'user_ponds.name          as pond_name',
                'user_ponds.location_label as location_label',
                'scan_results.disease_id   as disease_id',
                'diseases.name            as disease_name',
                'diseases.severity        as severity',
                'scan_results.confidence_score as confidence_score',
                'fish_scans.fish_type     as fish_type',
                'fish_scans.created_at    as scanned_at',
            ])
            ->map(fn ($row) => [
                'id'               => $row->id,
                // Utamakan GPS saat scan; lokasi kolam jadi cadangan.
                'lat'              => round((float) ($row->scan_lat ?? $row->pond_lat), 6),
                'lng'              => round((float) ($row->scan_lng ?? $row->pond_lng), 6),
                'pond_name'        => $row->pond_name,
                'location_label'   => $row->location_label,
                'disease_id'       => (int) $row->disease_id,
                'disease_name'     => $row->disease_name,
                'severity'         => $row->severity,
                'confidence_score' => round((float) $row->confidence_score, 1),
                'fish_type'        => $row->fish_type,
                'scanned_at'       => $row->scanned_at,
            ])
            ->values();

        return $this->ok($pins);
    }

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

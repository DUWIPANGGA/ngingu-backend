<?php

namespace App\Services;

use App\Models\Disease;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Klien ML (FastAPI) untuk prediksi penyakit ikan (NFR-01.2, hard limit 30 detik).
 * Driver: "fake" (dev/test, tanpa Python) atau "http" (ML_SERVICE_URL).
 */
class MlClient
{
    /**
     * @return array{
     *   disease_slug: string, disease_name: string, confidence: float,
     *   top_predictions: array<int, array{name: string, confidence: float}>,
     *   processing_time_ms: int, model_version: string, gradcam_url: ?string
     * }
     *
     * @throws MlException
     */
    public function predict(string $scanId, array $imagePaths = []): array
    {
        return $this->driver() === 'http'
            ? $this->predictViaHttp($imagePaths)
            : $this->predictFake($scanId);
    }

    private function driver(): string
    {
        return (string) config('ngingu.ml.driver', 'fake');
    }

    private function predictViaHttp(array $imagePaths): array
    {
        $timeout = (int) config('ngingu.ml.timeout', 30);

        try {
            $response = Http::withOptions(['timeout' => $timeout])
                ->connectTimeout($timeout)
                ->post(rtrim((string) config('ngingu.ml.base_url'), '/').'/predict', [
                    'images' => $imagePaths,
                ]);
        } catch (ConnectionException $e) {
            throw new MlException('ML_TIMEOUT', 'Prediksi melewati batas 30 detik. Coba lagi.', 504, $e);
        }

        if ($response->failed()) {
            throw new MlException('ML_UNAVAILABLE', 'Layanan prediksi sedang tidak tersedia. Coba lagi nanti.', 503);
        }

        return $this->normalize($response->json() ?? []);
    }

    private function predictFake(string $scanId): array
    {
        $delayMs = (int) config('ngingu.ml.fake_delay_ms', 0);

        if ($delayMs > 0) {
            usleep($delayMs * 1000);
        }

        $labels = $this->fakeLabels();
        $primary = $labels[0];

        return [
            'disease_slug' => Str::slug($primary['name']),
            'disease_name' => $primary['name'],
            'confidence' => $primary['confidence'],
            'top_predictions' => $labels,
            'processing_time_ms' => random_int(600, 4200),
            'model_version' => (string) config('ngingu.ml.fake_model_version', 'fake-v1'),
            'gradcam_url' => null,
        ];
    }

    /**
     * @return array<int, array{name: string, confidence: float}>
     */
    private function fakeLabels(): array
    {
        $seed = (int) substr(md5((string) uniqid('', true)), 0, 6);

        $confidence = 62 + ($seed % 36);

        $names = Disease::query()->published()->inRandomOrder()->limit(3)->pluck('name')->all();

        if ($names === []) {
            $names = ['Ikan Sehat'];
        }

        $rest = 100 - $confidence;
        $labels = [['name' => $names[0], 'confidence' => (float) $confidence]];

        $remaining = array_slice($names, 1);
        if ($remaining === []) {
            $remaining = ['Tidak diketahui'];
        }

        $split = round($rest / count($remaining), 1);
        foreach ($remaining as $i => $name) {
            $value = $i === count($remaining) - 1
                ? round(100 - $confidence - ($split * (count($remaining) - 1)), 1)
                : $split;

            $labels[] = ['name' => $name, 'confidence' => max(0.1, $value)];
        }

        return $labels;
    }

    private function normalize(array $payload): array
    {
        $predictions = collect($payload['top_predictions'] ?? [])->map(fn ($row) => [
            'name' => (string) ($row['name'] ?? $row['label'] ?? 'Tidak diketahui'),
            'confidence' => round((float) ($row['confidence'] ?? 0), 1),
        ])->values()->all();

        $primary = $predictions[0] ?? ['name' => 'Tidak diketahui', 'confidence' => 0.0];

        return [
            'disease_slug' => (string) ($payload['slug'] ?? Str::slug($primary['name'])),
            'disease_name' => (string) ($payload['disease'] ?? $primary['name']),
            'confidence' => $primary['confidence'],
            'top_predictions' => $predictions,
            'processing_time_ms' => (int) ($payload['processing_time_ms'] ?? 0),
            'model_version' => (string) ($payload['model_version'] ?? 'unknown'),
            'gradcam_url' => $payload['gradcam_url'] ?? null,
        ];
    }
}

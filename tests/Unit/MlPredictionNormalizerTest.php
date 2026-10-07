<?php

namespace Tests\Unit;

use App\Services\MlException;
use App\Services\MlPredictionNormalizer;
use App\Support\ImageStorage;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Lapisan adaptasi response ML — bentuk keluaran harus stabil apa pun
 * bentuk payload dari model (FR-10, API.md §10).
 */
class MlPredictionNormalizerTest extends TestCase
{
    private MlPredictionNormalizer $normalizer;

    private string $disk;

    protected function setUp(): void
    {
        parent::setUp();

        $this->normalizer = app(MlPredictionNormalizer::class);
        $this->disk = app(ImageStorage::class)->disk();
    }

    // ── bentuk payload ────────────────────────────────────────────────

    public function test_new_payload_with_disease_object(): void
    {
        $result = $this->normalizer->normalize([
            'disease' => ['label' => 'aeromoniasis', 'name' => 'Infeksi Bakteri Aeromonas', 'slug' => 'aeromoniasis', 'severity' => 'berat'],
            'confidence' => 87.3,
            'top_predictions' => [
                ['label' => 'aeromoniasis', 'name' => 'Infeksi Bakteri Aeromonas', 'confidence' => 87.3],
                ['label' => 'healthy', 'name' => 'Ikan Sehat', 'confidence' => 8.4],
            ],
            'low_confidence_warning' => false,
            'gradcam_image' => null,
            'processing_time_ms' => 835,
            'model_version' => 'vit-v1.0.0',
        ]);

        $this->assertSame('aeromoniasis', $result['disease_slug']);
        $this->assertSame('Infeksi Bakteri Aeromonas', $result['disease_name']);
        $this->assertSame('aeromoniasis', $result['disease_label']);
        $this->assertSame('berat', $result['severity']);
        $this->assertSame(87.3, $result['confidence']);
        $this->assertSame(835, $result['processing_time_ms']);
        $this->assertSame('vit-v1.0.0', $result['model_version']);
        $this->assertFalse($result['low_confidence_warning']);
        $this->assertNull($result['gradcam_url']);
        $this->assertCount(2, $result['top_predictions']);
        $this->assertSame('Infeksi Bakteri Aeromonas', $result['top_predictions'][0]['name']);
        $this->assertSame('aeromoniasis', $result['top_predictions'][0]['label']);
    }

    public function test_legacy_payload_with_string_disease(): void
    {
        $result = $this->normalizer->normalize([
            'disease' => 'White Spot (Ich)',
            'slug' => 'white-spot-ich',
            'top_predictions' => [
                ['name' => 'White Spot (Ich)', 'confidence' => 72],
                ['name' => 'Ikan Sehat', 'confidence' => 28],
            ],
            'processing_time_ms' => 1200,
            'model_version' => 'old-v2',
        ]);

        $this->assertSame('White Spot (Ich)', $result['disease_name']);
        $this->assertSame('white-spot-ich', $result['disease_slug']);
        $this->assertSame(72.0, $result['confidence']);
        $this->assertNull($result['disease_label']);
        $this->assertNull($result['gradcam_url']);
    }

    public function test_missing_disease_falls_back_to_top_prediction(): void
    {
        $result = $this->normalizer->normalize([
            'disease' => null,
            'top_predictions' => [['label' => 'healthy', 'name' => 'Ikan Sehat', 'confidence' => 91.2]],
        ]);

        $this->assertSame('Ikan Sehat', $result['disease_name']);
        // label dipakai untuk slug ketika payload tidak menyebut slug
        $this->assertSame('healthy', $result['disease_slug']);
        $this->assertSame(91.2, $result['confidence']);
    }

    public function test_empty_payload_yields_stable_unknown_result(): void
    {
        $result = $this->normalizer->normalize([]);

        $this->assertSame('Tidak diketahui', $result['disease_name']);
        $this->assertSame('tidak-diketahui', $result['disease_slug']);
        $this->assertSame(0.0, $result['confidence']);
        $this->assertSame([], $result['top_predictions']);
        $this->assertSame('unknown', $result['model_version']);
        $this->assertSame(0, $result['processing_time_ms']);
    }

    // ── skala confidence ──────────────────────────────────────────────

    public function test_fraction_confidence_is_scaled_to_percent(): void
    {
        $result = $this->normalizer->normalize([
            'disease' => ['name' => 'A'],
            'confidence' => 0.873,
        ]);

        $this->assertSame(87.3, $result['confidence']);
    }

    public function test_confidence_falls_back_to_first_prediction(): void
    {
        $result = $this->normalizer->normalize([
            'top_predictions' => [['name' => 'A', 'confidence' => 0.42]],
        ]);

        $this->assertSame(42.0, $result['confidence']);
    }

    public function test_confidence_is_clamped_to_valid_range(): void
    {
        $this->assertSame(100.0, $this->normalizer->normalize(['confidence' => 150])['confidence']);
        $this->assertSame(0.0, $this->normalizer->normalize(['confidence' => -5])['confidence']);
    }

    // ── low_confidence_warning ───────────────────────────────────────

    public function test_low_confidence_warning_derived_from_threshold(): void
    {
        config(['ngingu.scan.low_confidence_threshold' => 60]);

        $this->assertTrue($this->normalizer->normalize(['confidence' => 45])['low_confidence_warning']);
        $this->assertFalse($this->normalizer->normalize(['confidence' => 75])['low_confidence_warning']);
    }

    public function test_low_confidence_warning_from_payload_wins(): void
    {
        $this->assertFalse($this->normalizer->normalize([
            'confidence' => 10,
            'low_confidence_warning' => false,
        ])['low_confidence_warning']);
    }

    // ── top_predictions variasi ───────────────────────────────────────

    public function test_top_predictions_accepts_alternative_keys(): void
    {
        $result = $this->normalizer->normalize([
            'top_predictions' => [
                ['class' => 'Parasit', 'probability' => 0.6],
                ['disease' => 'Jamur'],
                'String Entry',
            ],
        ]);

        $this->assertCount(3, $result['top_predictions']);
        $this->assertSame('Parasit', $result['top_predictions'][0]['name']);
        $this->assertSame(60.0, $result['top_predictions'][0]['confidence']);
        $this->assertSame('Jamur', $result['top_predictions'][1]['name']);
        $this->assertSame('String Entry', $result['top_predictions'][2]['name']);
    }

    public function test_non_array_top_predictions_is_tolerated(): void
    {
        $result = $this->normalizer->normalize(['top_predictions' => 'broken']);

        $this->assertSame([], $result['top_predictions']);
    }

    // ── GradCAM ───────────────────────────────────────────────────────

    public function test_gradcam_base64_is_stored_as_file_and_returns_url(): void
    {
        Storage::fake($this->disk);

        $result = $this->normalizer->normalize([
            'disease' => ['name' => 'A'],
            'gradcam_image' => base64_encode($this->pngBinary()),
        ], 'scan-abc');

        $this->assertNotNull($result['gradcam_url']);
        $this->assertStringContainsString('gradcams/scan-abc/', $result['gradcam_url']);
    }

    public function test_gradcam_data_uri_is_accepted(): void
    {
        Storage::fake($this->disk);

        $uri = 'data:image/png;base64,'.base64_encode($this->pngBinary());

        $result = $this->normalizer->normalize(['gradcam_image' => $uri], 'scan-abc');

        $this->assertStringContainsString('gradcams/scan-abc/', (string) $result['gradcam_url']);
    }

    public function test_gradcam_url_passes_through_when_it_fits_column(): void
    {
        $result = $this->normalizer->normalize(['gradcam_url' => 'https://cdn.example.com/gradcam.jpg']);

        $this->assertSame('https://cdn.example.com/gradcam.jpg', $result['gradcam_url']);
    }

    public function test_gradcam_url_longer_than_column_is_dropped(): void
    {
        $result = $this->normalizer->normalize(['gradcam_url' => str_repeat('a', 400)]);

        $this->assertNull($result['gradcam_url']);
    }

    public function test_invalid_gradcam_base64_degrades_to_null(): void
    {
        Storage::fake($this->disk);

        $result = $this->normalizer->normalize(['gradcam_image' => 'not-an-image!!!'], 'scan-abc');

        $this->assertNull($result['gradcam_url']);
    }

    // ── batch (API.md §10.5) ─────────────────────────────────────────

    public function test_batch_payload_uses_aggregate_block(): void
    {
        $result = $this->normalizer->normalizeBatch([
            'results' => [
                ['disease' => ['name' => 'Foto 1'], 'confidence' => 55, 'top_predictions' => [['name' => 'Foto 1', 'confidence' => 55]], 'model_version' => 'vit-v1'],
                ['disease' => ['name' => 'Foto 2'], 'confidence' => 80, 'top_predictions' => [['name' => 'Foto 2', 'confidence' => 80]], 'model_version' => 'vit-v1'],
            ],
            'aggregated' => [
                'disease' => ['label' => 'saprolegniasis', 'name' => 'Saprolegniasis', 'slug' => 'saprolegniasis', 'severity' => 'sedang'],
                'confidence' => 74.5,
                'best_photo_index' => 1,
            ],
            'processing_time_ms' => 3000,
        ], 'scan-1');

        $this->assertSame('saprolegniasis', $result['disease_slug']);
        $this->assertSame(74.5, $result['confidence']);
        $this->assertSame(3000, $result['processing_time_ms']);
        $this->assertFalse($result['low_confidence_warning']);
    }

    public function test_batch_payload_without_aggregate_falls_back_to_single(): void
    {
        $result = $this->normalizer->normalizeBatch([
            'disease' => ['name' => 'A', 'slug' => 'a'],
            'confidence' => 90,
        ]);

        $this->assertSame('a', $result['disease_slug']);
        $this->assertSame(90.0, $result['confidence']);
    }

    // ── error mapping (API.md §10.2) ─────────────────────────────────

    public function test_image_quality_low_maps_to_422_with_suggestions(): void
    {
        $e = MlPredictionNormalizer::exception([
            'code' => 'IMAGE_QUALITY_LOW',
            'message' => 'Gambar terlalu gelap.',
            'suggestions' => ['Gunakan pencahayaan yang cukup.'],
        ], 422);

        $this->assertSame('IMAGE_QUALITY_LOW', $e->errorCode);
        $this->assertSame(422, $e->getCode());
        $this->assertStringContainsString('Gambar terlalu gelap.', $e->getMessage());
        $this->assertStringContainsString('pencahayaan', $e->getMessage());
    }

    public function test_model_not_loaded_maps_to_503(): void
    {
        $e = MlPredictionNormalizer::exception(['code' => 'MODEL_NOT_LOADED', 'message' => 'Belum siap'], 503);

        $this->assertSame('ML_UNAVAILABLE', $e->errorCode);
        $this->assertSame(503, $e->getCode());
    }

    public function test_inference_timeout_maps_to_504(): void
    {
        $e = MlPredictionNormalizer::exception(['code' => 'INFERENCE_TIMEOUT'], 504);

        $this->assertSame('ML_TIMEOUT', $e->errorCode);
        $this->assertSame(504, $e->getCode());
        $this->assertInstanceOf(MlException::class, $e);
    }

    public function test_unsupported_media_type_maps_to_400(): void
    {
        $e = MlPredictionNormalizer::exception(['code' => 'UNSUPPORTED_MEDIA_TYPE'], 400);

        $this->assertSame('UNSUPPORTED_MEDIA_TYPE', $e->errorCode);
        $this->assertSame(400, $e->getCode());
    }

    public function test_unknown_error_keeps_service_status(): void
    {
        $e = MlPredictionNormalizer::exception(['code' => 'WEIRD', 'message' => 'Ups'], 418);

        $this->assertSame('WEIRD', $e->errorCode);
        $this->assertSame(418, $e->getCode());
    }

    public function test_empty_detail_falls_back_to_generic_unavailable(): void
    {
        $e = MlPredictionNormalizer::exception([], 502);

        $this->assertSame('ML_UNAVAILABLE', $e->errorCode);
        $this->assertSame(502, $e->getCode());
        $this->assertNotSame('', $e->getMessage());
    }

    // ── helpers ───────────────────────────────────────────────────────

    private function pngBinary(): string
    {
        $image = imagecreatetruecolor(4, 4);
        imagefilledrectangle($image, 0, 0, 3, 3, imagecolorallocate($image, 255, 0, 0));

        ob_start();
        imagepng($image);
        imagedestroy($image);

        return (string) ob_get_clean();
    }
}

<?php

namespace Tests\Feature\Api;

use App\Models\Disease;
use App\Models\FishScan;
use App\Models\ScanResult;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ScanLocationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
    }

    public function test_create_scan_stores_location_coordinates(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post('/api/scans', [
            'images' => [UploadedFile::fake()->image('ikan.jpg', 640, 480)],
            'fish_type' => 'Nila',
            'location_lat' => -6.3275,
            'location_lng' => 108.3250,
        ]);

        $response->assertStatus(202);

        $scan = FishScan::find($response->json('data.scan_id'));

        $this->assertNotNull($scan);
        $this->assertEqualsWithDelta(-6.3275, (float) $scan->location_lat, 0.0001);
        $this->assertEqualsWithDelta(108.3250, (float) $scan->location_lng, 0.0001);
    }

    public function test_create_scan_rejects_out_of_range_location(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->postJson('/api/scans', [
            'images' => [UploadedFile::fake()->image('ikan.jpg', 640, 480)],
            'location_lat' => 999,
            'location_lng' => 108.3250,
        ])->assertStatus(422)
            ->assertJsonStructure(['errors' => ['location_lat']]);
    }

    public function test_heatmap_uses_scan_coordinates_without_pond(): void
    {
        $user = User::factory()->create(['province' => 'Jawa Barat']);
        $disease = Disease::factory()->create(['name' => 'Aeromoniasis', 'slug' => 'aeromoniasis']);

        $scan = FishScan::factory()->create([
            'user_id' => $user->id,
            'pond_id' => null,
            'status' => 'done',
            'location_lat' => -6.3275,
            'location_lng' => 108.3250,
        ]);

        ScanResult::create([
            'scan_id' => $scan->id,
            'disease_id' => $disease->id,
            'confidence_score' => 70,
            'top_predictions' => [],
            'low_confidence_warning' => false,
            'model_version' => 'fake-v1',
        ]);

        $this->getJson('/api/map/heatmap?days=30')
            ->assertOk()
            ->assertJsonPath('data.0.province', 'Jawa Barat')
            ->assertJsonPath('data.0.cases', 1)
            ->assertJsonPath('data.0.top_disease', 'Aeromoniasis')
            ->assertJsonPath('data.0.lat', -6.3275)
            ->assertJsonPath('data.0.lng', 108.325);
    }
}

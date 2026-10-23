<?php

namespace Tests\Feature\Api;

use App\Models\Disease;
use App\Models\FishScan;
use App\Models\ScanResult;
use App\Models\User;
use App\Models\UserPond;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Test endpoint GET /api/map/pins (FR-07.3 — GIS per-scan).
 */
class MapPinsTest extends TestCase
{
    use RefreshDatabase;

    private function createScanWithPond(User $user, Disease $disease, UserPond $pond): void
    {
        $scan = FishScan::factory()->create([
            'user_id'   => $user->id,
            'pond_id'   => $pond->id,
            'status'    => 'done',
            'fish_type' => 'Lele',
        ]);
        ScanResult::create([
            'scan_id'                => $scan->id,
            'disease_id'             => $disease->id,
            'confidence_score'       => 88.5,
            'top_predictions'        => [],
            'low_confidence_warning' => false,
            'model_version'          => 'v1',
        ]);
    }

    private function createScanWithoutPond(User $user, Disease $disease): void
    {
        $scan = FishScan::factory()->create([
            'user_id' => $user->id,
            'pond_id' => null,
            'status'  => 'done',
        ]);
        ScanResult::create([
            'scan_id'                => $scan->id,
            'disease_id'             => $disease->id,
            'confidence_score'       => 70,
            'top_predictions'        => [],
            'low_confidence_warning' => false,
            'model_version'          => 'v1',
        ]);
    }

    private function makePond(User $user, float $lat, float $lng): UserPond
    {
        return UserPond::create([
            'user_id'        => $user->id,
            'name'           => 'Tambak Test',
            'location_lat'   => $lat,
            'location_lng'   => $lng,
            'location_label' => 'Indramayu, Jawa Barat',
            'health_score'   => 90,
        ]);
    }

    public function test_pins_is_public_and_returns_individual_scan_points(): void
    {
        $user    = User::factory()->create();
        $disease = Disease::factory()->create(['name' => 'White Spot', 'severity' => 'berat', 'slug' => 'white-spot-x']);
        $pond    = $this->makePond($user, -6.3275, 108.325);

        $this->createScanWithPond($user, $disease, $pond);
        $this->createScanWithPond($user, $disease, $pond);

        $this->getJson('/api/map/pins?days=30')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonStructure(['data' => [['id','lat','lng','pond_name','location_label','disease_id','disease_name','severity','confidence_score','fish_type','scanned_at']]])
            ->assertJsonPath('data.0.disease_id', $disease->id)
            ->assertJsonPath('data.0.disease_name', 'White Spot')
            ->assertJsonPath('data.0.severity', 'berat')
            ->assertJsonPath('data.0.lat', -6.3275)
            ->assertJsonPath('data.0.lng', 108.325);
    }

    public function test_scans_without_pond_coords_are_excluded(): void
    {
        $user    = User::factory()->create();
        $disease = Disease::factory()->create(['slug' => 'cols-x']);

        $this->createScanWithoutPond($user, $disease);

        $pond = $this->makePond($user, -6.9, 107.6);
        $this->createScanWithPond($user, $disease, $pond);

        $this->getJson('/api/map/pins?days=30')
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_failed_scans_are_excluded(): void
    {
        $user    = User::factory()->create();
        $pond    = $this->makePond($user, -6.9, 107.6);
        $disease = Disease::factory()->create(['slug' => 'motile-x']);

        FishScan::factory()->failed()->create(['user_id' => $user->id, 'pond_id' => $pond->id]);

        $this->getJson('/api/map/pins?days=30')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_pins_respects_days_filter(): void
    {
        $user    = User::factory()->create();
        $pond    = $this->makePond($user, -6.3275, 108.325);
        $disease = Disease::factory()->create(['slug' => 'ich-x']);

        $scan = FishScan::factory()->create(['user_id' => $user->id, 'pond_id' => $pond->id, 'status' => 'done']);
        ScanResult::create(['scan_id' => $scan->id, 'disease_id' => $disease->id, 'confidence_score' => 80, 'top_predictions' => [], 'low_confidence_warning' => false, 'model_version' => 'v1']);
        FishScan::whereKey($scan->id)->toBase()->update(['created_at' => now()->subDays(60)]);

        $this->getJson('/api/map/pins?days=30')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/map/pins?days=90')->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_pins_filters_by_disease_id(): void
    {
        $user   = User::factory()->create();
        $pond   = $this->makePond($user, -7.0, 108.0);
        $ich    = Disease::factory()->create(['slug' => 'ich-f']);
        $velvet = Disease::factory()->create(['slug' => 'velvet-f']);

        $this->createScanWithPond($user, $ich, $pond);
        $this->createScanWithPond($user, $velvet, $pond);

        $this->getJson("/api/map/pins?disease_id={$ich->id}")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.disease_name', $ich->name);

        $this->getJson('/api/map/pins?disease_id=999999')->assertStatus(422);
    }
}

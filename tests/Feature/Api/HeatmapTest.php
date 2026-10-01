<?php

namespace Tests\Feature\Api;

use App\Models\Disease;
use App\Models\FishScan;
use App\Models\ScanResult;
use App\Models\User;
use App\Models\UserPond;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HeatmapTest extends TestCase
{
    use RefreshDatabase;

    private function caseWithPond(User $user, Disease $disease, ?UserPond $pond): void
    {
        $scan = FishScan::factory()->create([
            'user_id' => $user->id,
            'pond_id' => $pond?->id,
            'status' => 'done',
        ]);

        ScanResult::create([
            'scan_id' => $scan->id,
            'disease_id' => $disease->id,
            'confidence_score' => 90,
            'top_predictions' => [],
            'low_confidence_warning' => false,
            'model_version' => 'fake-v1',
        ]);
    }

    public function test_heatmap_is_public_and_groups_by_province(): void
    {
        $jabar = User::factory()->create(['province' => 'Jawa Barat']);
        $jateng = User::factory()->create(['province' => 'Jawa Tengah']);

        $ich = Disease::factory()->create(['name' => 'White Spot (Ich)', 'slug' => 'white-spot-ich']);
        $columnaris = Disease::factory()->create(['name' => 'Columnaris', 'slug' => 'columnaris']);

        $pondJabar = UserPond::create([
            'user_id' => $jabar->id,
            'name' => 'Kolam Jabar',
            'location_lat' => -6.9,
            'location_lng' => 107.6,
            'health_score' => 100,
        ]);

        $this->caseWithPond($jabar, $ich, $pondJabar);
        $this->caseWithPond($jabar, $ich, $pondJabar);
        $this->caseWithPond($jabar, $columnaris, $pondJabar);
        $this->caseWithPond($jateng, $ich, null);

        $response = $this->getJson('/api/map/heatmap?days=30');

        $response->assertOk()
            ->assertJsonPath('data.0.province', 'Jawa Barat')
            ->assertJsonPath('data.0.cases', 3)
            ->assertJsonPath('data.0.top_disease', 'White Spot (Ich)')
            ->assertJsonPath('data.0.lat', -6.9)
            ->assertJsonPath('data.0.lng', 107.6)
            ->assertJsonPath('data.1.province', 'Jawa Tengah')
            ->assertJsonPath('data.1.cases', 1)
            ->assertJsonPath('data.1.lat', null)
            ->assertJsonStructure(['data' => [['province', 'lat', 'lng', 'cases', 'top_disease']]]);
    }

    public function test_heatmap_filters_by_province_and_disease(): void
    {
        $jabar = User::factory()->create(['province' => 'Jawa Barat']);
        $sumatra = User::factory()->create(['province' => 'Sumatera Utara']);

        $ich = Disease::factory()->create(['name' => 'White Spot (Ich)', 'slug' => 'white-spot-ich']);
        $velvet = Disease::factory()->create(['name' => 'Velvet', 'slug' => 'velvet']);

        $this->caseWithPond($jabar, $ich, null);
        $this->caseWithPond($sumatra, $velvet, null);

        $this->getJson('/api/map/heatmap?province=Jawa%20Barat')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.province', 'Jawa Barat');

        $this->getJson("/api/map/heatmap?disease_id={$ich->id}")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.province', 'Jawa Barat');

        $this->getJson('/api/map/heatmap?days=0')->assertStatus(422);
        $this->getJson('/api/map/heatmap?disease_id=999999')->assertStatus(422);
    }

    public function test_heatmap_excludes_old_and_failed_scans(): void
    {
        $user = User::factory()->create(['province' => 'Bali']);
        $ich = Disease::factory()->create(['slug' => 'ich']);

        $old = FishScan::factory()->create(['user_id' => $user->id, 'status' => 'done']);
        ScanResult::create([
            'scan_id' => $old->id,
            'disease_id' => $ich->id,
            'confidence_score' => 80,
            'top_predictions' => [],
            'low_confidence_warning' => false,
            'model_version' => 'fake-v1',
        ]);
        FishScan::whereKey($old->id)->toBase()->update(['created_at' => now()->subDays(60)]);

        $failed = FishScan::factory()->failed()->create(['user_id' => $user->id]);

        $this->getJson('/api/map/heatmap?days=30')
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $this->getJson('/api/map/heatmap?days=90')
            ->assertOk()
            ->assertJsonPath('data.0.province', 'Bali')
            ->assertJsonPath('data.0.cases', 1);
    }
}

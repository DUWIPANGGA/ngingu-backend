<?php

namespace Tests\Feature\Admin;

use App\Models\MlDataset;
use App\Models\MlModel;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

class ModelAdminTest extends AdminTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    public function test_upload_model_version_stores_file_and_records_audit(): void
    {
        $admin = $this->actingAsAdmin();

        $this->post(route('admin.models.store'), [
            'version' => 'v1.0.0',
            'model_file' => UploadedFile::fake()->create('model_v1.onnx', 512),
            'accuracy_pct' => 92.5,
            'disease_labels' => "White Spot (Ich)\nColumnaris",
        ])->assertRedirect(route('admin.models.index'));

        $model = MlModel::where('version', 'v1.0.0')->firstOrFail();

        $this->assertFalse($model->is_active);
        $this->assertSame(92.5, $model->accuracy_pct);
        $this->assertSame(['White Spot (Ich)', 'Columnaris'], $model->disease_labels);
        $this->assertSame($admin->id, $model->uploaded_by);
        Storage::disk('local')->assertExists($model->s3_path);

        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $admin->id,
            'action' => 'model.uploaded',
            'model_id' => $model->id,
        ]);
    }

    public function test_upload_rejects_duplicate_version_and_bad_extension(): void
    {
        MlModel::create(['version' => 'v1.0.0', 'filename' => 'x.onnx', 'is_active' => false]);
        $this->actingAsAdmin();

        $this->post(route('admin.models.store'), [
            'version' => 'v1.0.0',
            'model_file' => UploadedFile::fake()->create('model.onnx', 100),
        ])->assertSessionHasErrors('version');

        $this->post(route('admin.models.store'), [
            'version' => 'v2.0.0',
            'model_file' => UploadedFile::fake()->create('malware.exe', 100),
        ])->assertSessionHasErrors('model_file');
    }

    public function test_activate_switches_active_version_exclusively(): void
    {
        $admin = $this->createAdmin();
        $v1 = MlModel::create(['version' => 'v1', 'filename' => 'a.onnx', 'is_active' => true, 'activated_at' => now()->subDay()]);
        $v2 = MlModel::create(['version' => 'v2', 'filename' => 'b.onnx', 'is_active' => false]);

        $this->actingAs($admin)->post(route('admin.models.activate', $v2))->assertRedirect();

        $this->assertFalse($v1->fresh()->is_active);
        $this->assertTrue($v2->fresh()->is_active);
        $this->assertNotNull($v2->fresh()->activated_at);

        $this->assertDatabaseHas('audit_logs', ['action' => 'model.activated', 'model_id' => $v2->id]);

        $this->actingAs($admin)->post(route('admin.models.activate', $v2))->assertSessionHas('info');
    }

    public function test_rollback_restores_previous_version(): void
    {
        $admin = $this->createAdmin();
        $v1 = MlModel::create(['version' => 'v1', 'filename' => 'a.onnx', 'is_active' => true, 'activated_at' => now()->subDay()]);
        MlModel::create(['version' => 'v2', 'filename' => 'b.onnx', 'is_active' => false]);

        $v2 = MlModel::where('version', 'v2')->firstOrFail();
        $this->actingAs($admin)->post(route('admin.models.activate', $v2));

        $this->actingAs($admin)->post(route('admin.models.rollback'))->assertRedirect();

        $this->assertTrue($v1->fresh()->is_active);
        $this->assertFalse($v2->fresh()->is_active);

        $this->assertDatabaseHas('audit_logs', ['action' => 'model.rollback', 'model_id' => $v1->id]);
    }

    public function test_rollback_without_previous_version_shows_info(): void
    {
        $admin = $this->createAdmin();
        MlModel::create(['version' => 'v1', 'filename' => 'a.onnx', 'is_active' => true, 'activated_at' => now()]);

        $this->actingAs($admin)->post(route('admin.models.rollback'))->assertSessionHas('info');
    }

    public function test_active_model_cannot_be_deleted_and_inactive_can(): void
    {
        $admin = $this->createAdmin();
        $active = MlModel::create(['version' => 'v1', 'filename' => 'a.onnx', 'is_active' => true, 's3_path' => 'ml-models/a.onnx']);
        $inactive = MlModel::create(['version' => 'v2', 'filename' => 'b.onnx', 'is_active' => false, 's3_path' => 'ml-models/b.onnx']);
        Storage::disk('local')->put('ml-models/b.onnx', 'weights');

        $this->actingAs($admin)->delete(route('admin.models.destroy', $active))
            ->assertSessionHasErrors('model');

        $this->actingAs($admin)->delete(route('admin.models.destroy', $inactive))
            ->assertRedirect();

        $this->assertNotNull($active->fresh());
        $this->assertNull($inactive->fresh());
        Storage::disk('local')->assertMissing('ml-models/b.onnx');
        $this->assertDatabaseHas('audit_logs', ['action' => 'model.deleted']);
    }

    public function test_upload_and_delete_dataset_images(): void
    {
        $admin = $this->actingAsAdmin();

        $this->post(route('admin.datasets.store'), [
            'images' => [
                UploadedFile::fake()->image('sample1.png', 100, 100),
                UploadedFile::fake()->image('sample2.jpg', 100, 100),
            ],
            'label' => 'white_spot',
        ])->assertRedirect();

        $this->assertSame(2, MlDataset::count());

        foreach (MlDataset::all() as $dataset) {
            Storage::disk('local')->assertExists($dataset->path);
            $this->assertSame('white_spot', $dataset->label);
            $this->assertSame($admin->id, $dataset->uploaded_by);
        }

        $this->assertDatabaseHas('audit_logs', ['action' => 'dataset.uploaded']);

        $dataset = MlDataset::firstOrFail();
        $this->delete(route('admin.datasets.destroy', $dataset))->assertRedirect();

        $this->assertNull($dataset->fresh());
        Storage::disk('local')->assertMissing($dataset->path);
        $this->assertDatabaseHas('audit_logs', ['action' => 'dataset.deleted']);
    }

    public function test_dataset_upload_requires_at_least_one_image(): void
    {
        $this->actingAsAdmin();

        $this->post(route('admin.datasets.store'), ['label' => 'x'])
            ->assertSessionHasErrors('images');
    }
}

<?php

namespace Tests\Feature;

use App\Models\AppInfo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LandingPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_landing_page_renders(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertSee('Deteksi Penyakit Ikan', false)
            ->assertSee('css/landing.css')
            ->assertSee('js/landing.js');
    }

    public function test_landing_page_shows_apk_link_when_available(): void
    {
        AppInfo::query()->create([
            'app_name' => 'Ngingu',
            'developer' => 'Tim Ngingu',
            'apk_path' => 'https://cdn.ngingu.id/ngingu.apk',
            'apk_version' => '1.2.0',
        ]);

        $this->get('/')
            ->assertOk()
            ->assertSee('https://cdn.ngingu.id/ngingu.apk')
            ->assertSee('1.2.0');
    }
}

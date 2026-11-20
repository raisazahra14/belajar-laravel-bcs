<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ApiDocumentationTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_api_docs_portal_renders_swagger_ui_with_local_vendored_assets(): void
    {
        $response = $this->get('/docs/api');

        $response->assertOk()
            ->assertSee('LogistikKu', false)
            ->assertSee('id="swagger-ui"', false)
            ->assertSee('SwaggerUIBundle', false)
            ->assertSee('/docs/openapi.yaml', false)
            ->assertSee('/vendor/swagger-ui/swagger-ui.css', false)
            ->assertSee('/vendor/swagger-ui/swagger-ui-bundle.js', false)
            ->assertSee('/vendor/swagger-ui/swagger-ui-standalone-preset.js', false);
    }

    public function test_public_openapi_yaml_endpoint_serves_valid_specification_covering_all_v1_routes(): void
    {
        $response = $this->get('/docs/openapi.yaml');

        $response->assertOk()
            ->assertHeader('X-API-Version', 'v1.0')
            ->assertHeader('X-Content-Type-Options', 'nosniff');

        $contentType = (string) $response->headers->get('Content-Type');
        $this->assertStringStartsWith('application/yaml', $contentType);

        $yaml = (string) $response->getContent();

        $expectedFragments = [
            'openapi: 3.0.3',
            '/api/v1/status:',
            '/api/v1/auth/login:',
            '/api/v1/auth/me:',
            '/api/v1/auth/logout:',
            '/api/v1/barang:',
            '/api/v1/barang/{barang}:',
            '/api/v1/barang/{barang}/check-stock:',
            '/api/v1/stok/adjust:',
            '/api/v1/stok/transfer:',
            '/api/v1/purchase-orders:',
            '/api/v1/purchase-orders/{purchaseOrder}:',
            '/api/v1/purchase-orders/{purchaseOrder}/receive:',
        ];

        foreach ($expectedFragments as $fragment) {
            $this->assertStringContainsString($fragment, $yaml);
        }
    }

    public function test_local_swagger_ui_vendor_assets_exist_in_public_directory(): void
    {
        $assets = [
            public_path('vendor/swagger-ui/swagger-ui.css'),
            public_path('vendor/swagger-ui/swagger-ui-bundle.js'),
            public_path('vendor/swagger-ui/swagger-ui-standalone-preset.js'),
        ];

        foreach ($assets as $assetPath) {
            $this->assertFileExists($assetPath);
            $this->assertGreaterThan(0, filesize($assetPath));
        }
    }

    public function test_api_status_endpoint_exposes_hateoas_documentation_links(): void
    {
        $response = $this->getJson('/api/v1/status');

        $response->assertOk()
            ->assertHeader('X-API-Version', 'v1.0')
            ->assertJsonStructure([
                'data' => [
                    'links' => ['self', 'docs', 'openapi'],
                ],
            ]);

        $this->assertStringEndsWith('/api/v1/status', (string) $response->json('data.links.self'));
        $this->assertStringEndsWith('/docs/api', (string) $response->json('data.links.docs'));
        $this->assertStringEndsWith('/docs/openapi.yaml', (string) $response->json('data.links.openapi'));
    }

    public function test_authenticated_sidebar_includes_api_documentation_menu_link(): void
    {
        $staff = User::factory()->create(['role' => 'staff']);

        $response = $this->actingAs($staff)->get('/barang');

        $response->assertOk()
            ->assertSee(route('docs.api'), false)
            ->assertSee('Dokumentasi API');
    }

    public function test_week4_markdown_deliverables_exist_and_reference_openapi_artifacts(): void
    {
        $openApiPath = base_path('docs/openapi.yaml');
        $guidePath = base_path('docs/api_integration_guide.md');
        $reportPath = base_path('docs/LAPORAN_KEGIATAN_PERIODE_4_MAGANGHUB.md');

        $this->assertFileExists($openApiPath);
        $this->assertFileExists($guidePath);
        $this->assertFileExists($reportPath);

        $guideContent = (string) file_get_contents($guidePath);
        $this->assertStringContainsString('cURL', $guideContent);
        $this->assertStringContainsString('requests', $guideContent);
        $this->assertStringContainsString('fetch', $guideContent);
        $this->assertStringContainsString('axios', $guideContent);
        $this->assertStringContainsString('/docs/api', $guideContent);

        $reportContent = (string) file_get_contents($reportPath);
        $this->assertStringContainsString('openapi.yaml', $reportContent);
        $this->assertStringContainsString('/docs/api', $reportContent);
        $this->assertStringContainsString('api_integration_guide.md', $reportContent);
    }
}

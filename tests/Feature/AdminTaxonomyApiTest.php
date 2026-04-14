<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminTaxonomyApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('cinema.media_root', storage_path('framework/testing-media-root'));
        config()->set('cinema.media_public_base_url', 'http://localhost:3000/media');
    }

    public function test_admin_can_create_actor_with_imdb_image_and_receive_image_in_movie_options(): void
    {
        $admin = $this->createAdminUser();

        Http::fake([
            'https://v2.sg.media-imdb.com/*' => Http::response([
                'd' => [
                    [
                        'id' => 'nm0000138',
                        'l' => 'Leonardo DiCaprio',
                        'i' => [
                            'imageUrl' => 'https://m.media-amazon.com/images/M/MV5B-test.jpg',
                        ],
                    ],
                ],
            ]),
            'https://m.media-amazon.com/*' => Http::response('fake-image-binary', 200, [
                'Content-Type' => 'image/jpeg',
            ]),
        ]);

        Sanctum::actingAs($admin);

        $createResponse = $this->postJson('/api/admin/taxonomies/actors', [
            'name' => 'لئوناردو دی‌کاپریو',
            'slug' => 'leonardo-dicaprio',
            'description' => 'بازیگر آمریکایی',
            'imdb_url' => 'https://www.imdb.com/name/nm0000138/',
            'is_active' => true,
        ]);

        $createResponse
            ->assertCreated()
            ->assertJsonPath('data.item.slug', 'leonardo-dicaprio')
            ->assertJsonPath('data.item.imdb_url', 'https://www.imdb.com/name/nm0000138/');

        $this->assertDatabaseHas('actors', [
            'slug' => 'leonardo-dicaprio',
        ]);

        $imagePath = $createResponse->json('data.item.image_path');
        $imageUrl = $createResponse->json('data.item.image_url');

        $this->assertNotNull($imagePath);
        $this->assertFileExists($imagePath);
        $this->assertSame(
            'http://localhost:3000/media/taxonomies/actors/leonardo-dicaprio/portrait.jpg',
            $imageUrl
        );

        $optionsResponse = $this->getJson('/api/admin/movies/options');

        $optionsResponse
            ->assertOk()
            ->assertJsonPath('data.taxonomies.actors.0.slug', 'leonardo-dicaprio')
            ->assertJsonPath(
                'data.taxonomies.actors.0.image_url',
                'http://localhost:3000/media/taxonomies/actors/leonardo-dicaprio/portrait.jpg'
            );
    }

    private function createAdminUser(): User
    {
        $this->seed(RolePermissionSeeder::class);

        $user = User::factory()->create([
            'status' => 'active',
        ]);

        $adminRole = Role::query()->where('slug', 'admin')->firstOrFail();
        $user->roles()->sync([$adminRole->id]);

        return $user;
    }
}

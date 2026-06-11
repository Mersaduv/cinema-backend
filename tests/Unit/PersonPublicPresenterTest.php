<?php

namespace Tests\Unit;

use App\Models\Person;
use App\Services\Cinema\PersonPublicPresenter;
use Tests\TestCase;

class PersonPublicPresenterTest extends TestCase
{
    public function test_profile_fields_prefer_person_metadata_image(): void
    {
        config(['cinema_images.public_base_url' => '']);

        $person = new Person([
            'name' => 'Sam Malone',
            'slug' => 'sam-malone',
            'tmdb_person_id' => 4896770,
            'profile_url' => 'https://image.tmdb.org/t/p/w500/old.jpg',
            'metadata' => [
                'images' => [
                    'profile' => [
                        'original' => '/images/2026/person-profile.jpg',
                        'blur' => '/images/2026/person-profile.jpg?width=20&quality=10&sharpen=0',
                        'src' => '/images/2026/person-profile.jpg?width=384&quality=65&sharpen=60',
                        'srcset_string' => '/images/2026/person-profile.jpg?width=384&quality=65&sharpen=60 384w',
                    ],
                ],
            ],
        ]);

        $presenter = app(PersonPublicPresenter::class);
        $profile = $presenter->profileFields($person);

        $this->assertSame('/images/2026/person-profile.jpg', $profile['profile_url']);
        $this->assertSame('/images/2026/person-profile.jpg', $profile['profile_image']['original']);
    }

    public function test_profile_fields_fallback_to_related_metadata_actor(): void
    {
        config(['cinema_images.public_base_url' => '']);

        $person = new Person([
            'name' => 'Sam Malone',
            'slug' => 'sam-malone',
            'tmdb_person_id' => 4896770,
            'profile_url' => 'https://image.tmdb.org/t/p/w500/old.jpg',
            'metadata' => [],
        ]);

        $metadataActors = [[
            'name' => 'Sam Malone',
            'tmdb_person_id' => 4896770,
            'profile_url' => '/images/2026/from-series-cast.jpg',
            'profile_image' => [
                'original' => '/images/2026/from-series-cast.jpg',
                'blur' => '/images/2026/from-series-cast.jpg?width=20&quality=10&sharpen=0',
                'src' => '/images/2026/from-series-cast.jpg?width=384&quality=65&sharpen=60',
                'srcset_string' => '/images/2026/from-series-cast.jpg?width=384&quality=65&sharpen=60 384w',
            ],
        ]];

        $presenter = app(PersonPublicPresenter::class);
        $profile = $presenter->profileFields($person, $metadataActors);

        $this->assertSame('/images/2026/from-series-cast.jpg', $profile['profile_url']);
        $this->assertSame('/images/2026/from-series-cast.jpg', $profile['profile_image']['original']);
    }
}

<?php

namespace App\GraphQL\Types;

use App\Models\Person as PersonModel;
use App\Services\Cinema\PersonPublicPresenter;

final class Person
{
    public function __construct(
        private readonly PersonPublicPresenter $personPublicPresenter,
    ) {
    }

    public function profileUrl(PersonModel $person): ?string
    {
        return $this->personPublicPresenter->profileFields($person)['profile_url'];
    }

    /**
     * @return ?array<string, mixed>
     */
    public function profileImage(PersonModel $person): ?array
    {
        return $this->personPublicPresenter->profileFields($person)['profile_image'];
    }
}

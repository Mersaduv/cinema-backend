<?php

namespace App\GraphQL\Types;

use App\Models\Person as PersonModel;

final class Person
{
    public function profileUrl(PersonModel $person): ?string
    {
        return $person->getProfileImageUrl();
    }
}

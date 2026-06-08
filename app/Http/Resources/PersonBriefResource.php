<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PersonBriefResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'tmdb_person_id' => $this->tmdb_person_id,
            'name' => $this->name,
            'slug' => $this->slug,
            'profile_url' => $this->getProfileImageUrl(),
            'known_for_department' => $this->known_for_department,
        ];
    }
}

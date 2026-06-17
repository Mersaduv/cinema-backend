<?php

namespace App\Services\Cinema;

use App\Models\ContentRequest;
use App\Models\User;

class ContentRequestPresentationService
{
  /**
   * @return array<string, mixed>
   */
  public function transform(ContentRequest $request, ?User $viewer = null): array
  {
    return [
      'id' => $request->id,
      'title' => $request->title,
      'genre_slug' => $request->genre_slug,
      'genre_name' => $request->genre_name,
      'description' => $request->description,
      'status' => $request->status,
      'admin_note' => $request->admin_note,
      'created_at' => $request->created_at?->toIso8601String(),
      'updated_at' => $request->updated_at?->toIso8601String(),
      'moderated_at' => $request->moderated_at?->toIso8601String(),
      'is_own' => $viewer instanceof User && (int) $request->user_id === (int) $viewer->id,
    ];
  }

  /**
   * @return array<string, mixed>
   */
  public function adminTransform(ContentRequest $request): array
  {
    $request->loadMissing('user');

    return [
      ...$this->transform($request),
      'user' => $request->user ? [
        'id' => $request->user->id,
        'name' => $request->user->name,
        'email' => $request->user->email,
      ] : null,
    ];
  }
}

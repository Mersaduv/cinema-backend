<?php

namespace App\Services\Cinema;

use App\Models\ContentRequest;
use App\Models\Viewer;

class ContentRequestPresentationService
{
  /**
   * @return array<string, mixed>
   */
  public function transform(ContentRequest $request, ?Viewer $viewer = null): array
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
      'is_own' => $viewer instanceof Viewer && (int) $request->viewer_id === (int) $viewer->id,
    ];
  }

  /**
   * @return array<string, mixed>
   */
  public function adminTransform(ContentRequest $request): array
  {
    $request->loadMissing('viewer');

    return [
      ...$this->transform($request),
      'viewer' => $request->viewer ? [
        'id' => $request->viewer->id,
        'name' => $request->viewer->name,
        'email' => $request->viewer->email,
      ] : null,
    ];
  }
}

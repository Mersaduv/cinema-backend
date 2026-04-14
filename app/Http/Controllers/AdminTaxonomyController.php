<?php

namespace App\Http\Controllers;

use App\Models\Actor;
use App\Models\Country;
use App\Models\Genre;
use App\Models\Language;
use App\Models\Tag;
use App\Services\Cinema\ActorImageSyncService;
use App\Services\Cinema\MediaPathService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class AdminTaxonomyController extends Controller
{
    public function __construct(
        private readonly ActorImageSyncService $actorImageSyncService,
        private readonly MediaPathService $mediaPathService,
    ) {
    }

    /**
     * @return array<string, array{model: class-string<Model>, singular: string, plural: string}>
     */
    private function configs(): array
    {
        return [
            'genres' => [
                'model' => Genre::class,
                'singular' => 'ژانر',
                'plural' => 'ژانرها',
            ],
            'countries' => [
                'model' => Country::class,
                'singular' => 'کشور',
                'plural' => 'کشورها',
            ],
            'languages' => [
                'model' => Language::class,
                'singular' => 'زبان',
                'plural' => 'زبان‌ها',
            ],
            'tags' => [
                'model' => Tag::class,
                'singular' => 'تگ',
                'plural' => 'تگ‌ها',
            ],
            'actors' => [
                'model' => Actor::class,
                'singular' => 'بازیگر',
                'plural' => 'بازیگران',
            ],
        ];
    }

    public function index(Request $request, string $type): JsonResponse
    {
        $config = $this->resolveConfig($type);
        $modelClass = $config['model'];
        $search = trim((string) $request->string('search'));
        $status = (string) $request->string('status');
        $perPage = min(max((int) $request->integer('per_page', 12), 1), 50);

        $query = $modelClass::query()
            ->withCount('movies')
            ->when($search !== '', function ($query) use ($search) {
                $query->where(function ($innerQuery) use ($search) {
                    $innerQuery
                        ->where('name', 'like', "%{$search}%")
                        ->orWhere('slug', 'like', "%{$search}%")
                        ->orWhere('description', 'like', "%{$search}%");
                });
            })
            ->when(in_array($status, ['active', 'inactive'], true), function ($query) use ($status) {
                $query->where('is_active', $status === 'active');
            })
            ->orderBy('sort_order')
            ->orderBy('name');

        $items = $query->paginate($perPage);

        return response()->json([
            'success' => true,
            'data' => [
                'meta' => [
                    'type' => $type,
                    'singular_label' => $config['singular'],
                    'plural_label' => $config['plural'],
                ],
                'items' => collect($items->items())
                    ->map(fn (Model $item) => $this->transformItem($item))
                    ->values(),
                'summary' => [
                    'total' => $modelClass::query()->count(),
                    'active' => $modelClass::query()->where('is_active', true)->count(),
                    'inactive' => $modelClass::query()->where('is_active', false)->count(),
                    'attached_movies' => $modelClass::query()->has('movies')->count(),
                ],
                'statuses' => [
                    ['value' => 'active', 'label' => 'فعال'],
                    ['value' => 'inactive', 'label' => 'غیرفعال'],
                ],
                'pagination' => [
                    'current_page' => $items->currentPage(),
                    'last_page' => $items->lastPage(),
                    'per_page' => $items->perPage(),
                    'total' => $items->total(),
                ],
            ],
        ]);
    }

    public function store(Request $request, string $type): JsonResponse
    {
        $config = $this->resolveConfig($type);
        $modelClass = $config['model'];
        $validated = $this->validator($request->all(), $modelClass)->validate();

        try {
            /** @var Model $item */
            $item = DB::transaction(function () use ($modelClass, $validated) {
                $attributes = [
                    'name' => $validated['name'],
                    'slug' => $this->resolveUniqueSlug($modelClass, (string) ($validated['slug'] ?? $validated['name'])),
                    'description' => $validated['description'] ?? null,
                    'is_active' => (bool) ($validated['is_active'] ?? true),
                    'sort_order' => (int) ($validated['sort_order'] ?? 0),
                ];

                if ($modelClass === Actor::class) {
                    $attributes['imdb_url'] = $validated['imdb_url'] ?? null;
                }

                /** @var Model $createdItem */
                $createdItem = $modelClass::query()->create($attributes);

                if ($createdItem instanceof Actor && filled($validated['imdb_url'] ?? null)) {
                    $createdItem = $this->actorImageSyncService->syncFromImdb($createdItem);
                }

                return $createdItem;
            });
        } catch (InvalidArgumentException $exception) {
            return response()->json([
                'success' => false,
                'message' => $exception->getMessage(),
                'errors' => [
                    'imdb_url' => [$exception->getMessage()],
                ],
            ], 422);
        }

        $item->loadCount('movies');

        return response()->json([
            'success' => true,
            'message' => "{$config['singular']} با موفقیت ایجاد شد.",
            'data' => [
                'item' => $this->transformItem($item),
            ],
        ], 201);
    }

    public function update(Request $request, string $type, int $id): JsonResponse
    {
        $config = $this->resolveConfig($type);
        $modelClass = $config['model'];
        /** @var Model $item */
        $item = $modelClass::query()->findOrFail($id);
        $validated = $this->validator($request->all(), $modelClass, $id)->validate();

        try {
            $item = DB::transaction(function () use ($item, $modelClass, $validated) {
                $previousSlug = $item instanceof Actor ? $item->slug : null;
                $attributes = [
                    'name' => $validated['name'],
                    'slug' => $this->resolveUniqueSlug(
                        $modelClass,
                        (string) ($validated['slug'] ?? $validated['name']),
                        $item->getKey()
                    ),
                    'description' => $validated['description'] ?? null,
                    'is_active' => (bool) ($validated['is_active'] ?? true),
                    'sort_order' => (int) ($validated['sort_order'] ?? 0),
                ];

                if ($item instanceof Actor) {
                    $attributes['imdb_url'] = $validated['imdb_url'] ?? null;
                }

                $item->update($attributes);

                if ($item instanceof Actor) {
                    if ($previousSlug !== null && $previousSlug !== $item->slug) {
                        $this->actorImageSyncService->deleteStoredImage(new Actor(['slug' => $previousSlug]));
                    }

                    if (filled($validated['imdb_url'] ?? null)) {
                        $item = $this->actorImageSyncService->syncFromImdb($item);
                    } elseif ($item->image_path) {
                        $this->actorImageSyncService->deleteStoredImage($item);
                        $item->forceFill(['image_path' => null])->save();
                    }
                }

                return $item->refresh();
            });
        } catch (InvalidArgumentException $exception) {
            return response()->json([
                'success' => false,
                'message' => $exception->getMessage(),
                'errors' => [
                    'imdb_url' => [$exception->getMessage()],
                ],
            ], 422);
        }

        $item->loadCount('movies');

        return response()->json([
            'success' => true,
            'message' => "{$config['singular']} به‌روزرسانی شد.",
            'data' => [
                'item' => $this->transformItem($item),
            ],
        ]);
    }

    public function destroy(string $type, int $id): JsonResponse
    {
        $config = $this->resolveConfig($type);
        $modelClass = $config['model'];
        /** @var Model&object{movies: mixed,name: string,slug: string} $item */
        $item = $modelClass::query()->findOrFail($id);

        if (method_exists($item, 'movies')) {
            $item->movies()->detach();
        }

        $name = $item->name;
        $slug = $item->slug;

        if ($item instanceof Actor) {
            $this->actorImageSyncService->deleteStoredImage($item);
        }

        $item->delete();

        return response()->json([
            'success' => true,
            'message' => "{$config['singular']} حذف شد.",
            'data' => [
                'deleted_item' => [
                    'name' => $name,
                    'slug' => $slug,
                ],
            ],
        ]);
    }

    /**
     * @param  class-string<Model>  $modelClass
     */
    private function validator(array $payload, string $modelClass, ?int $ignoreId = null)
    {
        $rules = [
            'name' => ['required', 'string', 'max:255'],
            'slug' => [
                'nullable',
                'string',
                'max:255',
                Rule::unique((new $modelClass())->getTable(), 'slug')->ignore($ignoreId),
            ],
            'description' => ['nullable', 'string'],
            'is_active' => ['nullable', 'boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:9999'],
        ];

        if ($modelClass === Actor::class) {
            $rules['imdb_url'] = ['nullable', 'url', 'max:500', 'regex:/imdb\.com\/name\/nm\d+/i'];
        }

        return Validator::make($payload, $rules);
    }

    /**
     * @return array{model: class-string<Model>, singular: string, plural: string}
     */
    private function resolveConfig(string $type): array
    {
        $configs = $this->configs();

        abort_unless(isset($configs[$type]), 404, 'Taxonomy type not found.');

        return $configs[$type];
    }

    /**
     * @param  class-string<Model>  $modelClass
     */
    private function resolveUniqueSlug(string $modelClass, string $value, ?int $ignoreId = null): string
    {
        $baseSlug = Str::slug($value);

        if ($baseSlug === '') {
            $baseSlug = 'taxonomy-item';
        }

        $slug = $baseSlug;
        $counter = 2;

        while (
            $modelClass::query()
                ->when($ignoreId !== null, fn ($query) => $query->whereKeyNot($ignoreId))
                ->where('slug', $slug)
                ->exists()
        ) {
            $slug = "{$baseSlug}-{$counter}";
            $counter++;
        }

        return $slug;
    }

    /**
     * @param  Model&object{id:int,name:string,slug:string,description:?string,is_active:bool,sort_order:int,movies_count?:int}  $item
     * @return array<string, mixed>
     */
    private function transformItem(Model $item): array
    {
        return [
            'id' => $item->id,
            'name' => $item->name,
            'slug' => $item->slug,
            'description' => $item->description,
            'imdb_url' => $item instanceof Actor ? $item->imdb_url : null,
            'image_path' => $item instanceof Actor ? $item->image_path : null,
            'image_url' => $item instanceof Actor
                ? $this->mediaPathService->inferPublicUrlFromAbsolutePath($item->image_path)
                : null,
            'is_active' => (bool) $item->is_active,
            'sort_order' => $item->sort_order,
            'movies_count' => (int) ($item->movies_count ?? 0),
            'created_at' => $item->created_at?->toISOString(),
            'updated_at' => $item->updated_at?->toISOString(),
        ];
    }
}

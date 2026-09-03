<?php

namespace App\Http\Controllers;

use App\Services\Cinema\ContentImageIngestionService;
use App\Services\Cinema\ImageIngestionService;
use App\Services\Cinema\ImageStorageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class AdminUploadController extends Controller
{
    public function __construct(
        private readonly ImageStorageService $imageStorage,
        private readonly ImageIngestionService $imageIngestionService,
        private readonly ContentImageIngestionService $contentImageIngestionService,
    ) {
    }

    public function storeImage(Request $request): JsonResponse
    {
        if (! $request->hasFile('file')) {
            $contentLength = (int) $request->header('Content-Length', 0);
            $postMaxBytes = $this->parsePhpIniSize((string) ini_get('post_max_size'));
            $uploadMaxBytes = $this->parsePhpIniSize((string) ini_get('upload_max_filesize'));
            $effectiveMaxBytes = min(
                $postMaxBytes > 0 ? $postMaxBytes : PHP_INT_MAX,
                $uploadMaxBytes > 0 ? $uploadMaxBytes : PHP_INT_MAX,
                10 * 1024 * 1024,
            );

            if ($contentLength > 0 && $contentLength > $effectiveMaxBytes) {
                return response()->json([
                    'success' => false,
                    'message' => 'حجم فایل از محدودیت سرور بیشتر است. حداکثر مجاز آپلود تصویر ۱۰ مگابایت است.',
                ], 413);
            }

            return response()->json([
                'success' => false,
                'message' => 'فایل تصویر دریافت نشد. اگر فایل بزرگ است، حجم آن را کمتر از ۱۰ مگابایت کنید.',
            ], 422);
        }

        $validated = Validator::make($request->all(), [
            'file' => ['required', 'file', 'mimes:jpg,jpeg,png,webp,gif', 'max:10240'],
            'entity_type' => ['nullable', Rule::in(['movie', 'series'])],
            'entity_id' => ['nullable', 'integer', 'min:1'],
            'image_role' => ['nullable', Rule::in(['poster', 'backdrop', 'still'])],
        ], [
            'file.required' => 'فایل تصویر الزامی است.',
            'file.mimes' => 'فرمت تصویر مجاز نیست.',
            'file.max' => 'حجم فایل نباید بیشتر از ۱۰ مگابایت باشد.',
        ])->validate();

        /** @var \Illuminate\Http\UploadedFile $file */
        $file = $validated['file'];

        $mimeType = strtolower((string) $file->getMimeType());
        $extension = $this->imageStorage->extensionFromMimeType($mimeType);
        $plan = $this->imageStorage->buildStoragePlan('year', $extension);

        $binary = file_get_contents($file->getRealPath());
        if ($binary === false) {
            return response()->json([
                'success' => false,
                'message' => 'خواندن فایل آپلودشده ممکن نشد.',
            ], 422);
        }

        $this->imageStorage->saveBinary($plan['physical_path'], $binary);

        $entityType = $validated['entity_type'] ?? null;
        $entityId = isset($validated['entity_id']) ? (int) $validated['entity_id'] : null;
        $imageRole = $validated['image_role'] ?? 'poster';
        $imageType = match ($imageRole) {
            'backdrop' => 'backdrop',
            'still' => 'still',
            default => 'poster',
        };

        $registerResult = $this->imageIngestionService->registerUploadedManagedFile(
            $plan['public_path'],
            [
                'entity_type' => $entityType,
                'entity_id' => $entityId,
                'image_type' => $imageType,
                'is_primary' => $imageType === 'poster',
            ],
        );

        if (! $registerResult['success']) {
            return response()->json([
                'success' => false,
                'message' => $registerResult['error'] ?? 'ثبت تصویر آپلودشده انجام نشد.',
            ], 422);
        }

        $imagePath = $plan['public_path'];
        $imageObject = $registerResult['image'];
        $synced = null;

        if (
            is_string($entityType)
            && $entityId !== null
            && $entityId > 0
            && in_array($imageRole, ['poster', 'backdrop'], true)
        ) {
            $synced = $this->contentImageIngestionService->syncUploadedCatalogImage(
                $entityType,
                $entityId,
                $imageRole,
                $imagePath,
                is_array($imageObject) ? $imageObject : null,
            );
        }

        return response()->json([
            'success' => true,
            'data' => [
                'image_path' => $imagePath,
                'image' => $imageObject,
                'synced' => $synced,
            ],
        ]);
    }

    private function parsePhpIniSize(string $value): int
    {
        $value = trim($value);

        if ($value === '' || $value === '-1') {
            return 0;
        }

        $unit = strtolower(substr($value, -1));
        $number = (float) $value;

        return match ($unit) {
            'g' => (int) round($number * 1024 * 1024 * 1024),
            'm' => (int) round($number * 1024 * 1024),
            'k' => (int) round($number * 1024),
            default => (int) round($number),
        };
    }
}

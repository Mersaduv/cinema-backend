<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\Cinema\TicketAttachmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class UploadController extends Controller
{
    public function __construct(
        private readonly TicketAttachmentService $ticketAttachmentService,
    ) {
    }

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();

        if (! $user instanceof User || $user->status === 'banned') {
            return response()->json(['success' => false, 'message' => 'Unauthorized.'], 401);
        }

        $validated = Validator::make($request->all(), [
            'assetType' => ['required', 'string', 'in:image,ticket_attachment'],
            'file' => ['required', 'file', 'max:10240'],
            'releaseYear' => ['nullable', 'integer', 'min:1900', 'max:2100'],
        ], [
            'file.required' => 'فایل الزامی است.',
            'file.max' => 'حجم فایل نباید بیشتر از ۱۰ مگابایت باشد.',
        ])->validate();

        $assetType = (string) $validated['assetType'];

        /** @var \Illuminate\Http\UploadedFile $file */
        $file = $validated['file'];

        if ($assetType === 'image') {
            Validator::make(
                ['file' => $file],
                ['file' => ['mimes:jpg,jpeg,png,webp,gif']],
                ['file.mimes' => 'فرمت تصویر مجاز نیست.'],
            )->validate();
            $stored = $this->ticketAttachmentService->storeUploadedImage($file);
        } else {
            Validator::make(
                ['file' => $file],
                ['file' => ['mimes:jpg,jpeg,png,webp,gif,pdf,zip,doc,docx,txt']],
                ['file.mimes' => 'فرمت فایل مجاز نیست.'],
            )->validate();
            $stored = $this->ticketAttachmentService->storeUploadedAttachment($file);
        }

        return response()->json([
            'success' => true,
            'fileDirectory' => $stored['fileDirectory'],
            'fileName' => $stored['fileName'],
            'data' => [
                'fileDirectory' => $stored['fileDirectory'],
                'fileName' => $stored['fileName'],
                'image_path' => $stored['image_path'],
                'image_url' => $stored['image_url'],
            ],
        ]);
    }
}

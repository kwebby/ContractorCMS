<?php

/**
 * Author: ramanpal singh
 * URL: https://kwebby.com
 */

namespace App\Http\Controllers;

use App\Communications\EmailTemplates;
use App\Contracts\RecordRepository;
use App\Support\Access;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class EmailTemplatesController extends Controller
{
    public function __construct(private readonly EmailTemplates $templates, private readonly RecordRepository $records) {}

    public function index(): JsonResponse
    {
        Access::authorize('settings.manage');

        return response()->json(['data' => $this->templates->all()]);
    }

    public function update(Request $request, string $key): JsonResponse
    {
        Access::authorize('settings.manage');

        return response()->json(['data' => $this->templates->update($key, $request->all())]);
    }

    public function revisions(string $key): JsonResponse
    {
        Access::authorize('settings.manage');
        $this->templates->get($key);

        return response()->json(['data' => $this->records->all('email_template_revisions', ['key' => $key])]);
    }

    public function preview(Request $request, string $key): JsonResponse
    {
        Access::authorize('settings.manage');
        $data = $request->validate(['variables' => ['sometimes', 'array', 'max:10']]);
        $defaults = [
            'recipient_name' => 'Sample customer', 'action_url' => rtrim(config('app.url'), '/').'/login', 'document_number' => 'DRAFT-001',
            'total' => 'USD 125.00', 'service' => 'Service visit', 'appointment_at' => 'October 20, 2026 at 10:00 AM', 'message' => 'This is a preview. No email has been sent.', 'role' => 'technician',
        ];

        return response()->json(['data' => $this->templates->render($key, array_replace($defaults, $data['variables'] ?? []))]);
    }
}

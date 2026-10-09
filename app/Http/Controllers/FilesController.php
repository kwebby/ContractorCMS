<?php

/**
 * Author: ramanpal singh
 * URL: https://kwebby.com
 */

namespace App\Http\Controllers;

use App\Contracts\RecordRepository;
use App\Support\Access;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class FilesController extends Controller
{
    public function __construct(private RecordRepository $records) {}

    public function upload(Request $request): mixed
    {
        $data = $request->validate(['file' => 'required|file|max:10240|mimetypes:image/jpeg,image/png,image/webp,text/plain,application/pdf', 'visibility' => 'sometimes|in:private,draft,public', 'resource_type' => 'sometimes|in:jobs,conversations', 'resource_id' => 'required_with:resource_type|string']);
        abort_if($request->user()->role === 'client' && ! $request->user()->email_verified_at, 403, 'Verify your email first.');
        $visibility = $data['visibility'] ?? 'private';
        if (in_array($visibility, ['public', 'draft'], true)) {
            Access::authorize('publishing.manage');
            abort_if(! empty($data['resource_type']), 422, 'Publishing images cannot be attached to a private job or conversation.');
        }
        if (! empty($data['resource_type'])) {
            $record = $this->records->get($data['resource_type'], $data['resource_id']);
            if ($data['resource_type'] === 'conversations') {
                $user = $request->user();
                abort_unless(Access::can($user, 'chat.use') && ($user->role !== 'client' || ($user->email_verified_at && $user->customer_id)) && in_array($user->id, $record['participants'] ?? [], true), 403);
            } else {
                Access::authorizeRecord($request->user(), $record, 'operations.manage');
            }
        }
        $file = $request->file('file');
        $mime = $file->getMimeType();
        $id = (string) Str::uuid();
        $status = 'ready';
        $extension = match ($mime) {
            'image/jpeg' => 'jpg','image/png' => 'png','image/webp' => 'webp','application/pdf' => 'pdf',default => 'txt'
        };
        $path = 'uploads/'.$id.'.'.$extension;
        $bytes = $file->get();
        if (str_starts_with($mime, 'image/')) {
            $info = @getimagesizefromstring($bytes);
            abort_unless($info && $info[0] * $info[1] <= 10000000, 422, 'Image is invalid or too large.');
            $image = @imagecreatefromstring($bytes);
            abort_unless($image, 422, 'Unable to decode image.');
            ob_start();
            match ($mime) {
                'image/jpeg' => imagejpeg($image, null, 90),'image/png' => imagepng($image),default => imagewebp($image, null, 85)
            };
            $bytes = ob_get_clean();
            imagedestroy($image);
        } elseif ($mime === 'application/pdf') {
            $status = 'quarantined';
            abort_if(in_array($visibility, ['public', 'draft'], true), 422, 'Publishing media supports raster images only.');
        }
        abort_if(in_array($visibility, ['public', 'draft'], true) && ! in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true), 422, 'Publishing media supports raster images only.');
        Storage::disk('local')->put($path, $bytes);
        $record = $this->records->create('files', ['name' => mb_substr(preg_replace('/[\x00-\x1f\x7f]/', '', basename(str_replace('\\', '/', $file->getClientOriginalName()))), 0, 200), 'path' => $path, 'mime' => $mime, 'size' => strlen($bytes), 'owner_id' => $request->user()->id, 'visibility' => $visibility, 'initial_visibility' => $visibility, 'publication_policy' => $visibility === 'draft' ? 'private-until-first-publication' : ($visibility === 'public' ? 'explicit-public-upload' : null), 'status' => $status, 'resource_type' => $data['resource_type'] ?? null, 'resource_id' => $data['resource_id'] ?? null, 'sha256' => hash('sha256', $bytes)], $id);

        return response()->json(['data' => Arr::except($record, ['path']) + ['url' => '/files/'.$id]], 201);
    }

    public function download(Request $request, string $id): mixed
    {
        $file = $this->records->get('files', $id);
        abort_unless($file['status'] === 'ready', 423, 'File is quarantined until a scanner clears it.');
        if ($file['visibility'] === 'draft') {
            abort_unless(Access::can($request->user(), 'publishing.manage'), 403, 'Draft publishing images are available only to publishing staff.');
        } elseif ($file['visibility'] !== 'public') {
            $user = $request->user();
            abort_unless($user, 401);
            if ($file['owner_id'] !== $user->id && ! Access::can($user, 'files.manage')) {
                abort_unless(! empty($file['resource_type']) && ! empty($file['resource_id']), 403);
                $record = $this->records->get($file['resource_type'], $file['resource_id']);
                if ($file['resource_type'] === 'conversations') {
                    abort_unless(Access::can($user, 'chat.use') && ($user->role !== 'client' || ($user->email_verified_at && $user->customer_id)) && in_array($user->id, $record['participants'] ?? [], true), 403);
                } else {
                    Access::authorizeRecord($user, $record, 'operations.manage');
                }
            }
        }
        $headers = ['Content-Type' => $file['mime'], 'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => $file['visibility'] === 'public' ? 'public, max-age=86400' : 'private, no-store'];
        if ($file['visibility'] !== 'public') {
            $headers['X-Robots-Tag'] = 'noindex';
        }

        return Storage::disk('local')->response($file['path'], $file['name'], $headers, str_starts_with($file['mime'], 'image/') ? 'inline' : 'attachment');
    }
}

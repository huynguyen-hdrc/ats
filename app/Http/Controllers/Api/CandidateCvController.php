<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Candidate;
use App\Services\CvFiles;
use App\Services\CvParser;
use App\Support\Audit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** File CV (tải lên / tải về / xoá) và đọc CV tự động. */
class CandidateCvController extends Controller
{
    /**
     * Route cho phép candidates.edit hoặc candidates.create. App cũ tải CV ngay sau khi thêm ứng viên
     * (chỉ cần quyền tạo mới): người chỉ có quyền tạo được gắn CV cho hồ sơ mình vừa tạo, khi hồ sơ chưa có CV.
     */
    public function store(Request $request, Candidate $candidate, CvFiles $files): JsonResponse
    {
        $user = $request->user();
        if (! $user->hasPerm('candidates', 'edit')
            && ($candidate->created_by !== $user->id || $candidate->cv_path !== null)) {
            return response()->json(['message' => 'Vai trò của bạn không có quyền sửa ở nhóm "Ứng viên".', 'code' => 'forbidden'], 403);
        }
        $request->validate(
            ['file' => ['required', 'file', 'max:'.CvFiles::MAX_KB, 'extensions:'.implode(',', CvFiles::EXTENSIONS)]],
            ['file.max' => 'File CV tối đa 15 MB.', 'file.extensions' => 'Chỉ nhận file CV dạng .pdf, .docx hoặc .txt.'],
            ['file' => 'file CV'],
        );
        $name = $files->store($candidate, $request->file('file'));
        Audit::log('candidates.cv_upload', $candidate, ['file' => $name]);

        return response()->json(['data' => $candidate->fresh()->load('job:id,title,department')], 201);
    }

    /** Chỉ phát file qua API có kiểm quyền, luôn dạng tải về (attachment). */
    public function show(Candidate $candidate, CvFiles $files): StreamedResponse|JsonResponse
    {
        $path = $files->diskPath($candidate);
        if (! $path || ! Storage::disk(CvFiles::DISK)->exists($path)) {
            return response()->json(['message' => 'Không tìm thấy file CV.'], 404);
        }

        return Storage::disk(CvFiles::DISK)->download($path, $candidate->cv_filename ?: basename($path), [
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    public function destroy(Candidate $candidate, CvFiles $files): JsonResponse
    {
        $old = $candidate->cv_filename;
        $files->delete($candidate);
        Audit::log('candidates.cv_delete', $candidate, ['file' => $old]);

        return response()->json(['data' => $candidate->fresh()->load('job:id,title,department')]);
    }

    /** Trình duyệt rút chữ từ file (mammoth / pdf.js), gửi lên đây để tách thông tin. */
    public function parse(Request $request, CvParser $parser): JsonResponse
    {
        $data = $request->validate([
            'text' => ['present', 'nullable', 'string', 'max:500000'],
            'filename' => ['nullable', 'string', 'max:255'],
        ], [], ['text' => 'nội dung CV', 'filename' => 'tên file']);
        $text = (string) ($data['text'] ?? '');
        if (mb_strlen(preg_replace('/\s+/u', '', $text)) < CvParser::MIN_CHARS) {
            // App cũ: nội dung rỗng/quá ngắn → HTTP 400 (selftest C5).
            return response()->json(['message' => 'Nội dung CV trống hoặc quá ngắn để đọc.'], 400);
        }

        return response()->json($parser->parse($text, $data['filename'] ?? null));
    }
}

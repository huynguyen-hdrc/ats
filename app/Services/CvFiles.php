<?php

namespace App\Services;

use App\Models\Candidate;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * File CV trên đĩa `local` (storage/app/private), không bao giờ có URL công khai.
 * cv_path giữ định dạng của kho `cvs` bên Supabase: "{candidate_id}/{tên file}"; trên đĩa nằm ở "cvs/{cv_path}".
 */
final class CvFiles
{
    public const DISK = 'local';

    public const MAX_KB = 15 * 1024;

    /** Đúng danh sách của ô chọn file ở app cũ (accept=".pdf,.docx,.txt"). */
    public const EXTENSIONS = ['pdf', 'docx', 'txt'];

    /** Tên file an toàn: bỏ thư mục, ký tự lạ → "_", giữ chữ có dấu, giới hạn độ dài. */
    public static function safeName(string $name): string
    {
        $name = basename(str_replace('\\', '/', $name));
        $ext = mb_strtolower(pathinfo($name, PATHINFO_EXTENSION));
        $stem = pathinfo($name, PATHINFO_FILENAME);
        $stem = preg_replace('/[^\p{L}\p{N}._\- ]+/u', '_', $stem);
        $stem = trim(preg_replace('/[\s_]+/u', '_', $stem), '._-');
        $stem = Str::limit($stem, 120, '') ?: 'cv';

        return $ext !== '' ? "{$stem}.{$ext}" : $stem;
    }

    /** Lưu (thay) CV của ứng viên. Trả tên file đã lưu. */
    public function store(Candidate $c, UploadedFile $file): string
    {
        $name = self::safeName($file->getClientOriginalName());
        $disk = Storage::disk(self::DISK);
        $old = $this->diskPath($c);
        $disk->putFileAs($c->cvFolder(), $file, $name);
        $path = $c->id.'/'.$name;
        if ($old && $old !== 'cvs/'.$path) {
            $disk->delete($old);
        }
        $c->forceFill(['cv_path' => $path, 'cv_filename' => $name])->save();

        return $name;
    }

    public function delete(Candidate $c): void
    {
        if ($p = $this->diskPath($c)) {
            Storage::disk(self::DISK)->delete($p);
        }
        $c->forceFill(['cv_path' => null, 'cv_filename' => null])->save();
    }

    /** Xoá cả thư mục của ứng viên (app cũ: purgeFiles sau khi xoá bản ghi). */
    public function purge(string $candidateId): void
    {
        Storage::disk(self::DISK)->deleteDirectory('cvs/'.$candidateId);
    }

    /** Đường dẫn trên đĩa, hoặc null nếu không có / không hợp lệ (chặn "../"). */
    public function diskPath(Candidate $c): ?string
    {
        $p = $c->cv_path;
        if (! $p || str_contains($p, '..') || ! str_starts_with($p, $c->id.'/')) {
            return null;
        }

        return 'cvs/'.$p;
    }
}

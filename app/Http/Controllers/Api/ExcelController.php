<?php

namespace App\Http\Controllers\Api;

use App\Excel\Column;
use App\Excel\Importer;
use App\Excel\ImportError;
use App\Excel\Sheet;
use App\Excel\Sheets\CandidatesSheet;
use App\Excel\Sheets\JobsSheet;
use App\Http\Controllers\Controller;
use App\Support\Access;
use App\Support\Audit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Nhập / xuất Excel dùng chung. Trình duyệt chỉ đọc/ghi file .xlsx; mọi chuyển đổi, kiểm tra,
 * chống trùng và ghi dữ liệu làm ở đây.
 */
class ExcelController extends Controller
{
    /** Thêm sheet mới khi chuyển từng module. */
    private const SHEETS = [
        'jobs' => JobsSheet::class,
        'candidates' => CandidatesSheet::class,
    ];

    public function template(Request $request, string $entity): JsonResponse
    {
        $sheet = $this->sheet($request, $entity, 'view');
        $guide = ['Cột có dấu * là bắt buộc.', 'Ngày ghi dạng yyyy-mm-dd hoặc dd/mm/yyyy.',
            'Dòng mẫu ở sheet "Nhap lieu" chỉ để tham khảo — xoá đi trước khi nhập.', '', 'Giá trị cho phép:'];
        foreach ($sheet->columns() as $c) {
            $allowed = match ($c->type) {
                'enum' => implode(' / ', $c->options),
                'map', 'stage' => implode(' / ', $c->options),
                'bool' => 'Có / Không',
                'lookup' => 'phải trùng tên đã có trong app',
                default => $c->hint,
            };
            if ($allowed) {
                $guide[] = "{$c->exportHeader()}: {$allowed}";
            }
        }

        return response()->json([
            'filename' => "mau-{$sheet->stem()}.xlsx",
            'headers' => array_map(fn (Column $c) => $c->header, $sheet->columns()),
            'sample' => array_map(fn (Column $c) => $c->sample, $sheet->columns()),
            'guide' => $guide,
            'importable' => $sheet->importable(),
        ]);
    }

    public function export(Request $request, string $entity): JsonResponse
    {
        $sheet = $this->sheet($request, $entity, 'view');
        $importer = new Importer($sheet);
        $rows = $sheet->exportRows()->map(fn ($r) => $importer->exportRow($r))->values();
        Audit::log("{$entity}.export", $entity, ['rows' => $rows->count()]);

        return response()->json([
            'filename' => "{$sheet->stem()}-".now()->format('Y-m-d').'.xlsx',
            'sheet' => mb_substr($sheet->label(), 0, 28),
            'headers' => array_map(fn (Column $c) => $c->exportHeader(), $sheet->columns()),
            'rows' => $rows,
        ]);
    }

    public function preview(Request $request, string $entity): JsonResponse
    {
        $sheet = $this->importSheet($request, $entity);

        return response()->json((new Importer($sheet))->preview($this->payload($request)));
    }

    public function import(Request $request, string $entity): JsonResponse
    {
        $sheet = $this->importSheet($request, $entity);
        try {
            $result = (new Importer($sheet))->commit($this->payload($request));
        } catch (ImportError $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
        Audit::log("{$entity}.import", $entity, $result);

        return response()->json($result + [
            'message' => "Đã thêm {$result['inserted']}, cập nhật {$result['updated']} dòng.",
        ]);
    }

    private function sheet(Request $request, string $entity, string $action): Sheet
    {
        $class = self::SHEETS[$entity] ?? abort(404, 'Không có mẫu Excel cho mục này.');
        $sheet = new $class;
        if (! $request->user()->hasPerm($sheet->module(), $action)) {
            abort(403, sprintf('Vai trò của bạn không có quyền %s ở nhóm "%s".',
                mb_strtolower(Access::ACTIONS[$action]), Access::MODULES[$sheet->module()]));
        }

        return $sheet;
    }

    /** Nhập cần cả quyền tạo mới lẫn sửa (dòng trùng sẽ được cập nhật). */
    private function importSheet(Request $request, string $entity): Sheet
    {
        $sheet = $this->sheet($request, $entity, 'create');
        $this->sheet($request, $entity, 'edit');
        abort_unless($sheet->importable(), 422, 'Mục này chỉ xuất được, không nhập.');

        return $sheet;
    }

    private function payload(Request $request): array
    {
        return $request->validate([
            'headers' => ['required', 'array', 'max:200'],
            'headers.*' => ['nullable'],
            'rows' => ['present', 'array', 'max:'.Importer::MAX_ROWS],
            'rows.*' => ['array'],
        ]);
    }
}

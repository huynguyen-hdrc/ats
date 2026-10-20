<?php

namespace App\Excel;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/** Định nghĩa một loại dữ liệu nhập/xuất Excel (tương ứng một mục trong SHEETS của app cũ). */
abstract class Sheet
{
    /** Nhãn hiển thị, tên sheet khi xuất. */
    abstract public function label(): string;

    /** Phần đầu tên file: {stem}-{yyyy-mm-dd}.xlsx */
    abstract public function stem(): string;

    /** Nhóm quyền (module) của ma trận quyền. */
    abstract public function module(): string;

    /** @return Column[] */
    abstract public function columns(): array;

    /** Dữ liệu để xuất, đã lọc theo phạm vi của user. */
    abstract public function exportRows(): Collection;

    public function importable(): bool
    {
        return false;
    }

    /** Lớp model khi nhập. */
    public function model(): string
    {
        throw new \LogicException(static::class.' không nhập được.');
    }

    /** Khoá chống trùng của một dòng đã chuyển đổi; null = luôn thêm mới. */
    public function keyOf(array $row): ?string
    {
        return null;
    }

    /** Mô tả khoá chống trùng cho màn hình xem trước, vd "tên + phòng ban". */
    public function keyLabel(): string
    {
        return '';
    }

    /** Khoá của bản ghi đã có trong DB (cùng cách tính với keyOf). */
    public function keyOfModel(Model $m): ?string
    {
        return $this->keyOf($m->getAttributes());
    }

    /** Thêm thuộc tính khi tạo mới (vd created_by). */
    public function extraOnCreate(): array
    {
        return [];
    }
}

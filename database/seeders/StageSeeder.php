<?php

namespace Database\Seeders;

use App\Models\Stage;
use Illuminate\Database\Seeder;

/**
 * 7 vòng tuyển dụng (selftest: "Kanban hiện đủ 7 cột"). Chạy lại được: chỉ thêm vòng còn thiếu,
 * không ghi đè nhãn/thứ tự đã có (dữ liệu thật chuyển từ Supabase sẽ thay các giá trị này).
 *
 * Chắc chắn từ app cũ: new (mặc định), phone (selftest new → phone), screening (nhãn "Screening"),
 * hired (đếm "đã tuyển"). Suy luận: interview, test, offer; nhãn tiếng Anh như "New"/"Screening"
 * và thứ tự sắp xếp. Xem docs/specs/2026-10-20-module-inventory.md §1.1, §8.1.
 */
class StageSeeder extends Seeder
{
    public const STAGES = [
        ['key' => 'new', 'label' => 'New', 'sort_order' => 10],
        ['key' => 'screening', 'label' => 'Screening', 'sort_order' => 20],
        ['key' => 'phone', 'label' => 'Phone screen', 'sort_order' => 30],
        ['key' => 'interview', 'label' => 'Interview', 'sort_order' => 40],
        ['key' => 'test', 'label' => 'Test', 'sort_order' => 50],
        ['key' => 'offer', 'label' => 'Offer', 'sort_order' => 60],
        ['key' => 'hired', 'label' => 'Hired', 'sort_order' => 70],
    ];

    public function run(): void
    {
        foreach (self::STAGES as $s) {
            Stage::firstOrCreate(['key' => $s['key']], $s);
        }
    }
}

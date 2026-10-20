<?php

namespace App\Excel;

/**
 * Một cột trong file Excel nhập/xuất. Kiểu và cờ giữ nguyên ý nghĩa của SHEETS trong app cũ:
 * text (giữ số 0 đầu) · phone · num · date · bool (Có/Không) · enum · map (nhãn→mã) · list (phẩy) · lookup
 * · stage (vòng tuyển: nhận mã hoặc nhãn, options = [mã => nhãn]).
 */
final class Column
{
    /**
     * @param  array<int,string>|array<string,string>  $options  enum: danh sách; map, stage: [mã => nhãn]
     * @param  \Closure|null  $lookup  fn(string $value): string  — trả id, ném ImportError nếu không thấy/trùng
     * @param  \Closure|null  $display  fn(mixed $stored): string — giá trị khi xuất (cho lookup)
     */
    public function __construct(
        public string $header,
        public string $key,
        public string $type = 'string',
        public bool $required = false,
        public mixed $default = null,
        public array $options = [],
        public ?\Closure $lookup = null,
        public ?\Closure $display = null,
        public string $sample = '',
        public string $hint = '',
    ) {}

    /** Tiêu đề chuẩn hoá để so khớp: chữ thường, bỏ "(…)", "*", ":", gộp khoảng trắng. */
    public static function norm(string $h): string
    {
        $h = mb_strtolower($h);
        $h = preg_replace('/\(.*?\)/u', '', $h);
        $h = str_replace(['*', ':'], '', $h);

        return trim(preg_replace('/\s+/u', ' ', $h));
    }

    public function normHeader(): string
    {
        return self::norm($this->header);
    }

    /** Tiêu đề khi xuất: bỏ dấu " *". */
    public function exportHeader(): string
    {
        return trim(str_replace(' *', '', $this->header));
    }
}

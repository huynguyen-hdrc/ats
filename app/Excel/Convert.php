<?php

namespace App\Excel;

/**
 * Chuyển giá trị ô Excel → giá trị lưu. Chuyển nguyên từ xlDate / xlNum / xlText của app cũ
 * (public/legacy/index.html:1183-1222), kèm kiểm tra ngày tồn tại thật (bản cũ cho lọt 31/02).
 */
final class Convert
{
    /** Ngày: 'yyyy-mm-dd', 'yyyy-m-d', d/m/yyyy (ngày trước), số serial Excel. */
    public static function date(mixed $v): ?string
    {
        if ($v === null || $v === '') {
            return null;
        }
        if (is_int($v) || is_float($v)) {
            // Serial Excel, gốc 1899-12-30.
            $ts = (int) round(($v - 25569) * 86400);

            return gmdate('Y-m-d', $ts);
        }
        $s = trim((string) $v);
        if (preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})(?:[T ].*)?$/', $s, $m)) {
            [$y, $mo, $d] = [(int) $m[1], (int) $m[2], (int) $m[3]];
        } elseif (preg_match('/^(\d{1,2})[\/\-.](\d{1,2})[\/\-.](\d{4})$/', $s, $m)) {
            [$d, $mo, $y] = [(int) $m[1], (int) $m[2], (int) $m[3]];
        } else {
            throw new ImportError("ngày \"{$s}\" sai định dạng, cần yyyy-mm-dd");
        }
        if (! checkdate($mo, $d, $y)) {
            throw new ImportError("ngày \"{$s}\" không tồn tại");
        }

        return sprintf('%04d-%02d-%02d', $y, $mo, $d);
    }

    /** Số: bỏ "vnd/vnđ/đ/₫/$", dấu phân cách hàng nghìn; dấu phẩy thập phân → chấm. */
    public static function num(mixed $v, string $header): int|float|null
    {
        if ($v === null || $v === '') {
            return null;
        }
        if (is_int($v) || is_float($v)) {
            return $v;
        }
        $s = (string) $v;
        $bad = fn () => new ImportError("\"{$header}\" phải là số, đang là \"{$s}\"");
        if (! preg_match('/\d/', $s)) {
            throw $bad();
        }
        $t = preg_replace('/\b(vnd|vnđ|đ)\b/iu', '', mb_strtolower($s));
        $t = str_replace(['₫', '$', 'đ'], '', $t);
        if (preg_match('/\p{L}/u', $t)) {
            throw $bad();
        }
        $t = preg_replace('/\s+/', '', $t);
        $t = preg_replace('/[.,](?=\d{3}(?!\d))/', '', $t);   // 15.000.000 → 15000000
        $t = preg_replace('/,/', '.', $t, 1);                   // 2,8 → 2.8
        if (! is_numeric($t)) {
            throw $bad();
        }

        return str_contains($t, '.') ? (float) $t : (int) $t;
    }

    /**
     * Văn bản giữ số 0 đầu: ô kiểu số đã mất số 0 ("901234567") nhưng chuỗi hiển thị còn ("0901234567")
     * → lấy chuỗi hiển thị.
     */
    public static function text(mixed $raw, mixed $shown): ?string
    {
        if ($raw === null || $raw === '') {
            return null;
        }
        $rawS = is_float($raw) && floor($raw) == $raw ? (string) (int) $raw : (string) $raw;
        $shownS = (string) ($shown ?? '');
        if (preg_match('/^\d+$/', $rawS) && preg_match('/^0\d/', preg_replace('/[\s.()\-]/', '', $shownS))) {
            return trim($shownS);
        }

        return trim($rawS);
    }

    /** Số điện thoại VN: chuẩn hoá +84/84 → 0, bù số 0 cho di động 9 số bị mất. */
    public static function phone(mixed $raw, mixed $shown): ?string
    {
        $t = self::text($raw, $shown);
        if ($t === null) {
            return null;
        }
        $digits = preg_replace('/[^\d+]/', '', $t);
        if ($digits === '' || $digits === '+') {
            return $t;
        }
        if (preg_match('/^\+?84(\d{9})$/', $digits, $m)) {
            return '0'.$m[1];
        }
        if (preg_match('/^[35789]\d{8}$/', $digits)) {
            return '0'.$digits;
        }

        return $digits; // số bàn (0281234567) hay số nước ngoài (+65…) giữ nguyên chữ số
    }

    /** Có/Không: chỉ các giá trị "có" rõ ràng là true; mọi giá trị khác (không rỗng) là false. */
    public static function bool(mixed $v): ?bool
    {
        if ($v === null || $v === '') {
            return null;
        }
        if (is_bool($v)) {
            return $v;
        }

        return (bool) preg_match('/^(có|co|x|yes|true|1|đang tuyển)$/iu', trim((string) $v));
    }
}

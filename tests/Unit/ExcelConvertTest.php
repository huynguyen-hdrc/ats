<?php

namespace Tests\Unit;

use App\Excel\Column;
use App\Excel\Convert;
use App\Excel\ImportError;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Chuyển từ selftest nhóm F của app cũ (selftest.html:468-475) + các ca biên. */
class ExcelConvertTest extends TestCase
{
    public static function numbers(): array
    {
        return [
            ['15.000.000', 15000000], ['15,000,000', 15000000], ['2,8', 2.8], ['2.8', 2.8],
            ['15 000 000 vnđ', 15000000], ['₫ 1.200', 1200], [42, 42], [3.5, 3.5],
        ];
    }

    #[DataProvider('numbers')]
    public function test_num(mixed $in, int|float $out): void
    {
        $this->assertSame($out, Convert::num($in, 'Lương'));
    }

    public function test_num_rejects_words(): void
    {
        $this->expectException(ImportError::class);
        Convert::num('15 triệu', 'Lương');
    }

    public static function dates(): array
    {
        return [
            ['2026-10-01', '2026-10-01'], ['2026-1-5', '2026-01-05'], ['05/01/2026', '2026-01-05'],
            ['5-1-2026', '2026-01-05'], ['31.12.2026', '2026-12-31'], [46296, '2026-10-01'],
        ];
    }

    #[DataProvider('dates')]
    public function test_date(mixed $in, string $out): void
    {
        $this->assertSame($out, Convert::date($in));
    }

    public function test_date_rejects_impossible_day(): void
    {
        $this->expectException(ImportError::class);
        Convert::date('31/02/2026');
    }

    public function test_date_rejects_garbage(): void
    {
        $this->expectException(ImportError::class);
        Convert::date('tháng sau');
    }

    public function test_text_keeps_leading_zero_from_displayed_value(): void
    {
        $this->assertSame('001', Convert::text(1, '001'));
        $this->assertSame('NV001', Convert::text('NV001', 'NV001'));
        $this->assertSame('123', Convert::text(123.0, '123'));
    }

    public static function phones(): array
    {
        return [
            [901234567, '901234567', '0901234567'],
            ['+84901234567', '+84901234567', '0901234567'],
            ['84901234567', '84901234567', '0901234567'],
            [901234567, '0901234567', '0901234567'],
            ['028 1234 567', '028 1234 567', '0281234567'],
            ['0281234567', '0281234567', '0281234567'],
        ];
    }

    #[DataProvider('phones')]
    public function test_phone(mixed $raw, string $shown, string $out): void
    {
        $this->assertSame($out, Convert::phone($raw, $shown));
    }

    public function test_bool(): void
    {
        foreach (['Có', 'co', 'x', 'YES', 'true', '1', 'Đang tuyển'] as $v) {
            $this->assertTrue(Convert::bool($v), $v);
        }
        foreach (['Không', 'no', '0', 'đã đóng'] as $v) {
            $this->assertFalse(Convert::bool($v), $v);
        }
        $this->assertNull(Convert::bool(''));
    }

    public function test_header_normalisation(): void
    {
        $this->assertSame('ngày vào làm', Column::norm('Ngày vào làm (yyyy-mm-dd)'));
        $this->assertSame('họ tên', Column::norm('  Họ   tên * '));
        $this->assertSame('đang tuyển', Column::norm('Đang tuyển (Có/Không):'));
    }
}

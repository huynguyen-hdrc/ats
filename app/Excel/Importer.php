<?php

namespace App\Excel;

use Illuminate\Support\Facades\DB;

/**
 * Nhập Excel cho một Sheet. Trình duyệt đọc file (SheetJS) và gửi lên:
 *   { headers: string[], rows: [[{v: giá trị gốc, s: chuỗi hiển thị}, …], …] }
 * Ngày từ ô kiểu ngày đã được trình duyệt đổi sang 'yyyy-mm-dd' theo giờ máy.
 *
 * Khác app cũ: lần nhập chạy trong một transaction — có lỗi là không ghi dòng nào.
 */
final class Importer
{
    public const MAX_ROWS = 5000;

    public function __construct(private Sheet $sheet) {}

    /** @return array{rows: array<int, array{0:int,1:array}>, errors: array<int, array{line:int,message:string}>} */
    public function parse(array $payload): array
    {
        $cols = $this->sheet->columns();
        $headers = array_map(fn ($h) => Column::norm((string) $h), $payload['headers'] ?? []);

        $index = [];
        foreach ($cols as $c) {
            $i = array_search($c->normHeader(), $headers, true);
            if ($i !== false) {
                $index[$c->key] = $i;
            }
        }

        $missing = array_values(array_map(fn (Column $c) => $c->exportHeader(),
            array_filter($cols, fn (Column $c) => $c->required && ! isset($index[$c->key]))));
        if ($missing) {
            return ['rows' => [], 'errors' => [['line' => 1, 'message' => 'thiếu cột bắt buộc: '.implode(', ', $missing)]]];
        }

        $rows = [];
        $errors = [];
        foreach (array_slice($payload['rows'] ?? [], 0, self::MAX_ROWS) as $r => $cells) {
            $line = $r + 2; // dòng 1 là tiêu đề
            if ($this->blank($cells)) {
                continue;
            }
            $data = [];
            $rowErr = [];
            foreach ($cols as $c) {
                if (! isset($index[$c->key])) {
                    continue;
                }
                $cell = $cells[$index[$c->key]] ?? null;
                $v = is_array($cell) ? ($cell['v'] ?? null) : $cell;
                $s = is_array($cell) ? ($cell['s'] ?? null) : $cell;
                if ($v === null || (is_string($v) && trim($v) === '')) {
                    if ($c->required) {
                        $rowErr[] = "thiếu \"{$c->exportHeader()}\"";
                    }

                    continue; // ô trống: không ghi đè giá trị đang có
                }
                try {
                    $data[$c->key] = $this->convert($c, $v, $s);
                } catch (ImportError $e) {
                    $rowErr[] = $e->getMessage();
                }
            }
            if ($rowErr) {
                $errors[] = ['line' => $line, 'message' => implode('; ', $rowErr)];
            } else {
                $rows[] = [$line, $data];
            }
        }

        return ['rows' => $rows, 'errors' => $errors];
    }

    public function preview(array $payload): array
    {
        ['rows' => $rows, 'errors' => $errors] = $this->parse($payload);
        $existing = $this->existingKeys();
        $seen = [];
        $dup = 0;
        foreach ($rows as [, $data]) {
            $k = $this->sheet->keyOf($data);
            if ($k !== null && (isset($existing[$k]) || isset($seen[$k]))) {
                $dup++;
            }
            if ($k !== null) {
                $seen[$k] = true;
            }
        }
        $cols = array_slice($this->sheet->columns(), 0, 5);

        return [
            'valid' => count($rows),
            'error_count' => count($errors),
            'errors' => array_slice($errors, 0, 50),
            'duplicates' => $dup,
            'key_label' => $this->sheet->keyLabel(),
            'sample' => [
                'headers' => array_map(fn (Column $c) => $c->exportHeader(), $cols),
                'rows' => array_map(fn ($r) => array_map(fn (Column $c) => $this->show($c, $r[1][$c->key] ?? null), $cols),
                    array_slice($rows, 0, 5)),
            ],
        ];
    }

    /** @return array{inserted:int, updated:int} */
    public function commit(array $payload): array
    {
        ['rows' => $rows, 'errors' => $errors] = $this->parse($payload);
        if ($errors) {
            throw new ImportError('File còn '.count($errors).' dòng lỗi. Sửa rồi nhập lại.');
        }

        $model = $this->sheet->model();
        $defaults = [];
        foreach ($this->sheet->columns() as $c) {
            if ($c->default !== null) {
                $defaults[$c->key] = $c->default;
            }
        }

        return DB::transaction(function () use ($rows, $model, $defaults) {
            $byKey = [];
            foreach ($model::query()->get() as $m) {
                $k = $this->sheet->keyOfModel($m);
                if ($k !== null) {
                    $byKey[$k] ??= $m;
                }
            }
            $inserted = $updated = 0;
            foreach ($rows as [, $data]) {
                $k = $this->sheet->keyOf($data);
                if ($k !== null && isset($byKey[$k])) {
                    $byKey[$k]->fill($data)->save();
                    $updated++;
                } else {
                    $m = $model::create(array_merge($defaults, $data, $this->sheet->extraOnCreate()));
                    if ($k !== null) {
                        $byKey[$k] = $m; // trùng ngay trong file: dòng sau cập nhật dòng trước
                    }
                    $inserted++;
                }
            }

            return ['inserted' => $inserted, 'updated' => $updated];
        });
    }

    /** Một dòng dữ liệu → các ô khi xuất. */
    public function exportRow(array|object $record): array
    {
        $attrs = is_array($record) ? $record : $record->toArray();

        return array_map(fn (Column $c) => $this->show($c, $attrs[$c->key] ?? null), $this->sheet->columns());
    }

    private function show(Column $c, mixed $v): string|int|float
    {
        if ($v === null || $v === '') {
            return '';
        }

        return match ($c->type) {
            'bool' => $v ? 'Có' : 'Không',
            'map' => $c->options[$v] ?? '',
            'stage' => $c->options[$v] ?? (string) $v,
            'list' => implode(', ', (array) $v),
            'lookup' => $c->display ? ($c->display)($v) : (string) $v,
            'date' => substr((string) $v, 0, 10),
            'num', 'int' => is_numeric($v) ? $v + 0 : (string) $v,
            default => (string) $v,
        };
    }

    private function convert(Column $c, mixed $v, mixed $s): mixed
    {
        return match ($c->type) {
            'text' => Convert::text($v, $s),
            'phone' => Convert::phone($v, $s),
            'num' => Convert::num($v, $c->exportHeader()),
            'int' => $this->int($c, Convert::num($v, $c->exportHeader())),
            'date' => Convert::date($v),
            'bool' => Convert::bool($v),
            'enum' => $this->pick($c, (string) $v, array_combine($c->options, $c->options)),
            'map' => $this->pick($c, (string) $v, $c->options),
            'stage' => $this->stage($c, (string) $v),
            'list' => array_values(array_filter(array_map('trim', explode(',', (string) $v)), fn ($x) => $x !== '')),
            'lookup' => ($c->lookup)(trim((string) $v)),
            'longtext' => $this->limit($c, $this->str($v), 20000),
            default => $this->limit($c, $this->str($v), 255),
        };
    }

    private function int(Column $c, int|float|null $n): ?int
    {
        if ($n === null) {
            return null;
        }
        if ($n < 0 || floor($n) != $n) {
            throw new ImportError("\"{$c->exportHeader()}\" phải là số nguyên không âm, đang là \"{$n}\"");
        }

        return (int) $n;
    }

    private function str(mixed $v): string
    {
        return trim(is_float($v) && floor($v) == $v ? (string) (int) $v : (string) $v);
    }

    private function limit(Column $c, string $v, int $max): string
    {
        if (mb_strlen($v) > $max) {
            throw new ImportError("\"{$c->exportHeader()}\" dài quá {$max} ký tự");
        }

        return $v;
    }

    /** Nhận mã hoặc nhãn (không phân biệt hoa thường), trả mã chuẩn. @param array<string,string> $codeToLabel */
    private function pick(Column $c, string $v, array $codeToLabel): string
    {
        $needle = mb_strtolower(trim($v));
        foreach ($codeToLabel as $code => $label) {
            if (mb_strtolower((string) $code) === $needle || mb_strtolower($label) === $needle) {
                return (string) $code;
            }
        }
        throw new ImportError("\"{$c->exportHeader()}\" chỉ nhận: ".implode(' / ', $codeToLabel));
    }

    /** Vòng tuyển: mã hoặc nhãn, không phân biệt hoa thường (app cũ: cellIn col.stage). */
    private function stage(Column $c, string $v): string
    {
        $needle = mb_strtolower(trim($v));
        foreach ($c->options as $key => $label) {
            if (mb_strtolower((string) $key) === $needle || mb_strtolower($label) === $needle) {
                return (string) $key;
            }
        }
        throw new ImportError("vòng \"{$v}\" không hợp lệ");
    }

    private function blank(array $cells): bool
    {
        foreach ($cells as $cell) {
            $v = is_array($cell) ? ($cell['v'] ?? null) : $cell;
            if ($v !== null && trim((string) $v) !== '') {
                return false;
            }
        }

        return true;
    }

    private function existingKeys(): array
    {
        if (! $this->sheet->importable()) {
            return [];
        }
        $model = $this->sheet->model();
        $keys = [];
        foreach ($model::query()->get() as $m) {
            if (($k = $this->sheet->keyOfModel($m)) !== null) {
                $keys[$k] = true;
            }
        }

        return $keys;
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Vòng tuyển dụng (cột kanban). Khoá là mã chữ (new, phone, …), không phải uuid. */
class Stage extends Model
{
    protected $primaryKey = 'key';

    protected $keyType = 'string';

    public $incrementing = false;

    public $timestamps = false;

    protected $fillable = ['key', 'label', 'sort_order'];

    protected function casts(): array
    {
        return ['sort_order' => 'integer'];
    }

    public static function ordered()
    {
        return static::orderBy('sort_order')->orderBy('key')->get();
    }
}

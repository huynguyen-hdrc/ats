<?php

namespace App\Http\Requests;

use App\Models\JobOpening;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class JobRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // quyền đã kiểm ở middleware perm:jobs,*
    }

    protected function prepareForValidation(): void
    {
        // Chuỗi rỗng từ form = không có giá trị.
        $this->merge(collect($this->all())->map(fn ($v) => is_string($v) && trim($v) === '' ? null : $v)->all());
    }

    public function rules(): array
    {
        $req = $this->isMethod('post') ? 'required' : 'sometimes|required';

        return [
            'title' => [$req, 'string', 'max:255'],
            'department' => ['nullable', 'string', 'max:255'],
            'headcount' => ['nullable', 'integer', 'min:0', 'max:10000'],
            'location' => ['nullable', 'string', 'max:255'],
            'employment_type' => ['nullable', Rule::in(JobOpening::EMPLOYMENT_TYPES)],
            'hiring_manager' => ['nullable', 'string', 'max:255'],
            'salary_range' => ['nullable', 'string', 'max:255'],
            'target_date' => ['nullable', 'date_format:Y-m-d'],
            'is_open' => ['sometimes', 'boolean'],
            'jd_summary' => ['nullable', 'string', 'max:20000'],
            'jd_responsibilities' => ['nullable', 'string', 'max:20000'],
            'jd_requirements' => ['nullable', 'string', 'max:20000'],
            'jd_benefits' => ['nullable', 'string', 'max:20000'],
        ];
    }

    public function attributes(): array
    {
        return [
            'title' => 'tên vị trí', 'department' => 'phòng ban', 'headcount' => 'số lượng cần tuyển',
            'location' => 'nơi làm việc', 'employment_type' => 'loại hình', 'target_date' => 'hạn tuyển xong',
        ];
    }
}

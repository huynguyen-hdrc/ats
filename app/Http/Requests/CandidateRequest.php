<?php

namespace App\Http\Requests;

use App\Models\Candidate;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CandidateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // quyền đã kiểm ở middleware perm:candidates,*
    }

    protected function prepareForValidation(): void
    {
        // Chuỗi rỗng từ form = không có giá trị.
        $data = collect($this->all())->map(fn ($v) => is_string($v) && trim($v) === '' ? null : $v)->all();
        if (is_array($data['skills'] ?? null)) {
            $data['skills'] = array_values(array_filter(array_map(fn ($s) => is_string($s) ? trim($s) : $s, $data['skills']),
                fn ($s) => $s !== '' && $s !== null));
        }
        // App cũ cắt nội dung CV còn 20 000 ký tự trước khi lưu.
        if (is_string($data['cv_text'] ?? null)) {
            $data['cv_text'] = mb_substr($data['cv_text'], 0, Candidate::CV_TEXT_MAX);
        }
        $this->merge($data);
    }

    public function rules(): array
    {
        $create = $this->isMethod('post');

        return [
            'full_name' => [$create ? 'required' : 'sometimes|required', 'string', 'max:255'],
            'email' => ['nullable', 'string', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'current_title' => ['nullable', 'string', 'max:255'],
            'current_company' => ['nullable', 'string', 'max:255'],
            'years_experience' => ['nullable', 'numeric', 'min:0', 'max:99'],
            'source' => ['nullable', 'string', 'max:255'],
            'job_id' => ['nullable', 'uuid', Rule::exists('jobs', 'id')],
            'stage' => ['sometimes', 'required', 'string', Rule::exists('stages', 'key')],
            'status' => ['sometimes', 'required', Rule::in(Candidate::STATUSES)],
            'skills' => ['nullable', 'array', 'max:100'],
            'skills.*' => ['string', 'max:100'],
            'cv_text' => $create ? ['nullable', 'string'] : ['prohibited'],
        ];
    }

    public function attributes(): array
    {
        return [
            'full_name' => 'họ tên', 'email' => 'email', 'phone' => 'điện thoại',
            'current_title' => 'chức danh hiện tại', 'current_company' => 'công ty hiện tại',
            'years_experience' => 'số năm kinh nghiệm', 'source' => 'nguồn', 'job_id' => 'vị trí ứng tuyển',
            'stage' => 'vòng', 'status' => 'trạng thái', 'skills' => 'kỹ năng', 'skills.*' => 'kỹ năng',
            'cv_text' => 'nội dung CV',
        ];
    }
}

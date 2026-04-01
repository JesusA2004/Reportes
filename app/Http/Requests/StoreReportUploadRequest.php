<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreReportUploadRequest extends FormRequest {

    public function authorize(): bool {
        return true;
    }

    public function rules(): array {
        return [
            'period_id' => ['required', 'exists:periods,id'],
            'data_source_id' => ['required', 'exists:data_sources,id'],
            'file' => [
                'required',
                'file',
                'mimes:xls,xlsx,xlsm',
                'max:20480',
            ],
            'notes' => ['nullable', 'string'],
        ];
    }

}

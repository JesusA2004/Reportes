<?php

namespace App\Http\Requests;

use App\Models\Period;
use Illuminate\Foundation\Http\FormRequest;

class StoreReportUploadRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'period_id' => [
                'required',
                'exists:periods,id',
            ],
            'covered_period_ids' => [
                'required',
                'array',
                'min:1',
            ],
            'covered_period_ids.*' => [
                'integer',
                'exists:periods,id',
            ],
            'data_source_id' => [
                'required',
                'exists:data_sources,id',
            ],
            'file' => [
                'required',
                'file',
                'mimes:xls,xlsx,xlsm',
                'max:20480',
            ],
            'notes' => [
                'nullable',
                'string',
            ],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $anchorPeriod = Period::query()->find($this->integer('period_id'));

            if (!$anchorPeriod) {
                return;
            }

            if ($anchorPeriod->type !== 'weekly') {
                $validator->errors()->add('period_id', 'Solo se pueden subir archivos sobre periodos semanales.');
                return;
            }

            $coveredIds = collect($this->input('covered_period_ids', []))
                ->map(fn ($id) => (int) $id)
                ->unique()
                ->values();

            $coveredPeriods = Period::query()
                ->whereIn('id', $coveredIds)
                ->get();

            if ($coveredPeriods->isEmpty()) {
                $validator->errors()->add('covered_period_ids', 'Debes seleccionar al menos una semana.');
                return;
            }

            $invalidType = $coveredPeriods->first(fn ($period) => $period->type !== 'weekly');

            if ($invalidType) {
                $validator->errors()->add('covered_period_ids', 'Solo se pueden asociar semanas.');
            }

            $invalidMonth = $coveredPeriods->first(function ($period) use ($anchorPeriod) {
                return (int) $period->year !== (int) $anchorPeriod->year
                    || (int) $period->month !== (int) $anchorPeriod->month;
            });

            if ($invalidMonth) {
                $validator->errors()->add('covered_period_ids', 'Las semanas seleccionadas deben pertenecer al mismo mes del periodo base.');
            }
        });
    }

    public function messages(): array
    {
        return [
            'period_id.required' => 'Debes seleccionar un periodo semanal.',
            'covered_period_ids.required' => 'Debes seleccionar las semanas que cubre el archivo.',
            'covered_period_ids.array' => 'Las semanas seleccionadas no son válidas.',
            'covered_period_ids.min' => 'Selecciona al menos una semana.',
            'data_source_id.required' => 'Debes seleccionar una fuente.',
            'file.required' => 'Debes seleccionar un archivo.',
            'file.mimes' => 'El archivo debe ser xls, xlsx o xlsm.',
        ];
    }
}

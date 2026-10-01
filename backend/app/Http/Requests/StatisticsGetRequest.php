<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StatisticsGetRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * contragent и link — массивы id (можно несколько, фильтр по объединению).
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'time'         => 'required|integer|min:0',
            'contragent'   => 'nullable|array',
            'contragent.*' => 'integer',
            'link'         => 'nullable|array',
            'link.*'       => 'integer',
            'bot'          => 'required|integer|exists:bots,id',
        ];
    }

    public function messages(): array
    {
        return [];
    }
}

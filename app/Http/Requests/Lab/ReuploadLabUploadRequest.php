<?php

namespace App\Http\Requests\Lab;

use App\Rules\FileUniqueByHash;
use Illuminate\Foundation\Http\FormRequest;

class ReuploadLabUploadRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'file' => [
                'required',
                'file',
                'max:'.StoreLabUploadRequest::MAX_FILE_KILOBYTES,
                'mimes:'.implode(',', StoreLabUploadRequest::FILE_EXTENSIONS),
                new FileUniqueByHash,
            ],
        ];
    }
}

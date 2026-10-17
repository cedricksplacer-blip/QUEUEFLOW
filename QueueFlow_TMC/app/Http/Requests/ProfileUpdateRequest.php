<?php

namespace App\Http\Requests;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ProfileUpdateRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\Rule|array|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'lowercase', 'email', 'max:255', Rule::unique(User::class)->ignore($this->user()->id)],
            // Optional, so the shared profile form that omits it is unaffected:
            // validated() only returns keys actually present in the request.
            'phone' => ['nullable', 'string', 'max:30'],
            // Handled in ProfileController, never mass-assigned. A bare "file"
            // rule matches the house style: the real MIME, size and image
            // checks live in AvatarStore.
            'avatar' => ['nullable', 'file'],
            'remove_avatar' => ['nullable', 'boolean'],
        ];
    }
}

<?php

namespace Pine\Commerce\Http\Requests\Admin\Content;

use Pine\Commerce\Models\User;
use Pine\Commerce\Services\Admin\StaffPassword;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Create/update a staff account (administrators only). Guards: you can't change your own role or switch yourself off,
 * and the last active administrator can't be demoted or switched off.
 */
class StaffRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->isAdmin();
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'email' => is_string($this->input('email')) ? mb_strtolower(trim($this->input('email'))) : $this->input('email'),
            'first_name' => is_string($this->input('first_name')) ? trim($this->input('first_name')) : $this->input('first_name'),
            'last_name' => is_string($this->input('last_name')) ? trim($this->input('last_name')) : $this->input('last_name'),
        ]);
    }

    public function rules(): array
    {
        $staff = $this->route('staff');
        $creating = ! $staff instanceof User;

        return [
            'first_name' => ['required', 'string', 'max:100'],
            'last_name' => ['nullable', 'string', 'max:100'],
            'email' => ['required', 'string', 'email:rfc', 'max:190', Rule::unique('users', 'email')->ignore($creating ? null : $staff->id)],
            'phone' => ['nullable', 'string', 'max:40'],
            'role' => ['required', Rule::in(['admin', 'manager', 'customer'])],
            'is_active' => ['boolean'],
            'password_mode' => $creating ? ['required', Rule::in(['invite', 'set'])] : ['nullable'],
            'password' => $creating && $this->input('password_mode') === 'set'
                ? ['required', 'string', 'max:255', 'confirmed', StaffPassword::rule()]
                : ['nullable'],
        ];
    }

    public function messages(): array
    {
        return [
            'email.unique' => 'An account with this email already exists (it may be a customer account). Use a different email address.',
        ];
    }

    public function attributes(): array
    {
        return ['first_name' => 'first name', 'password_mode' => 'password option'];
    }

    public function after(): array
    {
        return [
            function (Validator $validator) {
                $staff = $this->route('staff');
                if (! $staff instanceof User) {
                    if ($this->input('role') === 'customer') {
                        $validator->errors()->add('role', 'Choose Administrator or Shop manager.');
                    }

                    return;
                }
                $me = $this->user();
                $role = $this->input('role');
                $active = $this->boolean('is_active');
                if ($staff->is($me) && $role !== $staff->role) {
                    $validator->errors()->add('role', 'You can’t change your own role. Ask another administrator.');
                }
                if ($staff->is($me) && ! $active) {
                    $validator->errors()->add('is_active', 'You can’t switch off your own account.');
                }
                $removingAdmin = $staff->role === 'admin' && $staff->is_active && ($role !== 'admin' || ! $active);
                if ($removingAdmin && static::activeAdmins($staff->id) === 0) {
                    $validator->errors()->add($role !== 'admin' ? 'role' : 'is_active', 'This is the only active administrator – make someone else an administrator first.');
                }
            },
        ];
    }

    public static function activeAdmins(?int $exceptId = null): int
    {
        return User::query()->where('role', 'admin')->where('is_active', true)->when($exceptId, fn ($q) => $q->whereKeyNot($exceptId))->count();
    }

    public function staffData(): array
    {
        $first = $this->input('first_name');
        $last = $this->input('last_name');

        return [
            'first_name' => $first,
            'last_name' => $last ?: null,
            'name' => trim($first.' '.$last),
            'email' => $this->input('email'),
            'phone' => $this->filled('phone') ? trim($this->input('phone')) : null,
            'role' => $this->input('role'),
            'is_active' => $this->boolean('is_active'),
        ];
    }
}

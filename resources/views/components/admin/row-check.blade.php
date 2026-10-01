{{-- Row selection checkbox cell for <x-admin.table selectable>. <x-admin.row-check :id="$row->id" :label="'Select '.$row->name" /> --}}
@props(['id', 'label' => null])
<td class="table__check" @click.stop>
    <input type="checkbox" class="checkbox" :checked="isSelected(@js((string) $id))" @click="toggle(@js((string) $id), $event)" aria-label="{{ $label ?? 'Select row' }}">
</td>

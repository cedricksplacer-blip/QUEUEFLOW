{{--
    A boolean field presented as a switch.

    The real checkbox is kept in the DOM so the form still submits a plain
    name/value pair, and the wrapping <label> makes the whole row clickable
    rather than just the 40px track. Both state labels are rendered and CSS
    reveals the correct one, so the text is right before and after a save
    with no scripting involved.

    Unchecked, the browser submits no value for the field at all. That is
    intentional: the controllers read it with $request->boolean(), which
    treats an absent key as false, so switching off actually persists.

    Usage:
        <x-toggle name="is_active" :checked="$member->is_active" label="Account is active" />
--}}
@props([
    'name',
    'checked' => false,
    'label' => '',
    'on' => 'Active',
    'off' => 'Inactive',
])

<label {{ $attributes->class('toggle') }}>
    <input type="checkbox" name="{{ $name }}" value="1" @checked($checked)>
    <span class="toggle-track" aria-hidden="true"></span>
    <span class="toggle-text">
        <span class="toggle-title">{{ $label }}</span>
        <span class="toggle-state">
            <span class="toggle-on">{{ $on }}</span>
            <span class="toggle-off">{{ $off }}</span>
        </span>
    </span>
</label>

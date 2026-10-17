{{--
    Renders the inside of an avatar circle: the user's uploaded photo when one
    exists, otherwise their first initial.

    The caller supplies the surrounding element so each layout keeps its own
    size, colour and shape, e.g.:

        <div class="user-avatar"><x-avatar :user="$user" /></div>
--}}
@props(['user'])

@if($user->hasAvatar())
    <img class="avatar-img" src="{{ $user->avatarUrl() }}" alt="{{ $user->name }}" loading="lazy">
@else
    {{ strtoupper(substr($user->name, 0, 1)) }}
@endif

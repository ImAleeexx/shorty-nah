@extends('redirect.layout', ['title' => 'Password required'])

@section('content')
    <h1>This link is protected</h1>
    <p>Enter the password to continue.</p>

    @if ($tooManyAttempts)
        <p class="notice">Too many attempts. Try again in {{ $retryAfter }} seconds.</p>
    @elseif ($incorrect)
        <p class="notice">That password is incorrect.</p>
    @endif

    {{-- The form posts back to the same slug. Nothing on this page names the
         destination, and no CSRF token is required because the redirect path
         runs without a session. --}}
    <form method="POST" action="/{{ $slug }}">
        <label for="password">Password</label>
        <input id="password" name="password" type="password" autocomplete="off" autofocus required>
        <button type="submit">Continue</button>
    </form>
@endsection

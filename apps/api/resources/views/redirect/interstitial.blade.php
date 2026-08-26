@extends('redirect.layout', ['title' => 'Redirecting'])

@section('head')
    <meta http-equiv="refresh" content="1;url={{ $destination }}">
@endsection

@section('content')
    {{-- The branded hold page and its measurement beacon arrive in the next
         phase. This is the scripting-free fallback that page keeps regardless,
         so the destination is reachable either way. --}}
    <h1>Taking you there</h1>
    <p>If nothing happens, use the link below.</p>
    <p><a class="muted-link" href="{{ $destination }}" rel="noopener">{{ $destination }}</a></p>
@endsection

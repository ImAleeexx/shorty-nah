@extends('redirect.layout', ['title' => 'Link unavailable'])

@section('content')
    {{-- One page for every unavailable reason. A visitor learns only that there
         is nothing here, so the redirect path cannot be used to discover which
         slugs exist. --}}
    <h1>This link isn’t available</h1>
    <p>It may have been removed, or the address may be incorrect.</p>
@endsection

@extends('redirect.layout', ['title' => 'Domain not set up'])

@section('content')
    {{-- Any host this instance does not serve: never registered, or registered
         and not yet verified. The two are not told apart — a visitor learns only
         that nothing is served here, and the operator who can act on it already
         knows which it is. --}}
    <h1>This domain isn’t set up</h1>
    <p><code>{{ $host }}</code> points at a link service that isn’t serving it yet. If you manage this domain, register it in the service’s settings and publish its verification record.</p>
@endsection

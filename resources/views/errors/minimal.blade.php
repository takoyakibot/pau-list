@extends('layouts.app')

@section('content')
    <div class="flex items-center">
        <h1 class="border-r border-gray-400 pr-4 text-lg">@yield('code')</h1>
        <p class="pl-4 text-lg">@yield('message')</p>
    </div>
@endsection

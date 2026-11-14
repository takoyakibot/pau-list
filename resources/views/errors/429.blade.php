@extends('errors.minimal')

@section('error_title', 'アクセスが集中しています')
@section('code', '429')
@section('message', 'アクセスが集中しています。しばらく待ってから再度お試しください')

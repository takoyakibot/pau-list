@extends('errors.minimal')

@section('title', 'エラーが発生しました')
@section('code', (string) (isset($exception) && method_exists($exception, 'getStatusCode') ? $exception->getStatusCode() : ''))
@section('message', 'エラーが発生しました。しばらく待ってから再度お試しください')

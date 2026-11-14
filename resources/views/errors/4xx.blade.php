@extends('errors.minimal')

@section('title', 'リクエストを処理できません')
@section('code', (string) (isset($exception) && method_exists($exception, 'getStatusCode') ? $exception->getStatusCode() : ''))
@section('message', 'リクエストを処理できません。内容を確認して、もう一度お試しください')

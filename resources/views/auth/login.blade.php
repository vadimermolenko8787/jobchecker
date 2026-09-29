@extends('layouts.app')

@section('title', __('Sign in') . ' · JobChecker')

@section('content')
    <div class="login">
        <div class="brand">
            <span class="brand-mark" aria-hidden="true">
                <svg viewBox="0 0 24 24" width="17" height="17" fill="none" stroke="currentColor" stroke-width="2.1" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="7"/><path d="m21 21-4.3-4.3"/></svg>
            </span>
            <span class="brand-name">Job<b>Checker</b></span>
        </div>

        <div class="card">
            <div class="card-head"><h3>{{ __('Sign in') }}</h3></div>
            <form method="post" action="{{ route('login.store') }}" class="card-body" style="display:flex;flex-direction:column;gap:14px">
                @csrf
                <label class="field">
                    <span class="lab">{{ __('Email') }}</span>
                    <input type="email" name="email" value="{{ old('email') }}" required autofocus autocomplete="username">
                </label>
                <label class="field">
                    <span class="lab">{{ __('Password') }}</span>
                    <input type="password" name="password" required autocomplete="current-password">
                </label>
                <label class="check"><input type="checkbox" name="remember" value="1"> {{ __('Remember me') }}</label>
                <button type="submit" class="btn btn-primary btn-block">{{ __('Sign in') }}</button>
            </form>
        </div>
    </div>
@endsection

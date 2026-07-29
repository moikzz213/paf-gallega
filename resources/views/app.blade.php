<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ config('app.name', 'PAF') }} — Invoice Payment Approval Platform</title>
     <link rel="icon" href="{{ asset('/assets/images/icon.png') }}" sizes="32x32">
    @vite('resources/js/main.js')
</head>
<body>
    <div id="app"></div>
</body>
</html>

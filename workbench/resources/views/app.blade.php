<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>LaraGraph Workbench</title>
    <meta name="csrf-token" content="{{ csrf_token() }}" />
    @viteReactRefresh
    @vite(['resources/js/app.tsx', 'resources/css/app.css'])
    @inertiaHead
</head>
<body class="bg-gray-950 text-gray-100">
    @inertia
</body>
</html>

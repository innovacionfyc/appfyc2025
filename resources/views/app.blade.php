<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Rounded" rel="stylesheet" />
  <script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js"></script>
  <title inertia>{{ config('app.name', 'F&C Consultores') }}</title>
    @vite('resources/js/app.js')
      @livewireStyles
  <link rel="shortcut icon" href="{{ Vite::asset('resources/images/icon_white.png') }}" type="image/x-icon">


  @routes
  @vite(['resources/css/app.css', 'resources/js/app.js'])
  <meta name="csrf-token" content="{{ csrf_token() }}">
</head>
<body class="scrollbar-custom bg-mono-blanco dark:bg-mono-negro">
  @inertia

  @livewireScripts
  
</body>
</html>

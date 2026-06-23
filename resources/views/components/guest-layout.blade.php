<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>{{ $title ?? config('app.name') }}</title>
@include('partials.theme')
</head>
<body class="font-sans">
{{ $slot }}
</body>
</html>

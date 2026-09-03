<meta name="reverb-app-key" content="{{ config('reverb.apps.apps.0.key') }}">
<meta name="reverb-host" content="{{ config('reverb.apps.apps.0.options.host') }}">
<meta name="reverb-port" content="{{ config('reverb.apps.apps.0.options.port') }}">
<meta name="reverb-scheme" content="{{ config('reverb.apps.apps.0.options.scheme') }}">
@vite(['resources/js/echo.js', 'packages/Chat/resources/js/chat.js'])

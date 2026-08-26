<meta charset="utf-8" />
<meta name="viewport" content="width=device-width, initial-scale=1.0" />
<meta name="reverb-enabled" content="{{ config('broadcasting.default') === 'reverb' ? '1' : '0' }}" />
<meta name="reverb-app-key" content="{{ config('broadcasting.connections.reverb.key') }}" />
<meta name="reverb-public-port" content="{{ config('phpwiki.reverb.public_port') }}" />

<title>
    {{ filled($title ?? null) ? $title.' - '.config('app.name', 'Laravel') : config('app.name', 'Laravel') }}
</title>

<link rel="icon" href="/favicon.ico" sizes="any">
<link rel="icon" href="/favicon.svg" type="image/svg+xml">
<link rel="apple-touch-icon" href="/apple-touch-icon.png">

@fonts

@vite(['resources/css/app.css', 'resources/js/app.js'])
@fluxAppearance

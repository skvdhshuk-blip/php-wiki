@props(['markdown'])

<div {{ $attributes->merge(['class' => 'text-sm leading-7 [&_a]:text-indigo-600 [&_a]:underline dark:[&_a]:text-indigo-300']) }}>
    {!! app(\App\Services\Wiki\WikiMarkdownRenderer::class)->render((string) $markdown) !!}
</div>

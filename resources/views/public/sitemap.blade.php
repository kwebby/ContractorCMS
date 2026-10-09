{!! '<?xml version="1.0" encoding="UTF-8"?>' !!}
{{-- Author: ramanpal singh | URL: https://kwebby.com --}}
<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
@foreach ($entries as $entry)
    <url><loc>{{ $entry['url'] }}</loc>@if ($entry['modified'])<lastmod>{{ $entry['modified'] }}</lastmod>@endif</url>
@endforeach
</urlset>

{{-- Author: ramanpal singh | URL: https://kwebby.com --}}
<div class="home-sections">
    @foreach ($sections as $section)
        @if ($section['type'] === 'contact')
            <div id="home-{{ $section['id'] }}" class="home-contact-wrap">{!! $section['contact_html'] !!}</div>
        @else
            <section id="home-{{ $section['id'] }}" class="home-section home-section-{{ $section['type'] }} home-variant-{{ $section['variant'] }}{{ $section['image'] ? ' home-has-image' : '' }}" @if (!empty($section['heading'])) aria-labelledby="home-heading-{{ $section['id'] }}" @endif>
                <div class="home-section-intro">
                    @if (!empty($section['eyebrow']))<span class="eyebrow">{{ $section['eyebrow'] }}</span>@endif
                    @if (!empty($section['heading']))
                        @if ($section['type'] === 'hero')<h1 id="home-heading-{{ $section['id'] }}">{{ $section['heading'] }}</h1>
                        @else<h2 id="home-heading-{{ $section['id'] }}">{{ $section['heading'] }}</h2>@endif
                    @endif
                    @if ($section['body_html'])<div class="home-section-body prose">{!! $section['body_html'] !!}</div>@endif
                    @if ($section['buttons'])
                        <div class="home-actions">
                            @foreach ($section['buttons'] as $button)
                                <a href="{{ $button['href'] }}" class="home-button home-button-{{ $button['style'] }}">{{ $button['label'] }}<span aria-hidden="true"> ↗</span></a>
                            @endforeach
                        </div>
                    @endif
                    @if ($section['tool'])
                        <a class="home-button home-button-primary" href="{{ is_array($section['tool']) ? $section['tool']['url'] : $section['tool'] }}">{{ is_array($section['tool']) ? ($section['tool']['label'] ?? 'Open planning tool') : 'Open planning tool' }} <span aria-hidden="true">↗</span></a>
                    @endif
                </div>
                @if ($section['image'])
                    <figure class="home-section-image">
                        <img src="{{ $section['image']['url'] }}" alt="{{ $section['image']['alt'] }}" class="home-focal-x-{{ $section['image']['position_x'] }} home-focal-y-{{ $section['image']['position_y'] }}" loading="{{ $section['type'] === 'hero' ? 'eager' : 'lazy' }}" @if ($section['type'] === 'hero') fetchpriority="high" @endif decoding="async">
                    </figure>
                @endif
                @if ($section['items'])
                    @if ($section['type'] === 'faq')
                        <div class="home-faq-list">
                            @foreach ($section['items'] as $item)
                                <details><summary>{{ $item['question'] }}<span aria-hidden="true">+</span></summary><p>{{ $item['answer'] }}</p></details>
                            @endforeach
                        </div>
                    @elseif ($section['type'] === 'reviews')
                        <div class="home-item-grid">
                            @foreach ($section['items'] as $item)
                                <figure class="home-review"><blockquote><p>{{ $item['text'] }}</p></blockquote><figcaption><strong>{{ $item['author'] }}</strong>@if (!empty($item['source']))<span>@if (!empty($item['url']))<a href="{{ $item['url'] }}" rel="noopener noreferrer">{{ $item['source'] }}</a>@else{{ $item['source'] }}@endif</span>@endif @if (!empty($item['date']))<time datetime="{{ $item['date'] }}">{{ $item['date'] }}</time>@endif</figcaption></figure>
                            @endforeach
                        </div>
                    @elseif ($section['type'] === 'process')
                        <ol class="home-process-list">
                            @foreach ($section['items'] as $item)
                                <li>@if ($item['image'])<figure class="home-step-image"><img src="{{ $item['image']['url'] }}" alt="{{ $item['image']['alt'] }}" class="home-focal-x-{{ $item['image']['position_x'] }} home-focal-y-{{ $item['image']['position_y'] }}" loading="lazy" decoding="async"></figure>@endif<span class="home-step-number" aria-hidden="true">{{ str_pad($loop->iteration, 2, '0', STR_PAD_LEFT) }}</span><div><h3>{{ $item['title'] }}</h3>@if ($item['text'])<p>{{ $item['text'] }}</p>@endif @if ($item['href'])<a href="{{ $item['href'] }}">Learn more <span aria-hidden="true">↗</span></a>@endif</div></li>
                            @endforeach
                        </ol>
                    @else
                        <div class="home-item-grid">
                            @foreach ($section['items'] as $item)
                                <article class="home-item-card">
                                    @if ($item['image'])<figure><img src="{{ $item['image']['url'] }}" alt="{{ $item['image']['alt'] }}" class="home-focal-x-{{ $item['image']['position_x'] }} home-focal-y-{{ $item['image']['position_y'] }}" loading="lazy" decoding="async"></figure>@endif
                                    <div class="home-card-copy">
                                        @if (!empty($item['location']))<span class="eyebrow">{{ $item['location'] }}</span>@endif
                                        <h3>@if ($item['href'])<a href="{{ $item['href'] }}">{{ $item['title'] }} <span aria-hidden="true">↗</span></a>@else{{ $item['title'] }}@endif</h3>
                                        @if ($item['text'])<p>{{ $item['text'] }}</p>@endif
                                        @if (!empty($item['terms']))<p class="home-small">{{ $item['terms'] }}</p>@endif
                                        @if (!empty($item['ends_at']))<p class="home-small">Available through <time datetime="{{ $item['ends_at'] }}">{{ $item['ends_at'] }}</time>.</p>@endif
                                    </div>
                                </article>
                            @endforeach
                        </div>
                    @endif
                @endif
            </section>
        @endif
    @endforeach
</div>

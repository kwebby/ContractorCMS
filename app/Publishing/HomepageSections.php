<?php

/**
 * Author: ramanpal singh
 * URL: https://kwebby.com
 */

namespace App\Publishing;

use App\Automation\LeadToolService;
use App\Contracts\RecordRepository;
use App\Support\Access;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class HomepageSections
{
    public const TYPES = ['hero', 'trust', 'services', 'about', 'process', 'projects', 'reviews', 'service_areas', 'faq', 'contact', 'offers', 'financing', 'maintenance', 'articles', 'lead_tool', 'careers'];

    private const CARD_FIELDS = ['title', 'text', 'image', 'page_id', 'url'];

    public function __construct(private readonly RecordRepository $records) {}

    /** @return array<string, mixed> */
    public function validate(array $document, bool $includeDraft = true): array
    {
        if (strlen(json_encode($document, JSON_THROW_ON_ERROR)) > 250_000) {
            $this->fail('home_sections', 'Homepage sections must be smaller than 250 KB.');
        }
        $data = Validator::make(['home_sections' => $document], [
            'home_sections' => ['required', 'array:schema_version,mode,sections'],
            'home_sections.schema_version' => ['required', 'integer', Rule::in([1])],
            'home_sections.mode' => ['required', Rule::in(['guided', 'expert'])],
            'home_sections.sections' => ['present', 'array', 'list', 'max:24'],
            'home_sections.sections.*' => ['required', 'array:id,type,enabled,variant,eyebrow,heading,body,image,buttons,items,tool_key'],
            'home_sections.sections.*.id' => ['required', 'string', 'regex:/^[a-z][a-z0-9-]{0,47}$/D', 'distinct:strict'],
            'home_sections.sections.*.type' => ['required', Rule::in(self::TYPES)],
            'home_sections.sections.*.enabled' => ['required', 'boolean'],
            'home_sections.sections.*.variant' => ['required', Rule::in(['standard', 'split', 'cards'])],
            'home_sections.sections.*.eyebrow' => ['sometimes', 'nullable', 'string', 'max:80'],
            'home_sections.sections.*.heading' => ['sometimes', 'nullable', 'string', 'max:180'],
            'home_sections.sections.*.body' => ['sometimes', 'array', 'list'],
            'home_sections.sections.*.image' => ['sometimes', 'nullable', 'array'],
            'home_sections.sections.*.buttons' => ['sometimes', 'array', 'list', 'max:2'],
            'home_sections.sections.*.items' => ['sometimes', 'array', 'list', 'max:12'],
            'home_sections.sections.*.tool_key' => ['sometimes', 'nullable', 'string', Rule::in(array_keys(LeadToolService::TOOLS))],
        ])->validate()['home_sections'];
        $data['schema_version'] = 1;
        $heroes = 0;
        $contacts = 0;
        $ids = array_column($data['sections'], 'id');
        foreach ($data['sections'] as $index => &$section) {
            $path = 'home_sections.sections.'.$index;
            $section['enabled'] = (bool) $section['enabled'];
            if ($section['enabled'] && $section['type'] === 'hero') {
                $heroes++;
                if (trim($section['heading'] ?? '') === '') {
                    $this->fail($path.'.heading', 'Add the homepage heading.');
                }
            }
            if ($section['enabled'] && $section['type'] === 'contact' && ++$contacts > 1) {
                $this->fail($path.'.type', 'Use one contact section so the request form appears once.');
            }
            if (array_key_exists('tool_key', $section) && $section['type'] !== 'lead_tool') {
                $this->fail($path.'.tool_key', 'Only a lead tool section can reference a tool.');
            }
            $section += ['body' => [], 'buttons' => [], 'items' => []];
            $this->validateBody($section['body'], $includeDraft, $path.'.body');
            if (! empty($section['image'])) {
                $section['image'] = $this->validateImage($section['image'], $includeDraft, $path.'.image');
            }
            foreach ($section['buttons'] as $buttonIndex => &$button) {
                $button = $this->validateButton($button, $includeDraft, $ids, $path.'.buttons.'.$buttonIndex);
            }
            unset($button);
            if (in_array($section['type'], ['hero', 'contact', 'lead_tool'], true) && $section['items'] !== []) {
                $this->fail($path.'.items', 'This section does not support repeated items.');
            }
            if ($section['type'] === 'faq' && count($section['items']) > 8) {
                $this->fail($path.'.items', 'Use at most eight frequently asked questions.');
            }
            foreach ($section['items'] as $itemIndex => &$item) {
                $item = $this->validateItem($item, $section['type'], $includeDraft, $path.'.items.'.$itemIndex);
            }
            unset($item);
        }
        unset($section);
        if ($data['mode'] === 'guided' && $heroes !== 1) {
            $this->fail('home_sections.sections', 'A guided homepage needs exactly one enabled hero section.');
        }

        return $data;
    }

    /** Validate published destinations while permitting draft images in the same atomic publication. */
    public function validateForPublication(array $document): void
    {
        $data = $this->validate($document);
        if ($data['mode'] !== 'guided') {
            return;
        }
        $enabledIds = array_column(array_filter($data['sections'], fn (array $section): bool => $section['enabled']), 'id');
        $hasContact = (bool) array_filter($data['sections'], fn (array $section): bool => $section['enabled'] && $section['type'] === 'contact');
        foreach ($data['sections'] as $index => $section) {
            if (! $section['enabled']) {
                continue;
            }
            foreach ($section['buttons'] as $buttonIndex => $button) {
                $path = 'home_sections.sections.'.$index.'.buttons.'.$buttonIndex;
                if ($button['kind'] === 'page') {
                    $this->validatePage($button['page_id'], false, $path.'.page_id');
                }
                if ($button['kind'] === 'anchor' && (($button['anchor'] === 'contact' && ! $hasContact) || ($button['anchor'] !== 'contact' && ! in_array($button['anchor'], $enabledIds, true)))) {
                    $this->fail($path.'.anchor', 'Enable the linked section before publishing.');
                }
            }
            foreach ($section['items'] as $itemIndex => $item) {
                $path = 'home_sections.sections.'.$index.'.items.'.$itemIndex;
                if (! empty($item['page_id'])) {
                    $this->validatePage($item['page_id'], false, $path.'.page_id', $this->pageTypes($section['type']));
                }
                if ($section['type'] === 'reviews' && ! $item['permission_confirmed']) {
                    $this->fail($path.'.permission_confirmed', 'Confirm permission and provenance before publishing this testimonial.');
                }
            }
        }
    }

    /** @return list<string> */
    public function mediaIds(array $document): array
    {
        $ids = [];
        $collect = function (array $node) use (&$collect, &$ids): void {
            if (($node['type'] ?? '') === 'image') {
                if (is_string($node['props']['mediaId'] ?? null)) {
                    $ids[] = $node['props']['mediaId'];
                } elseif (preg_match('~^/files/([a-zA-Z0-9_-]+)$~D', $node['props']['url'] ?? '', $match)) {
                    $ids[] = $match[1];
                }
            }
            foreach ($node as $child) {
                if (is_array($child)) {
                    $collect($child);
                }
            }
        };
        if (($document['mode'] ?? '') === 'guided') {
            foreach ($document['sections'] ?? [] as $section) {
                if (! empty($section['enabled'])) {
                    if (! empty($section['image']['media_id'])) {
                        $ids[] = $section['image']['media_id'];
                    }
                    foreach ($section['items'] ?? [] as $item) {
                        if (! empty($item['image']['media_id'])) {
                            $ids[] = $item['image']['media_id'];
                        }
                    }
                    $collect($section['body'] ?? []);
                }
            }
        }

        return array_values(array_unique($ids));
    }

    /** @param array{preview?: bool, business?: array, pages?: array, contactWidget?: string, toolLinks?: array} $context */
    public function render(array $document, array $context = []): string
    {
        if (($document['mode'] ?? '') !== 'guided') {
            return '';
        }
        $preview = ($context['preview'] ?? false) && Access::can(auth()->user(), 'publishing.manage');
        $sections = [];
        $today = now($context['business']['timezone'] ?? config('app.timezone'))->toDateString();
        $contactRendered = false;
        foreach ($document['sections'] ?? [] as $section) {
            if (empty($section['enabled']) || ! in_array($section['type'] ?? '', self::TYPES, true)) {
                continue;
            }
            $section['body_html'] = $this->renderer($preview)->render($this->availableBody($section['body'] ?? [], $preview));
            $section['image'] = $this->renderImage($section['image'] ?? null, $preview);
            $section['buttons'] = array_values(array_filter(array_map(function (array $button) use ($context, $preview): ?array {
                $href = $this->buttonHref($button, $context, $preview);

                return $href ? $button + ['href' => $href] : null;
            }, $section['buttons'] ?? [])));
            $items = [];
            foreach ($section['items'] ?? [] as $item) {
                if ($section['type'] === 'offers' && (! empty($item['starts_at']) && $item['starts_at'] > $today || ! empty($item['ends_at']) && $item['ends_at'] < $today)) {
                    continue;
                }
                if ($section['type'] === 'reviews' && empty($item['permission_confirmed'])) {
                    continue;
                }
                $page = ! empty($item['page_id']) ? $this->resolvePage($item['page_id'], $preview) : null;
                if (! empty($item['page_id']) && ! $page) {
                    continue;
                }
                $item['title'] = ($item['title'] ?? '') ?: ($page['title'] ?? '');
                $item['text'] = ($item['text'] ?? '') ?: ($page['excerpt'] ?? '');
                $item['href'] = $page ? $this->pageUrl($page, $preview) : ($item['url'] ?? null);
                $item['image'] = $this->renderImage($item['image'] ?? null, $preview);
                $items[] = $item;
            }
            $section['items'] = $items;
            $section['tool'] = null;
            if ($section['type'] === 'lead_tool') {
                $tool = $context['toolLinks'][$section['tool_key'] ?? ''] ?? null;
                if (! $tool) {
                    continue;
                }
                $section['tool'] = $tool;
            }
            if ($section['type'] === 'contact') {
                if ($contactRendered) {
                    continue;
                }
                $contactRendered = true;
                $section['contact_html'] = view('public.contact', ['business' => $context['business'] ?? [], 'contactSection' => $section])->render();
            }
            if (in_array($section['type'], ['trust', 'services', 'process', 'projects', 'reviews', 'service_areas', 'faq', 'offers', 'articles'], true) && $items === []) {
                continue;
            }
            $sections[] = $section;
        }

        return view('public.home-sections', ['sections' => $sections, 'contactWidget' => $context['contactWidget'] ?? ''])->render();
    }

    /** @return array<string, array{label: string, description: string, document: array}> */
    public function presets(): array
    {
        $presets = [
            'service' => ['Service team', 'Plumbing, electrical and HVAC service requests.', ['hero', 'trust', 'services', 'reviews', 'about', 'process', 'service_areas', 'contact']],
            'roofing' => ['Roofing and exteriors', 'Inspection requests and evidence from completed work.', ['hero', 'trust', 'services', 'projects', 'about', 'process', 'reviews', 'service_areas', 'faq', 'contact']],
            'remodeling' => ['Remodeling and projects', 'Project photography, scope and consultations.', ['hero', 'projects', 'services', 'about', 'process', 'reviews', 'service_areas', 'faq', 'contact']],
            'multi_trade' => ['Multi-trade contractor', 'Clear services, areas and the next step.', ['hero', 'services', 'projects', 'about', 'process', 'trust', 'service_areas', 'contact']],
        ];
        $result = [];
        foreach ($presets as $key => [$label, $description, $types]) {
            $sections = [];
            foreach ($types as $type) {
                $sections[] = [
                    'id' => $type === 'service_areas' ? 'service-areas' : $type,
                    'type' => $type, 'enabled' => in_array($type, ['hero', 'contact'], true), 'variant' => 'standard',
                    'heading' => match ($type) {
                        'hero' => 'Tell us about your project', 'contact' => 'Request a conversation',
                        'service_areas' => 'Where we work', default => ucfirst(str_replace('_', ' ', $type)),
                    },
                    'eyebrow' => '', 'body' => [], 'buttons' => $type === 'hero' ? [['label' => 'Request service', 'kind' => 'anchor', 'anchor' => 'contact', 'style' => 'primary']] : [], 'items' => [],
                ];
            }
            $result[$key] = ['label' => $label, 'description' => $description, 'document' => ['schema_version' => 1, 'mode' => 'guided', 'sections' => $sections]];
        }

        return $result;
    }

    private function validateItem(mixed $item, string $type, bool $includeDraft, string $path): array
    {
        $allowed = match ($type) {
            'faq' => ['question', 'answer'],
            'reviews' => ['text', 'author', 'source', 'url', 'date', 'permission_confirmed'],
            'services', 'articles' => ['page_id', 'title', 'text', 'image'],
            'service_areas' => ['title', 'text', 'page_id'],
            'projects' => [...self::CARD_FIELDS, 'location'],
            'offers' => [...self::CARD_FIELDS, 'starts_at', 'ends_at', 'terms'],
            default => self::CARD_FIELDS,
        };
        $rules = [$path => ['required', 'array:'.implode(',', $allowed)]];
        foreach ($allowed as $field) {
            $rules[$path.'.'.$field] = match ($field) {
                'image' => ['sometimes', 'nullable', 'array'],
                'page_id' => [in_array($type, ['services', 'articles'], true) ? 'required' : 'sometimes', 'nullable', 'string', 'max:100'],
                'permission_confirmed' => ['required', 'boolean'],
                'starts_at', 'ends_at', 'date' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
                default => [in_array($field, $type === 'faq' ? ['question', 'answer'] : ($type === 'reviews' ? ['text', 'author'] : (in_array($type, ['services', 'articles'], true) ? [] : ['title'])), true) ? 'required' : 'sometimes', 'nullable', 'string', 'max:'.(in_array($field, ['text', 'answer', 'terms'], true) ? '3000' : ($field === 'url' ? '2000' : '300'))],
            };
        }
        $validated = $this->validateAt($item, $path, $rules);
        if (! empty($validated['image'])) {
            $validated['image'] = $this->validateImage($validated['image'], $includeDraft, $path.'.image');
        }
        if (! empty($validated['page_id'])) {
            $this->validatePage($validated['page_id'], $includeDraft, $path.'.page_id', $this->pageTypes($type));
        }
        if (! empty($validated['url'])) {
            $this->validateUrl($validated['url'], $path.'.url');
        }
        if (! empty($validated['page_id']) && ! empty($validated['url'])) {
            $this->fail($path.'.url', 'Choose a managed page or an external URL, not both.');
        }
        if (! empty($validated['starts_at']) && ! empty($validated['ends_at']) && $validated['ends_at'] < $validated['starts_at']) {
            $this->fail($path.'.ends_at', 'The offer end date must follow its start date.');
        }

        if (array_key_exists('permission_confirmed', $validated)) {
            $validated['permission_confirmed'] = (bool) $validated['permission_confirmed'];
        }

        return $validated;
    }

    private function validateButton(mixed $button, bool $includeDraft, array $sectionIds, string $path): array
    {
        $button = $this->validateAt($button, $path, [
            $path => ['required', 'array:label,kind,page_id,url,anchor,style'],
            $path.'.label' => ['required', 'string', 'max:60'],
            $path.'.kind' => ['required', Rule::in(['page', 'url', 'phone', 'anchor'])],
            $path.'.style' => ['required', Rule::in(['primary', 'secondary', 'text'])],
            $path.'.page_id' => ['sometimes', 'nullable', 'string', 'max:100'],
            $path.'.url' => ['sometimes', 'nullable', 'string', 'max:2000'],
            $path.'.anchor' => ['sometimes', 'nullable', 'string', 'max:48'],
        ]);
        foreach (['page_id' => 'page', 'url' => 'url', 'anchor' => 'anchor'] as $field => $kind) {
            if (! empty($button[$field]) && $button['kind'] !== $kind) {
                $this->fail($path.'.'.$field, 'The destination field does not match this button type.');
            }
            if ($button['kind'] === $kind && empty($button[$field])) {
                $this->fail($path.'.'.$field, 'Choose a destination.');
            }
        }
        if ($button['kind'] === 'page') {
            $this->validatePage($button['page_id'], $includeDraft, $path.'.page_id');
        } elseif ($button['kind'] === 'url') {
            $this->validateUrl($button['url'], $path.'.url');
        } elseif ($button['kind'] === 'anchor' && ! in_array($button['anchor'], ['contact', ...$sectionIds], true)) {
            $this->fail($path.'.anchor', 'Select contact or an existing section ID.');
        }

        return $button;
    }

    private function validateImage(array $image, bool $includeDraft, string $path): array
    {
        $image = $this->validateAt($image, $path, [
            $path => ['required', 'array:media_id,alt,decorative,focal_x,focal_y'],
            $path.'.media_id' => ['required', 'string', 'regex:/^[a-zA-Z0-9_-]+$/D', 'max:100'],
            $path.'.alt' => ['present', 'nullable', 'string', 'max:300'],
            $path.'.decorative' => ['required', 'boolean'],
            $path.'.focal_x' => ['required', 'numeric', 'between:0,100'],
            $path.'.focal_y' => ['required', 'numeric', 'between:0,100'],
        ]);
        if (! $image['decorative'] && trim($image['alt'] ?? '') === '') {
            $this->fail($path.'.alt', 'Describe the image or mark it decorative.');
        }
        if (! $this->imageUrl($image['media_id'], $includeDraft)) {
            $this->fail($path.'.media_id', 'Select a ready local publishing image. Private files cannot be used.');
        }
        $image['decorative'] = (bool) $image['decorative'];
        $image['focal_x'] = (float) $image['focal_x'];
        $image['focal_y'] = (float) $image['focal_y'];
        $image['alt'] = $image['decorative'] ? '' : $image['alt'];

        return $image;
    }

    private function validateBody(array $blocks, bool $includeDraft, string $path): void
    {
        $checkHeadings = function (array $nodes) use (&$checkHeadings, $path): void {
            foreach ($nodes as $node) {
                if (! is_array($node)) {
                    continue;
                }
                if (($node['type'] ?? '') === 'heading' && ($node['props']['level'] ?? 2) === 1) {
                    $this->fail($path, 'Use H2–H6 inside section writing; the hero supplies the page H1.');
                }
                if (is_array($node['children'] ?? null)) {
                    $checkHeadings($node['children']);
                }
            }
        };
        $checkHeadings($blocks);
        try {
            $this->renderer($includeDraft)->render($blocks);
        } catch (ValidationException $exception) {
            $this->fail($path, (string) collect($exception->errors())->flatten()->first());
        }
    }

    private function validatePage(string $id, bool $includeDraft, string $path, array $types = []): void
    {
        $page = $this->resolvePage($id, $includeDraft);
        if (! $page || ($types && ! in_array($page['type'], $types, true))) {
            $this->fail($path, 'Select an available '.($types ? implode(' or ', $types).' ' : '').'page'.($includeDraft ? '.' : ' and publish it before publishing this homepage.'));
        }
    }

    private function pageTypes(string $section): array
    {
        return match ($section) {
            'services' => ['service'], 'articles' => ['article'], 'projects' => ['case-study'],
            'service_areas' => ['service-area'], default => [],
        };
    }

    private function resolvePage(string $id, bool $includeDraft): ?array
    {
        $page = $this->records->find('pages', $id);
        if (! $page || ($page['status'] ?? '') === 'archived') {
            return null;
        }
        if ($includeDraft && Access::can(auth()->user(), 'publishing.manage')) {
            return $page;
        }

        return ($page['status'] ?? '') === 'published' && is_array($page['published'] ?? null) ? $page['published'] : null;
    }

    private function pageUrl(array $page, bool $preview): string
    {
        if ($preview) {
            return '/api/v1/pages/'.$page['id'].'/preview';
        }

        return ($page['type'] ?? '') === 'home' ? '/' : '/p/'.$page['slug'];
    }

    private function validateUrl(string $url, string $path): void
    {
        if (! filter_var($url, FILTER_VALIDATE_URL) || ! in_array(strtolower((string) parse_url($url, PHP_URL_SCHEME)), ['http', 'https'], true) || parse_url($url, PHP_URL_USER) !== null || parse_url($url, PHP_URL_PASS) !== null || preg_match('/[\x00-\x20\x7f\\\\]/', $url)) {
            $this->fail($path, 'Use an absolute HTTP or HTTPS URL without credentials.');
        }
    }

    private function imageUrl(string $id, bool $includeDraft): ?string
    {
        $file = $this->records->find('files', $id);
        $visible = ($file['visibility'] ?? '') === 'public' || ($includeDraft && ($file['visibility'] ?? '') === 'draft' && Access::can(auth()->user(), 'publishing.manage'));

        return $visible && ($file['status'] ?? '') === 'ready' && in_array($file['mime'] ?? '', ['image/png', 'image/jpeg', 'image/webp', 'image/gif'], true) ? '/files/'.$id : null;
    }

    private function renderer(bool $includeDraft): BlockNoteRenderer
    {
        return new BlockNoteRenderer(fn (string $id): ?string => $this->imageUrl($id, $includeDraft));
    }

    /** Missing or withdrawn media must not make an otherwise published page fail. */
    private function availableBody(array $blocks, bool $includeDraft): array
    {
        $result = [];
        foreach ($blocks as $block) {
            if (($block['type'] ?? '') === 'image') {
                $id = $block['props']['mediaId'] ?? null;
                if (! $id && preg_match('~^/files/([a-zA-Z0-9_-]+)$~D', $block['props']['url'] ?? '', $match)) {
                    $id = $match[1];
                }
                if (! $id || ! $this->imageUrl($id, $includeDraft)) {
                    continue;
                }
            }
            if (isset($block['children'])) {
                $block['children'] = $this->availableBody($block['children'], $includeDraft);
            }
            $result[] = $block;
        }

        return $result;
    }

    private function renderImage(?array $image, bool $includeDraft): ?array
    {
        if (! $image) {
            return null;
        }
        $url = $this->imageUrl($image['media_id'], $includeDraft);

        return $url ? $image + ['url' => $url, 'position_x' => (int) round($image['focal_x']), 'position_y' => (int) round($image['focal_y'])] : null;
    }

    private function buttonHref(array $button, array $context, bool $preview): ?string
    {
        return match ($button['kind']) {
            'page' => ($page = $this->resolvePage($button['page_id'], $preview)) ? $this->pageUrl($page, $preview) : null,
            'url' => $button['url'],
            'anchor' => $button['anchor'] === 'contact' ? '#request-service' : '#home-'.$button['anchor'],
            'phone' => ! empty($context['business']['phone']) ? 'tel:'.preg_replace('/[^+0-9]/', '', $context['business']['phone']) : null,
            default => null,
        };
    }

    private function validateAt(mixed $value, string $path, array $rules): array
    {
        $input = [];
        data_set($input, $path, $value);

        return data_get(Validator::make($input, $rules)->validate(), $path);
    }

    private function fail(string $path, string $message): never
    {
        throw ValidationException::withMessages([$path => $message]);
    }
}

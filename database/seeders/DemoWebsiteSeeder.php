<?php

namespace Database\Seeders;

/** Author: ramanpal singh | URL: https://kwebby.com */

use App\Contracts\RecordRepository;
use App\Identity\CmsUser;
use App\Publishing\HomepageSections;
use App\Publishing\PublishingService;
use App\Publishing\WebsiteSettings;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Auth;

class DemoWebsiteSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment('local', 'testing')) {
            throw new \RuntimeException('Demo website data is restricted to local/testing environments.');
        }
        $records = app(RecordRepository::class);
        if (! $records->find('settings', 'demo')) {
            return;
        }
        $owner = $records->all('users', ['role' => 'owner'])[0] ?? null;
        if (! $owner) {
            return;
        }
        $previous = Auth::user();
        Auth::setUser(new CmsUser($owner));
        try {
            $publishing = app(PublishingService::class);
            $pages = $publishing->published();
            if (! $records->all('pages', ['type' => 'home'])) {
                $document = app(HomepageSections::class)->presets()['service']['document'];
                foreach ($document['sections'] as &$section) {
                    if ($section['type'] === 'hero') {
                        $section['heading'] = 'Your home. In good hands.';
                        $section['eyebrow'] = 'DEMONSTRATION WEBSITE';
                        $section['body'] = $this->text('Tell us what needs attention. Explore this example website, then replace its content and business details before launch.');
                    } elseif ($section['type'] === 'services') {
                        $section['items'] = array_values(array_map(fn (array $page): array => ['page_id' => $page['id']], array_filter($pages, fn (array $page): bool => $page['type'] === 'service')));
                        $section['enabled'] = count($section['items']) > 0;
                    } elseif ($section['type'] === 'about') {
                        $section['enabled'] = true;
                        $section['heading'] = 'A clear next step for every request';
                        $section['body'] = $this->text('This example shows how your team can introduce its services. Add your real people, qualifications, project photos and service areas through the website editor.');
                    } elseif ($section['type'] === 'process') {
                        $section['enabled'] = true;
                        $section['items'] = [['title' => 'Tell us what you need', 'text' => 'Share your goals and access information.'], ['title' => 'Review the plan', 'text' => 'Review and accept your estimate in the client portal.'], ['title' => 'Stay in touch', 'text' => 'Message the office and review your appointments and invoices.']];
                    } elseif ($section['type'] === 'faq') {
                        $section['enabled'] = true;
                        $section['items'] = [['question' => 'Does a request confirm my appointment?', 'answer' => 'The office reviews requests and confirms appointments separately.'], ['question' => 'Can I review my estimate online?', 'answer' => 'Your client portal lets you review the estimate sent by your team.']];
                    }
                }
                unset($section);
                $home = $publishing->create(['title' => 'Your home. In good hands.', 'slug' => 'home', 'type' => 'home', 'locale' => 'en-US', 'excerpt' => 'Demonstration contractor website. Replace with your real business details before launch.', 'home_sections' => $document, 'seo' => ['robots' => 'noindex,follow']], $owner['id']);
                $publishing->publish($home['id'], $home['version'], $owner['id']);
            }
            if (! $records->find('website_settings', 'main')) {
                $settings = app(WebsiteSettings::class);
                $website = $settings->get();
                foreach (array_slice($pages, 0, 6) as $page) {
                    $website['draft']['navigation']['primary'][] = ['label' => $page['title'], 'kind' => 'page', 'page_id' => $page['id']];
                }
                $website['draft']['navigation']['footer'] = $website['draft']['navigation']['primary'];
                $website = $settings->update($website['draft'], $website['version'], $owner['id']);
                $settings->publish($website['version'], $owner['id']);
            }
        } finally {
            if ($previous) {
                Auth::setUser($previous);
            } else {
                Auth::forgetGuards();
            }
        }
    }

    private function text(string $text): array
    {
        return [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => $text, 'styles' => []]]]];
    }
}

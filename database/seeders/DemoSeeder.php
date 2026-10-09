<?php

/**
 * Author: ramanpal singh
 * URL: https://kwebby.com
 */

namespace Database\Seeders;

use App\Contracts\RecordRepository;
use App\Finance\FinanceService;
use App\Identity\CmsUser;
use App\Publishing\PublishingService;
use App\Support\Outbox;
use App\Support\Settings;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;

class DemoSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment('local', 'testing')) {
            throw new \RuntimeException('Demo data is restricted to local/testing environments.');
        }
        $records = app(RecordRepository::class);
        if ($records->find('settings', 'demo')) {
            $this->call(DemoWebsiteSeeder::class);

            return;
        }
        $password = getenv('CMS_DEMO_PASSWORD');
        if (! $password || strlen($password) < 12) {
            throw new \RuntimeException('Set CMS_DEMO_PASSWORD to at least 12 characters.');
        }
        $owner = $records->all('users', ['role' => 'owner'])[0] ?? $records->create('users', ['name' => 'Alex Morgan', 'email' => 'owner@example.test', 'password' => Hash::make($password), 'role' => 'owner', 'active' => true, 'email_verified_at' => now()->toISOString(), 'session_version' => 1]);
        Auth::login(new CmsUser($owner));
        Settings::put('business', ['name' => 'Northline Services', 'legal_name' => 'Northline Services — Demonstration', 'email' => 'office@example.test', 'phone' => '', 'country' => 'US', 'currency' => 'USD', 'timezone' => 'America/New_York', 'trade' => 'GeneralContractor', 'service_areas' => ['Your service area'], 'description' => 'Demonstration website. Replace this content and business details before publishing.']);
        Settings::put('installation', ['installed_at' => now()->toISOString(), 'provider' => config('cms.data_provider'), 'bootstrap_locked' => true, 'schema_version' => 1]);
        $staff = [];
        foreach ([['Jordan Lee', 'technician'], ['Sam Rivera', 'technician'], ['Taylor Brooks', 'office']] as $index => [$name,$role]) {
            $staff[] = $records->create('users', ['name' => $name, 'email' => 'staff'.($index + 1).'@example.test', 'role' => $role, 'password' => Hash::make($password), 'active' => true, 'email_verified_at' => now()->toISOString(), 'session_version' => 1]);
        }
        $customers = [];
        foreach (['Casey Walker', 'Avery Chen', 'Morgan Davis', 'Riley Patel'] as $index => $name) {
            $customers[] = $records->create('customers', ['name' => $name, 'email' => 'client'.($index + 1).'@example.test', 'phone' => '', 'address' => ($index + 10).' Example Lane', 'city' => 'Demo City', 'region' => 'NY', 'postal_code' => '10001', 'country' => 'US', 'status' => 'active']);
        }
        $client = $records->create('users', ['name' => $customers[0]['name'], 'email' => 'client@example.test', 'password' => Hash::make($password), 'role' => 'client', 'customer_id' => $customers[0]['id'], 'email_verified_at' => now()->toISOString(), 'active' => true, 'session_version' => 1]);
        foreach ([['Roof inspection', 'Roofing', 'scheduled'], ['Water heater service', 'Plumbing', 'in_progress'], ['Seasonal HVAC maintenance', 'HVAC', 'scheduled'], ['Kitchen project assessment', 'Remodeling', 'blocked'], ['Lighting installation', 'Electrical', 'completed']] as $index => [$title,$service,$status]) {
            $customer = $customers[$index % 4];
            $records->create('jobs', ['title' => $title, 'customer_id' => $customer['id'], 'customer_name' => $customer['name'], 'service' => $service, 'status' => $status, 'priority' => $status === 'blocked' ? 'high' : 'normal', 'assigned_to' => [$staff[$index % 2]['id']], 'scheduled_at' => now()->startOfDay()->addHours(13 + $index)->toISOString(), 'duration_minutes' => 60, 'address' => $customer['address'], 'description' => 'Demo job. Review the agreed scope with the customer before work begins.', 'checklist' => [], 'actual_cost_minor' => 0]);
        }
        foreach ([['Jamie Wilson', 'Roofing', 'new', 'Website'], ['Drew Miller', 'Plumbing', 'contacted', 'Referral'], ['Robin Garcia', 'Electrical', 'qualified', 'Website'], ['Cameron Adams', 'HVAC', 'estimate_sent', 'Phone']] as [$name,$service,$stage,$source]) {
            $records->create('leads', ['name' => $name, 'email' => strtolower(str_replace(' ', '.', $name)).'@example.test', 'service' => $service, 'stage' => $stage, 'source' => $source, 'priority' => 'normal', 'notes' => 'Fictional demonstration inquiry.', 'assigned_to' => $owner['id'], 'follow_up_at' => now()->addDay()->toISOString()]);
        }
        $finance = app(FinanceService::class);
        foreach ([['Roof inspection and report', 47500], ['Water heater service', 82500], ['Electrical installation', 125000]] as $index => [$title,$amount]) {
            $draft = $finance->draft('invoices', ['customer_id' => $customers[$index]['id'], 'title' => $title, 'currency' => 'USD', 'due_date' => now()->addDays(14)->toDateString(), 'items' => [['description' => $title, 'quantity' => '1', 'unit_price_minor' => $amount, 'tax_basis_points' => 0]], 'notes' => 'Demo invoice. No real payment is requested.']);
            $invoice = $finance->issue($draft['id']);
            if ($index > 0) {
                $finance->payment($invoice['id'], $index === 1 ? 30000 : $amount, 'bank_transfer', 'DEMO-ONLY', 'demo-payment-'.$index);
            }
        }
        $estimate = $finance->draft('estimates', ['customer_id' => $customers[0]['id'], 'title' => 'Roof maintenance assessment', 'currency' => 'USD', 'items' => [['description' => 'Site visit and written scope', 'quantity' => '1', 'unit_price_minor' => 25000, 'tax_basis_points' => 0]], 'notes' => 'Demonstration only.']);
        $finance->sendEstimate($estimate['id']);
        $conversation = $records->create('conversations', ['title' => 'Roof inspection · Casey Walker', 'type' => 'client', 'participants' => [$owner['id'], $client['id']], 'customer_id' => $customers[0]['id'], 'status' => 'open', 'last_message' => 'Could we review the access arrangements before the visit?', 'last_message_at' => now()->toISOString()]);
        $records->create('messages', ['conversation_id' => $conversation['id'], 'sender_id' => $client['id'], 'sender_name' => $client['name'], 'body' => 'Could we review the access arrangements before the visit?', 'file_ids' => [], 'sequence' => $records->sequence('conversation:'.$conversation['id'])]);
        Outbox::notify($owner['id'], 'Welcome. This installation contains demonstration data.', 'system', '/app/settings');
        Outbox::notify($owner['id'], 'Kitchen assessment needs a customer response', 'operations', '/app/jobs', 'high');
        $publishing = app(PublishingService::class);
        foreach ([['Services', 'services', 'service', 'One place to plan your next service visit. Tell us what you need, and our office will confirm the right next step.'], ['About our team', 'about', 'about', 'This is a demonstration website for a contractor business. Add your real team, qualifications, services and service areas before launch.'], ['Preparing for a service visit', 'preparing-for-a-service-visit', 'article', 'Write down when you first noticed the issue and any work already completed. Keep access routes clear and share relevant photos with the office. Do not attempt hazardous repairs.']] as [$title,$slug,$type,$text]) {
            $page = $publishing->create(['title' => $title, 'slug' => $slug, 'type' => $type, 'locale' => 'en-US', 'excerpt' => $text, 'content' => [['id' => 'intro', 'type' => 'paragraph', 'props' => [], 'content' => [['type' => 'text', 'text' => $text, 'styles' => []]], 'children' => []]], 'seo' => ['title' => $title.' | Northline Services', 'description' => $text, 'noindex' => true]], $owner['id']);
            $publishing->publish($page['id'], $page['version'], $owner['id']);
        }
        Settings::put('demo', ['enabled' => true, 'created_at' => now()->toISOString()]);
        $this->call(DemoWebsiteSeeder::class);
        Auth::logout();
        $this->command?->info('Demo workspace created. Accounts: owner@example.test, client@example.test, staff1@example.test.');
    }
}

{{-- Author: ramanpal singh | URL: https://kwebby.com --}}
<!doctype html>
<html lang="en"><head><meta charset="UTF-8"><title>{{ $document['number'] }}</title>
<style>
body { font-family: "{{ $document['design']['font'] ?? 'DejaVu Sans' }}", sans-serif; color:#25352f; font-size:11px; line-height:1.6; margin:22px; }
h1 { color:{{ $document['design']['accent_color'] ?? '#1f594e' }}; font-size:32px; margin:0; }
h2 { font-size:18px; margin:0; } .muted { color:#617168; } .row { width:100%; } .row td { vertical-align:top; } .right { text-align:right; } .section { margin-top:28px; } table.items { border-collapse:collapse; width:100%; } .items th { text-align:left; background:#edf3ef; padding:10px; } .items td { padding:10px; border-bottom:1px solid #dae3dd; } .totals { margin-left:55%; width:45%; margin-top:20px; } .totals td { padding:5px 0; } .total td { border-top:2px solid #25352f; font-size:16px; font-weight:bold; } pre { font-family:inherit; white-space:pre-wrap; } .footer { margin-top:32px; color:#617168; border-top:1px solid #dae3dd; padding-top:12px; }
</style></head><body>
@php($business = $document['business'])
@php($customer = $document['customer'])
@php($money = fn($value) => (new \App\Finance\Money((int)$value, $document['currency']))->format())
<table class="row"><tr><td><h2>{{ $business['legal_name'] ?? $business['name'] ?? '' }}</h2><div>{{ $business['address'] ?? '' }}</div><div>{{ implode(' ', array_filter([$business['city'] ?? '',$business['region'] ?? '',$business['postal_code'] ?? '',$business['country'] ?? ''])) }}</div><div>{{ $business['email'] ?? '' }} {{ $business['phone'] ?? '' }}</div>@if(!empty($business['tax_id']))<div>Tax ID: {{ $business['tax_id'] }}</div>@endif</td><td class="right"><h1>INVOICE</h1><strong>{{ $document['number'] }}</strong><div>Issued {{ substr($document['issued_at'],0,10) }}</div>@if(!empty($document['due_date']))<div>Due {{ $document['due_date'] }}</div>@endif</td></tr></table>
<div class="section"><div class="muted">BILL TO</div><h2>{{ $customer['name'] }}</h2><div>{{ $customer['address'] ?? '' }}</div><div>{{ $customer['email'] ?? '' }}</div></div>
<div class="section"><h2>{{ $document['title'] }}</h2>@if(!empty($document['po_number']))<p>PO: {{ $document['po_number'] }}</p>@endif @if(!empty($document['service_address']))<p>Service address: {{ $document['service_address'] }}</p>@endif</div>
<table class="items section"><thead><tr><th>Description</th>@if($document['design']['show_quantity'] ?? true)<th>Qty</th>@endif<th>Unit price</th>@if($document['design']['show_tax'] ?? true)<th>Tax</th>@endif<th>Amount</th></tr></thead><tbody>@foreach($document['items'] as $item)<tr><td>{{ $item['description'] }}@if($item['discount_minor'] > 0)<br><span class="muted">Discount: {{ $money($item['discount_minor']) }}</span>@endif</td>@if($document['design']['show_quantity'] ?? true)<td>{{ $item['quantity'] }}</td>@endif<td>{{ $money($item['unit_price_minor']) }}</td>@if($document['design']['show_tax'] ?? true)<td>{{ $money($item['tax_minor']) }}</td>@endif<td>{{ $money($item['total_minor']) }}</td></tr>@endforeach</tbody></table>
<table class="totals"><tr><td>Subtotal</td><td class="right">{{ $money($document['subtotal_minor']) }}</td></tr><tr><td>Tax</td><td class="right">{{ $money($document['tax_minor']) }}</td></tr><tr class="total"><td>Total ({{ $document['currency'] }})</td><td class="right">{{ $money($document['total_minor']) }}</td></tr></table>
@if(!empty($document['notes']))<pre class="section">{{ $document['notes'] }}</pre>@endif
@if(!empty($document['design']['payment_instructions']))<div class="section"><strong>Payment instructions</strong><pre>{{ $document['design']['payment_instructions'] }}</pre></div>@endif
<pre class="section">{{ $document['design']['terms'] ?? '' }}</pre><div class="footer">{{ $document['design']['footer'] ?? '' }}<p>This document records the invoice as issued. Current payments and credits appear separately in your client portal.</p></div>
</body></html>

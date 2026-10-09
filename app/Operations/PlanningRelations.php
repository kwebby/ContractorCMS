<?php

/**
 * Author: ramanpal singh
 * URL: https://kwebby.com
 */

namespace App\Operations;

use App\Contracts\RecordRepository;
use Illuminate\Validation\ValidationException;

class PlanningRelations
{
    public function __construct(private RecordRepository $records) {}

    public function validate(array $record): void
    {
        $relations = [];
        foreach (['customer_id' => 'customers', 'lead_id' => 'leads', 'job_id' => 'jobs'] as $field => $collection) {
            if (! empty($record[$field])) {
                $relations[$field] = $this->records->find($collection, $record[$field]);
                if (! $relations[$field]) {
                    throw ValidationException::withMessages([$field => 'Choose an existing '.str_replace('_id', '', $field).'.']);
                }
            }
        }
        $customerId = $record['customer_id'] ?? null;
        $job = $relations['job_id'] ?? null;
        $lead = $relations['lead_id'] ?? null;
        if ($job && $customerId && ($job['customer_id'] ?? null) !== $customerId) {
            throw ValidationException::withMessages(['job_id' => 'The job must belong to the selected customer.']);
        }
        if ($lead && ($customerId || $job)) {
            $relatedCustomerId = $customerId ?: ($job['customer_id'] ?? null);
            $sameCustomer = $relatedCustomerId && ($lead['customer_id'] ?? null) === $relatedCustomerId;
            $sameJobLead = $job && ($job['lead_id'] ?? null) === $lead['id'];
            if (! $sameCustomer && ! $sameJobLead) {
                throw ValidationException::withMessages(['lead_id' => 'The lead must be linked to the selected customer or job.']);
            }
            if (! empty($lead['customer_id']) && $lead['customer_id'] !== $relatedCustomerId) {
                throw ValidationException::withMessages(['lead_id' => 'The lead belongs to a different customer.']);
            }
        }
    }
}

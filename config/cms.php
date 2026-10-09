<?php

/**
 * Author: ramanpal singh
 * URL: https://kwebby.com
 */

return [
    'data_provider' => env('DATA_PROVIDER', 'postgres'),
    'mfa_required' => env('CMS_MFA_REQUIRED', env('APP_ENV') === 'production'),
    'firestore' => ['experimental' => env('FIRESTORE_EXPERIMENTAL', false), 'project' => env('FIRESTORE_PROJECT_ID'), 'database' => env('FIRESTORE_DATABASE', '(default)'), 'credentials' => env('GOOGLE_APPLICATION_CREDENTIALS'), 'emulator' => env('FIRESTORE_EMULATOR_HOST')],
    'uploads_max_kb' => 10240,
    'ai_tools' => ['service-preparation', 'project-scope', 'quote-comparison', 'roof-readiness', 'drain-intake', 'water-heater-planner', 'hvac-maintenance', 'electrical-brief', 'remodel-planner', 'budget-organizer'],
];

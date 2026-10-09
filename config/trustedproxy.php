<?php

/**
 * Author: ramanpal singh
 * URL: https://kwebby.com
 */
$proxies = array_values(array_filter(array_map('trim', explode(',', (string) env('TRUSTED_PROXIES', '')))));

return [
    'proxies' => $proxies === [] ? null : $proxies,
];

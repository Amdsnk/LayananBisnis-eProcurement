<?php

return ['bid_key' => env('BID_ENCRYPTION_KEY'), 'erp_allowed_hosts' => array_filter(explode(',', env('ERP_ALLOWED_HOSTS', ''))), 'scanner_host' => env('SCANNER_HOST'), 'scanner_port' => (int) env('SCANNER_PORT', 3310), 'session_absolute_minutes' => 480];

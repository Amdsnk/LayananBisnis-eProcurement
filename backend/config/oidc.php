<?php

return ['issuer' => env('OIDC_ISSUER'), 'client_id' => env('OIDC_CLIENT_ID'), 'client_secret' => env('OIDC_CLIENT_SECRET'), 'redirect_uri' => env('OIDC_REDIRECT_URI'), 'allowed_hosts' => array_filter(explode(',', env('OIDC_ALLOWED_HOSTS', '')))];

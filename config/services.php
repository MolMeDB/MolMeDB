<?php

use App\Support\ServiceUrl;

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'resend' => [
        'key' => env('RESEND_KEY'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],
    'cdk_depict_url' => env('CDK_DEPICT_URL'),
    'cdk_depict_internal_url' => env('CDK_DEPICT_INTERNAL_URL'),

    'rdkit' => [
        'url' => ServiceUrl::fromHost(env('RDKIT_HOST')),
    ],

    /**
     * External services configuration
     */
    'chebi' => [
        'base_api_url' => env('CHEBI_BASE_API_URL', 'https://www.ebi.ac.uk/chebi/backend/api/public'),
    ],

    'unichem' => [
        'base_api_url' => env('UNICHEM_BASE_API_URL', 'https://www.ebi.ac.uk/unichem/api/v1/'),
    ],

    'uniprot' => [
        'base_api_url' => env('UNIPROT_BASE_API_URL', 'https://rest.uniprot.org/'),
    ],

    'europe_pmc' => [
        'endpoint' => env('EUROPE_PMC_ENDPOINT', ''),
    ],

    'crossref' => [
        'endpoint' => env('CROSSREF_ENDPOINT', 'https://api.crossref.org'),
    ],

    'turnstile' => [
        'enabled' => env('TURNSTILE_ENABLED', true),
        'site_key' => env('TURNSTILE_SITE_KEY'),
        'secret_key' => env('TURNSTILE_SECRET_KEY'),
        'verify_url' => env('TURNSTILE_VERIFY_URL', 'https://challenges.cloudflare.com/turnstile/v0/siteverify'),
    ],
];

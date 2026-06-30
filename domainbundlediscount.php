<?php

if (!defined("WHMCS")) {
    die("This file cannot be accessed directly");
}

function domainbundlediscount_config()
{
    return [
        'name' => 'Domain Bundle Discount',
        'description' => 'Give domain discount when customer buys selected hosting products.',
        'version' => '1.0.0',
        'author' => 'RafaTech',
        'fields' => [
            'eligible_product_ids' => [
                'FriendlyName' => 'Eligible Product IDs',
                'Type' => 'text',
                'Size' => '50',
                'Default' => '1,2,3',
                'Description' => 'Separate product IDs with comma. Example: 1,2,3',
            ],
            'eligible_cycles' => [
                'FriendlyName' => 'Eligible Billing Cycles',
                'Type' => 'text',
                'Size' => '50',
                'Default' => 'annually',
                'Description' => 'Example: annually,biennially,triennially',
            ],
            'domain_tld' => [
                'FriendlyName' => 'Eligible TLD',
                'Type' => 'text',
                'Size' => '10',
                'Default' => '.com',
                'Description' => 'Example: .com',
            ],
            'discount_percentage' => [
                'FriendlyName' => 'Domain Discount Percentage',
                'Type' => 'text',
                'Size' => '10',
                'Default' => '50',
                'Description' => 'Example: 50 for 50% discount',
            ],
            'max_domains' => [
                'FriendlyName' => 'Maximum Discounted Domains',
                'Type' => 'text',
                'Size' => '10',
                'Default' => '1',
                'Description' => 'Usually 1 domain per order.',
            ],
        ],
    ];
}

function domainbundlediscount_output($vars)
{
    echo '<h2>Domain Bundle Discount</h2>';
    echo '<p>Configure this module from <strong>System Settings → Addon Modules</strong>.</p>';
    echo '<p>This module applies a domain discount when selected hosting products are ordered with selected billing cycles.</p>';
}

<?php

use WHMCS\Database\Capsule;

if (!defined("WHMCS")) {
    die("This file cannot be accessed directly");
}

add_hook('CartTotalAdjustment', 1, function ($vars) {

    $settings = Capsule::table('tbladdonmodules')
        ->where('module', 'domainbundlediscount')
        ->pluck('value', 'setting');

    if (empty($settings)) {
        return;
    }

    $eligibleProductIds = array_filter(array_map('trim', explode(',', $settings['eligible_product_ids'] ?? '')));
    $eligibleProductIds = array_map('intval', $eligibleProductIds);

    $eligibleCycles = array_filter(array_map('trim', explode(',', strtolower($settings['eligible_cycles'] ?? 'annually'))));

    $domainTld = strtolower(trim($settings['domain_tld'] ?? '.com'));
    if ($domainTld && $domainTld[0] !== '.') {
        $domainTld = '.' . $domainTld;
    }

    $discountPercentage = (float) ($settings['discount_percentage'] ?? 50);
    $maxDomains = (int) ($settings['max_domains'] ?? 1);

    if ($discountPercentage <= 0 || $discountPercentage > 100) {
        return;
    }

    if ($maxDomains <= 0) {
        $maxDomains = 1;
    }

    $hasEligibleHosting = false;

    foreach ($vars['products'] ?? [] as $product) {
        $pid = (int) ($product['pid'] ?? 0);
        $billingCycle = strtolower($product['billingcycle'] ?? '');

        if (
            in_array($pid, $eligibleProductIds, true)
            && in_array($billingCycle, $eligibleCycles, true)
        ) {
            $hasEligibleHosting = true;
            break;
        }
    }

    if (!$hasEligibleHosting) {
        return;
    }

    $discountTotal = 0.00;
    $discountedCount = 0;

    foreach ($vars['domains'] ?? [] as $domain) {
        if ($discountedCount >= $maxDomains) {
            break;
        }

        $domainType = strtolower($domain['type'] ?? '');
        $domainName = strtolower($domain['domain'] ?? '');

        // Only new registration, not transfer/renewal
        if ($domainType !== 'register') {
            continue;
        }

        if (!str_ends_with($domainName, $domainTld)) {
            continue;
        }

        $domainPrice = (float) ($domain['price'] ?? 0);

        if ($domainPrice <= 0) {
            continue;
        }

        $discountTotal += round($domainPrice * ($discountPercentage / 100), 2);
        $discountedCount++;
    }

    if ($discountTotal <= 0) {
        return;
    }

    return [
        'description' => $discountPercentage . '% Off ' . strtoupper($domainTld) . ' Domain with Eligible Hosting',
        'amount' => '-' . number_format($discountTotal, 2, '.', ''),
        'taxed' => false,
    ];
});

<?php

use WHMCS\Database\Capsule;

if (!defined("WHMCS")) {
    die("This file cannot be accessed directly");
}

function domainbundlediscount_calculate($products, $domains)
{
    $result = [
        'eligible'        => false,
        'discountPercent' => 0,
        'totalDiscount'   => 0,
        'domains'         => [],
    ];

    $settings = Capsule::table('mod_domainbundlediscount')->first();

    if (!$settings || (int) $settings->status !== 1) {
        return $result;
    }

    $eligibleProductIds = json_decode($settings->product_ids, true) ?: [];
    $eligibleBillingCycles = json_decode($settings->billing_cycles, true) ?: [];
    $eligibleTlds = json_decode($settings->tlds, true) ?: [];
    $eligibleDomainActions = json_decode($settings->domain_actions, true) ?: ['register'];
    $discountPercent = (float) $settings->discount_percent;

    if (
        empty($eligibleProductIds)
        || empty($eligibleBillingCycles)
        || empty($eligibleTlds)
        || empty($eligibleDomainActions)
        || $discountPercent <= 0
    ) {
        return $result;
    }

    $result['discountPercent'] = $discountPercent;

    if (empty($products) || !is_array($products) || empty($domains) || !is_array($domains)) {
        return $result;
    }

    $domainsByName = [];

    foreach ($domains as $domain) {
        $domainType = strtolower($domain['type'] ?? '');

        if (!in_array($domainType, $eligibleDomainActions, true)) {
            continue;
        }

        $domainName = domainbundlediscount_get_domain_name($domain);

        if (!$domainName || strpos($domainName, '.') === false) {
            continue;
        }

        $tld = domainbundlediscount_get_tld_from_domain($domainName, $eligibleTlds);

        if (!$tld) {
            continue;
        }

        $domainsByName[$domainName] = [
            'domain'    => $domainName,
            'tld'       => $tld,
            'regperiod' => isset($domain['regperiod']) ? (int) $domain['regperiod'] : 1,
            'type'      => $domainType,
        ];
    }

    if (empty($domainsByName)) {
        return $result;
    }

    foreach ($products as $product) {
        $pid = isset($product['pid']) ? (int) $product['pid'] : 0;

        $billingCycle = '';

        foreach (['billingcycle', 'billingCycle', 'cycle', 'recurringcycle'] as $key) {
            if (!empty($product[$key])) {
                $billingCycle = $product[$key];
                break;
            }
        }

        if (
            !in_array($pid, $eligibleProductIds, true)
            || !domainbundlediscount_in_array_ci($billingCycle, $eligibleBillingCycles)
        ) {
            continue;
        }

        $bundledDomain = isset($product['domain']) ? strtolower(trim($product['domain'])) : '';

        if (!$bundledDomain) {
            continue;
        }

        if (!isset($domainsByName[$bundledDomain])) {
            continue;
        }

        if (isset($result['domains'][$bundledDomain])) {
            continue;
        }

        $d = $domainsByName[$bundledDomain];

        $domainAmount = domainbundlediscount_lookup_domain_price(
            $d['tld'],
            $d['regperiod'],
            $d['type']
        );

        if ($domainAmount <= 0) {
            continue;
        }

        $discountAmount = round($domainAmount * ($discountPercent / 100), 2);

        $result['eligible'] = true;
        $result['totalDiscount'] += $discountAmount;
        $result['domains'][$bundledDomain] = [
            'domain'     => $bundledDomain,
            'type'       => $d['type'],
            'original'   => $domainAmount,
            'discounted' => round($domainAmount - $discountAmount, 2),
        ];
    }

    $result['domains'] = array_values($result['domains']);

    return $result;
}

add_hook('CartTotalAdjustment', 1, function ($vars) {
    try {
        $calc = domainbundlediscount_calculate($vars['products'] ?? [], $vars['domains'] ?? []);

        if (!$calc['eligible'] || $calc['totalDiscount'] <= 0) {
            return [];
        }

        $domainNames = array_column($calc['domains'], 'domain');
        $discountPercent = rtrim(rtrim(number_format($calc['discountPercent'], 2), '0'), '.');

        return [
            'description' => 'One-Time Domain Bundle (' . implode(', ', $domainNames) . ') - ' . $discountPercent . '% Discount',
            'amount'      => '-' . number_format($calc['totalDiscount'], 2, '.', ''),
            'taxed'       => false,
        ];

    } catch (\Exception $e) {
        logActivity('Domain Bundle Discount hook error: ' . $e->getMessage());
        return [];
    }
});

add_hook('ClientAreaFooterOutput', 1, function ($vars) {
    if (!isset($vars['filename']) || $vars['filename'] !== 'cart') {
        return '';
    }

    try {
        $calc = domainbundlediscount_calculate($vars['products'] ?? [], $vars['domains'] ?? []);

        if (!$calc['eligible'] || $calc['totalDiscount'] <= 0) {
            return '';
        }

        $domainNames = array_column($calc['domains'], 'domain');
        $discountPercent = rtrim(rtrim(number_format($calc['discountPercent'], 2), '0'), '.');

        $discountLabel = 'One-Time Domain Bundle (' .
            implode(', ', $domainNames) .
            ')<br>' .
            $discountPercent .
            '% Discount';

        $discountAmount = number_format($calc['totalDiscount'], 2);

        $jsonLabel = json_encode($discountLabel);
        $jsonAmount = json_encode($discountAmount);

        return <<<HTML
<style>
    .dbd-summary-row {
        padding: 5px 0;
        color: #2e7d32;
    }

    .dbd-summary-row .dbd-label {
        line-height: 1.35;
    }
</style>

<script>
document.addEventListener('DOMContentLoaded', function () {
    var discountLabel = {$jsonLabel};
    var discountAmount = {$jsonAmount};

    var summaryContainer = document.querySelector('.summary-container');

    if (!summaryContainer) {
        return;
    }

    if (document.querySelector('.dbd-summary-row')) {
        return;
    }

    var taxBox = summaryContainer.querySelector('.bordered-totals');
    var recurringTotals = summaryContainer.querySelector('.recurring-totals');
    var subtotalRow = summaryContainer.querySelector('.subtotal');

    var newRow = document.createElement('div');
    newRow.className = 'clearfix dbd-summary-row';
    newRow.innerHTML =
        '<span class="pull-left float-left dbd-label">' + discountLabel + '</span>' +
        '<span class="pull-right float-right">-RM' + discountAmount + '</span>';

    if (taxBox) {
        summaryContainer.insertBefore(newRow, taxBox);
    } else if (recurringTotals) {
        summaryContainer.insertBefore(newRow, recurringTotals);
    } else if (subtotalRow && subtotalRow.nextSibling) {
        summaryContainer.insertBefore(newRow, subtotalRow.nextSibling);
    } else {
        summaryContainer.appendChild(newRow);
    }
});
</script>
HTML;

    } catch (\Exception $e) {
        logActivity('Domain Bundle Discount display hook error: ' . $e->getMessage());
        return '';
    }
});

function domainbundlediscount_lookup_domain_price($tld, $years = 1, $domainType = 'register')
{
    if ($years < 1) {
        $years = 1;
    }

    $extension = ltrim($tld, '.');

    $tldRow = Capsule::table('tbldomainpricing')
        ->where(function ($q) use ($tld, $extension) {
            $q->where('extension', $tld)
              ->orWhere('extension', $extension);
        })
        ->first();

    if (!$tldRow) {
        return 0;
    }

    $currencyId = Capsule::table('tblcurrencies')
        ->where('default', 1)
        ->value('id');

    if (!$currencyId) {
        $currencyId = 1;
    }

    $pricingTypeMap = [
        'register' => 'domainregister',
        'transfer' => 'domaintransfer',
        'renew'    => 'domainrenew',
    ];

    $pricingType = $pricingTypeMap[$domainType] ?? 'domainregister';

    $priceRow = Capsule::table('tblpricing')
        ->where('type', $pricingType)
        ->where('relid', $tldRow->id)
        ->where('currency', $currencyId)
        ->first();

    if (!$priceRow) {
        return 0;
    }

    $yearColumns = [
        1 => 'msetupfee',
        2 => 'qsetupfee',
        3 => 'ssetupfee',
        4 => 'asetupfee',
        5 => 'bsetupfee',
        6 => 'tsetupfee',
    ];

    if (
        isset($yearColumns[$years])
        && isset($priceRow->{$yearColumns[$years]})
        && (float) $priceRow->{$yearColumns[$years]} > 0
    ) {
        return (float) $priceRow->{$yearColumns[$years]};
    }

    if (isset($priceRow->msetupfee) && (float) $priceRow->msetupfee > 0) {
        return (float) $priceRow->msetupfee * $years;
    }

    return 0;
}

function domainbundlediscount_get_domain_name(array $domain)
{
    if (!empty($domain['domain'])) {
        return strtolower(trim($domain['domain']));
    }

    if (!empty($domain['sld']) && !empty($domain['tld'])) {
        return strtolower(trim($domain['sld'] . $domain['tld']));
    }

    return '';
}

function domainbundlediscount_get_tld_from_domain($domainName, array $eligibleTlds)
{
    $domainName = strtolower(trim($domainName));

    usort($eligibleTlds, function ($a, $b) {
        return strlen($b) <=> strlen($a);
    });

    foreach ($eligibleTlds as $tld) {
        $tld = strtolower(trim($tld));

        if ($tld && $tld[0] !== '.') {
            $tld = '.' . $tld;
        }

        if (substr($domainName, -strlen($tld)) === $tld) {
            return $tld;
        }
    }

    return '';
}

function domainbundlediscount_in_array_ci($needle, $haystack)
{
    $needle = strtolower((string) $needle);

    foreach ($haystack as $item) {
        if (strtolower((string) $item) === $needle) {
            return true;
        }
    }

    return false;
}

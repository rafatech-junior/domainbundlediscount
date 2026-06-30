<?php

if (!defined("WHMCS")) {
    die("This file cannot be accessed directly");
}

use WHMCS\Database\Capsule;

function domainbundlediscount_config()
{
    return [
        'name'        => 'Domain Bundle Discount',
        'description' => 'Discounts a domain when bundled with an eligible hosting product/billing cycle.',
        'version'     => '1.1.0',
        'author'      => 'Mohd Farihan',
        'fields'      => [],
    ];
}

function domainbundlediscount_activate()
{
    try {
        domainbundlediscount_ensure_table();

        return [
            'status' => 'success',
            'description' => 'Domain Bundle Discount activated successfully.',
        ];
    } catch (\Exception $e) {
        return [
            'status' => 'error',
            'description' => 'Activation error: ' . $e->getMessage(),
        ];
    }
}

function domainbundlediscount_deactivate()
{
    return [
        'status' => 'success',
        'description' => 'Domain Bundle Discount deactivated.',
    ];
}

function domainbundlediscount_ensure_table()
{
    if (!Capsule::schema()->hasTable('mod_domainbundlediscount')) {
        Capsule::schema()->create('mod_domainbundlediscount', function ($table) {
            $table->increments('id');
            $table->text('product_ids')->nullable();
            $table->text('billing_cycles')->nullable();
            $table->text('tlds')->nullable();
            $table->text('domain_actions')->nullable();
            $table->decimal('discount_percent', 5, 2)->default(0);
            $table->tinyInteger('status')->default(1);
            $table->timestamps();
        });

        Capsule::table('mod_domainbundlediscount')->insert([
            'product_ids'      => json_encode([]),
            'billing_cycles'   => json_encode([]),
            'tlds'             => json_encode([]),
            'domain_actions'   => json_encode(['register']),
            'discount_percent' => 0,
            'status'           => 0,
            'created_at'       => date('Y-m-d H:i:s'),
            'updated_at'       => date('Y-m-d H:i:s'),
        ]);

        return;
    }

    $columns = [
        'product_ids'      => function ($table) { $table->text('product_ids')->nullable(); },
        'billing_cycles'   => function ($table) { $table->text('billing_cycles')->nullable(); },
        'tlds'             => function ($table) { $table->text('tlds')->nullable(); },
        'domain_actions'   => function ($table) { $table->text('domain_actions')->nullable(); },
        'discount_percent' => function ($table) { $table->decimal('discount_percent', 5, 2)->default(0); },
        'status'           => function ($table) { $table->tinyInteger('status')->default(1); },
        'created_at'       => function ($table) { $table->timestamp('created_at')->nullable(); },
        'updated_at'       => function ($table) { $table->timestamp('updated_at')->nullable(); },
    ];

    foreach ($columns as $columnName => $addColumn) {
        if (!Capsule::schema()->hasColumn('mod_domainbundlediscount', $columnName)) {
            Capsule::schema()->table('mod_domainbundlediscount', function ($table) use ($addColumn) {
                $addColumn($table);
            });
        }
    }

    if (!Capsule::table('mod_domainbundlediscount')->first()) {
        Capsule::table('mod_domainbundlediscount')->insert([
            'product_ids'      => json_encode([]),
            'billing_cycles'   => json_encode([]),
            'tlds'             => json_encode([]),
            'domain_actions'   => json_encode(['register']),
            'discount_percent' => 0,
            'status'           => 0,
            'created_at'       => date('Y-m-d H:i:s'),
            'updated_at'       => date('Y-m-d H:i:s'),
        ]);
    }
}

function domainbundlediscount_output($vars)
{
    $modulelink = $vars['modulelink'];

    domainbundlediscount_ensure_table();

    if (isset($_POST['dbd_save'])) {
        $productIds = isset($_POST['product_ids']) ? array_map('intval', $_POST['product_ids']) : [];
        $billingCycles = isset($_POST['billing_cycles']) ? array_map('trim', $_POST['billing_cycles']) : [];
        $tlds = isset($_POST['tlds']) ? array_map('trim', $_POST['tlds']) : [];
        $domainActions = isset($_POST['domain_actions']) ? array_map('trim', $_POST['domain_actions']) : [];
        $discount = isset($_POST['discount_percent']) ? (float) $_POST['discount_percent'] : 0;
        $status = isset($_POST['status']) ? 1 : 0;

        $allowedActions = ['register', 'transfer', 'renew'];
        $domainActions = array_values(array_intersect($domainActions, $allowedActions));

        if ($discount < 0) {
            $discount = 0;
        }

        if ($discount > 100) {
            $discount = 100;
        }

        $row = Capsule::table('mod_domainbundlediscount')->first();

        $data = [
            'product_ids'      => json_encode($productIds),
            'billing_cycles'   => json_encode($billingCycles),
            'tlds'             => json_encode($tlds),
            'domain_actions'   => json_encode($domainActions),
            'discount_percent' => $discount,
            'status'           => $status,
            'updated_at'       => date('Y-m-d H:i:s'),
        ];

        if ($row) {
            Capsule::table('mod_domainbundlediscount')->where('id', $row->id)->update($data);
        } else {
            $data['created_at'] = date('Y-m-d H:i:s');
            Capsule::table('mod_domainbundlediscount')->insert($data);
        }

        echo '<div class="alert alert-success">Settings saved successfully.</div>';
    }

    $settings = Capsule::table('mod_domainbundlediscount')->first();

    $savedProductIds = $settings ? json_decode($settings->product_ids, true) ?: [] : [];
    $savedBillingCycles = $settings ? json_decode($settings->billing_cycles, true) ?: [] : [];
    $savedTlds = $settings ? json_decode($settings->tlds, true) ?: [] : [];
    $savedDomainActions = $settings ? json_decode($settings->domain_actions, true) ?: [] : ['register'];
    $savedDiscount = $settings ? $settings->discount_percent : 0;
    $savedStatus = $settings ? (int) $settings->status : 0;

    $products = Capsule::table('tblproducts')
        ->select('tblproducts.id', 'tblproducts.name', 'tblproductgroups.name as groupname')
        ->leftJoin('tblproductgroups', 'tblproducts.gid', '=', 'tblproductgroups.id')
        ->orderBy('tblproductgroups.order')
        ->orderBy('tblproducts.order')
        ->get();

    $productsByGroup = [];

    foreach ($products as $p) {
        $group = $p->groupname ?: 'Ungrouped';
        $productsByGroup[$group][] = $p;
    }

    $billingCycleOptions = [
        'Monthly'       => 'Monthly',
        'Quarterly'     => 'Quarterly',
        'Semi-Annually' => 'Semi-Annually',
        'Annually'      => 'Annually',
        'Biennially'    => 'Biennially',
        'Triennially'   => 'Triennially',
    ];

    $domainActionOptions = [
        'register' => 'New Domain Registration',
        'transfer' => 'Domain Transfer',
        'renew'    => 'Domain Renewal',
    ];

    $tldRows = Capsule::table('tbldomainpricing')
        ->select('extension')
        ->orderBy('extension')
        ->get();

    $tldOptions = [];

    foreach ($tldRows as $t) {
        $tldOptions[] = $t->extension;
    }

    ob_start();
    ?>

    <style>
        .dbd-box {
            background: #fff;
            border: 1px solid #e3e3e3;
            border-radius: 4px;
            padding: 20px;
            margin-bottom: 20px;
        }

        .dbd-box h3 {
            margin-top: 0;
        }

        .dbd-checklist {
            max-height: 260px;
            overflow-y: auto;
            border: 1px solid #ddd;
            border-radius: 4px;
            padding: 10px;
            background: #fafafa;
        }

        .dbd-checklist .group-title {
            font-weight: bold;
            margin-top: 8px;
        }

        .dbd-search {
            margin-bottom: 8px;
        }

        select[multiple] {
            height: 240px;
        }

        .dbd-example {
            color: #666;
            font-size: 12px;
        }
    </style>

    <div class="dbd-box">
        <h3>Domain Bundle Discount - Configuration</h3>
        <p class="dbd-example">
            Example: Annual hosting + .com registration/transfer/renewal = one-time 50% domain discount.
        </p>

        <form method="post" action="<?= htmlspecialchars($modulelink) ?>">

            <div class="form-group">
                <label>
                    <input type="checkbox" name="status" value="1" <?= $savedStatus ? 'checked' : '' ?>>
                    Enable Domain Bundle Discount
                </label>
            </div>

            <div class="row">
                <div class="col-md-4">
                    <label><strong>Eligible Hosting Products</strong></label>
                    <input type="text" class="form-control dbd-search" placeholder="Filter products..." onkeyup="dbdFilterChecklist(this,'dbd-product-list')">

                    <div class="dbd-checklist" id="dbd-product-list">
                        <?php foreach ($productsByGroup as $groupName => $groupProducts): ?>
                            <div class="group-title"><?= htmlspecialchars($groupName) ?></div>

                            <?php foreach ($groupProducts as $p): ?>
                                <div class="dbd-item">
                                    <label>
                                        <input type="checkbox" name="product_ids[]" value="<?= (int) $p->id ?>"
                                            <?= in_array((int) $p->id, $savedProductIds) ? 'checked' : '' ?>>
                                        <?= htmlspecialchars($p->name) ?> (ID: <?= (int) $p->id ?>)
                                    </label>
                                </div>
                            <?php endforeach; ?>

                        <?php endforeach; ?>
                    </div>
                </div>

                <div class="col-md-4">
                    <label><strong>Eligible Billing Cycles</strong></label>
                    <select name="billing_cycles[]" multiple class="form-control">
                        <?php foreach ($billingCycleOptions as $value => $label): ?>
                            <option value="<?= htmlspecialchars($value) ?>"
                                <?= in_array($value, $savedBillingCycles) ? 'selected' : '' ?>>
                                <?= htmlspecialchars($label) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <p class="help-block">Ctrl/Cmd+Click to select multiple cycles.</p>
                </div>

                <div class="col-md-4">
                    <label><strong>Eligible TLDs</strong></label>
                    <input type="text" class="form-control dbd-search" placeholder="Search TLD..." onkeyup="dbdFilterSelect(this,'dbd-tld-select')">

                    <select name="tlds[]" id="dbd-tld-select" multiple class="form-control">
                        <?php foreach ($tldOptions as $tld): ?>
                            <option value="<?= htmlspecialchars($tld) ?>"
                                <?= in_array($tld, $savedTlds) ? 'selected' : '' ?>>
                                <?= htmlspecialchars($tld) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>

                    <p class="help-block">Ctrl/Cmd+Click to select multiple TLDs.</p>
                </div>
            </div>

            <hr>

            <div class="form-group">
                <label><strong>Eligible Domain Actions</strong></label>

                <?php foreach ($domainActionOptions as $value => $label): ?>
                    <div>
                        <label>
                            <input type="checkbox" name="domain_actions[]" value="<?= htmlspecialchars($value) ?>"
                                <?= in_array($value, $savedDomainActions) ? 'checked' : '' ?>>
                            <?= htmlspecialchars($label) ?>
                        </label>
                    </div>
                <?php endforeach; ?>

                <p class="help-block">Select which domain actions can receive the one-time discount.</p>
            </div>

            <div class="form-group">
                <label><strong>Discount Percentage (%)</strong></label>

                <input type="number" step="0.01" min="0" max="100" name="discount_percent"
                       class="form-control" style="max-width:150px;"
                       value="<?= htmlspecialchars($savedDiscount) ?>">

                <p class="help-block">
                    One-time percentage discount applied only on the current cart/invoice.
                    It does not apply to future recurring invoices.
                </p>
            </div>

            <button type="submit" name="dbd_save" value="1" class="btn btn-primary">
                Save Settings
            </button>
        </form>
    </div>

    <script>
        function dbdFilterChecklist(input, listId) {
            var filter = input.value.toLowerCase();
            var list = document.getElementById(listId);
            var items = list.getElementsByClassName('dbd-item');

            for (var i = 0; i < items.length; i++) {
                var text = items[i].textContent || items[i].innerText;
                items[i].style.display = text.toLowerCase().indexOf(filter) > -1 ? '' : 'none';
            }
        }

        function dbdFilterSelect(input, selectId) {
            var filter = input.value.toLowerCase();
            var select = document.getElementById(selectId);
            var options = select.getElementsByTagName('option');

            for (var i = 0; i < options.length; i++) {
                var text = options[i].textContent || options[i].innerText;
                options[i].style.display = text.toLowerCase().indexOf(filter) > -1 ? '' : 'none';
            }
        }
    </script>

    <?php
    echo ob_get_clean();
}

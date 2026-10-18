<?php
// Staff roles and permissions for the store panel. Stored as JSON in users.permissions.
// users.role keeps its old values: admin = Owner, editor = Manager, author = Billing staff.

const STORE_ROLES = [
    'admin'  => 'Owner',
    'editor' => 'Manager',
    'author' => 'Billing staff',
];

/** Every switch, all off. Group => [key => label]; a string value is a single top-level switch. */
function store_permission_labels(): array {
    return [
        'dashboard_access' => 'Open the store panel',
        'ecommerce' => [
            'manage_billing'    => 'Billing / POS and sales history',
            'manage_customers'  => 'Customers',
            'manage_credits'    => 'Udhaar and due collection',
            'manage_products'   => 'Products, stock, barcodes, GST rates',
            'manage_categories' => 'Categories and sub-categories',
            'manage_coupons'    => 'Coupons',
            'manage_orders'     => 'Bill details (view, change status, delete)',
            'manage_payment'    => 'Business settings (name, invoice, logo)',
        ],
        'users'    => ['create' => 'Add staff', 'edit' => 'Edit staff', 'delete' => 'Remove staff'],
        'security' => ['view_logs' => 'Activity log'],
    ];
}

function store_permission_template(bool $value = false): array {
    $out = [];
    foreach (store_permission_labels() as $k => $v) {
        $out[$k] = is_array($v) ? array_fill_keys(array_keys($v), $value) : $value;
    }
    return $out;
}

/** Default switches for a role. */
function store_role_preset(string $role): array {
    if ($role === 'admin') return store_permission_template(true);
    $p = store_permission_template(false);
    $p['dashboard_access'] = true;
    $p['ecommerce']['manage_billing']   = true;
    $p['ecommerce']['manage_customers'] = true;
    $p['ecommerce']['manage_credits']   = true;
    if ($role === 'editor') {
        $p['ecommerce']['manage_products']   = true;
        $p['ecommerce']['manage_categories'] = true;
        $p['ecommerce']['manage_coupons']    = true;
        $p['ecommerce']['manage_orders']     = true;
    }
    return $p;
}

/** Read ticked boxes from a form (permissions[group][key]) into the full structure. */
function store_permissions_from_post(array $post): array {
    $p = store_permission_template(false);
    foreach ($p as $k => $v) {
        if (is_array($v)) {
            foreach ($v as $sub => $_) $p[$k][$sub] = !empty($post[$k][$sub]);
        } else {
            $p[$k] = !empty($post[$k]);
        }
    }
    return $p;
}

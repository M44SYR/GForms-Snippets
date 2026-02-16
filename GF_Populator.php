<?php
/**
 * Gravity Forms: Master Populator (Server-side, Multi-page dependent dropdowns)
 * M44SYR
 *
 * Area (Page 1) dropdown lists roles by prefix (e.g. area_1..area_9)
 * Store (Page 2) dropdown populates users in selected Area role after clicking Next
 * Populate hidden/read-only fields from selected Store's:
 *    - core WP user fields (core:user_email, core:display_name, core:user_login)
 *    - ACF/user meta fields (meta:postcode, meta:manager_email, etc.)
 *
 * Extra population modes (optional):
 *   - users-domain:example.com         => list users by email domain (value=email)
 *   - users-role:role_slug            => list users in one role (value=email)
 *   - users-role-any:r1,r2,r3         => list users in any roles (value=email)
 *
 * Field CSS Classes:
 *
 * Area field (Page 1):
 *   dep-area roles-prefix:area_
 *
 * Store field (Page 2):
 *   dep-store stores-by:role
 *
 * Any field you want populated from the selected Store user (same page or later render):
 *   populate-from-store core:user_email
 *   populate-from-store core:display_name
 *   populate-from-store core:user_login
 *   populate-from-store meta:postcode
 *   populate-from-store meta:manager_email
 *
 * Notes:
 * - Excludes role slug: area_leaders
 * - Placeholders use value '' (blank)
 * - Store dropdown values are EMAIL by default (best for notifications)
 */

add_filter('gform_pre_render', 'gf_master_populator');
add_filter('gform_pre_validation', 'gf_master_populator');
add_filter('gform_pre_submission_filter', 'gf_master_populator');
add_filter('gform_admin_pre_render', 'gf_master_populator');

function gf_master_populator($form) {

    $form = gf_apply_generic_user_populators($form);
    $form = gf_apply_area_store_dependency($form);
    $form = gf_apply_store_value_population($form);

    return $form;
}

/* =====================================================
   (A) Generic dropdown populators (any field)
===================================================== */

function gf_apply_generic_user_populators($form) {

    foreach ($form['fields'] as &$field) {

        if (empty($field->cssClass) || !property_exists($field, 'choices')) {
            continue;
        }

        $css = trim((string) rgar($field, 'cssClass'));
        if ($css === '') continue;

        // users-domain:example.com
        $domain = gf_extract_css_value($css, 'users-domain:');
        if ($domain) {
            $choices = gf_build_user_choices([
                'domain' => $domain,
                'value'  => 'email',
            ]);
            array_unshift($choices, ['text' => 'Select…', 'value' => '']);
            $field->choices = $choices;
            continue;
        }

        // users-role:role_slug
        $role = gf_extract_css_value($css, 'users-role:');
        if ($role) {
            $choices = gf_build_user_choices([
                'roles' => [sanitize_key($role)],
                'value' => 'email',
            ]);
            array_unshift($choices, ['text' => 'Select…', 'value' => '']);
            $field->choices = $choices;
            continue;
        }

        // users-role-any:r1,r2,r3
        $roles_csv = gf_extract_css_value($css, 'users-role-any:');
        if ($roles_csv) {
            $roles = array_filter(array_map('sanitize_key', array_map('trim', explode(',', $roles_csv))));
            $choices = gf_build_user_choices([
                'roles' => $roles,
                'value' => 'email',
            ]);
            array_unshift($choices, ['text' => 'Select…', 'value' => '']);
            $field->choices = $choices;
            continue;
        }
    }

    return $form;
}

function gf_build_user_choices($args) {

    $roles  = !empty($args['roles']) ? (array)$args['roles'] : [];
    $domain = !empty($args['domain']) ? strtolower(trim($args['domain'])) : '';
    $value_mode = !empty($args['value']) ? $args['value'] : 'email'; // email|id

    $query = [
        'orderby' => 'display_name',
        'order'   => 'ASC',
        'fields'  => ['ID', 'display_name', 'user_email'],
        'number'  => 9999,
    ];

    if (!empty($roles)) {
        $query['role__in'] = $roles;
    }

    $users = get_users($query);
    $choices = [];
    $seen = [];

    foreach ($users as $u) {

        $email = strtolower(trim($u->user_email));
        if (!$email) continue;

        if ($domain) {
            $email_domain = substr(strrchr($email, "@"), 1);
            if ($email_domain !== $domain) continue;
        }

        if (isset($seen[$email])) continue;
        $seen[$email] = true;

        $value = ($value_mode === 'id') ? (string)$u->ID : (string)$email;

        $choices[] = [
            'text'  => $u->display_name ?: $email,
            'value' => $value,
        ];
    }

    return $choices;
}

/* =====================================================
   (B) Multi-page dependency: Area -> Store
===================================================== */

function gf_apply_area_store_dependency($form) {

    $area_field_id  = gf_find_field_id_by_css($form, 'dep-area');
    $store_field_id = gf_find_field_id_by_css($form, 'dep-store');

    if (!$area_field_id || !$store_field_id) {
        return $form;
    }

    // Populate Area roles list
    $area_prefix = gf_extract_css_value_by_field_id($form, $area_field_id, 'roles-prefix:');
    if ($area_prefix) {

        $choices = gf_build_role_choices_by_prefix($area_prefix, ['area_leaders']);
        array_unshift($choices, ['text' => 'Select an area…', 'value' => '']);

        $form = gf_set_field_choices($form, $area_field_id, $choices);
    }

    // Populate Store choices based on selected Area role (after Next)
    $selected_role = sanitize_key(rgpost('input_' . $area_field_id));

    if (!$selected_role) {
        return gf_set_field_choices($form, $store_field_id, [
            ['text' => 'Select an area first…', 'value' => '']
        ]);
    }

    if (strpos($selected_role, 'area_') !== 0 || $selected_role === 'area_leaders') {
        return gf_set_field_choices($form, $store_field_id, [
            ['text' => 'Invalid area selected', 'value' => '']
        ]);
    }

    $users = get_users([
        'role'    => $selected_role,
        'orderby' => 'display_name',
        'order'   => 'ASC',
        'fields'  => ['ID', 'display_name', 'user_email'],
        'number'  => 9999,
    ]);

    $store_choices = [];
    $store_choices[] = ['text' => 'Select a store…', 'value' => ''];

    foreach ($users as $u) {
        // Store value as EMAIL (best for notifications)
        $store_choices[] = [
            'text'  => $u->display_name,
            'value' => (string) $u->user_email,
        ];
    }

    return gf_set_field_choices($form, $store_field_id, $store_choices);
}

/* =====================================================
   (C) Populate fields from selected Store user
   Supports:
     - core: user object properties (user_email, display_name, user_login)
     - meta: ACF/user meta keys (postcode, manager_email, etc.)
===================================================== */

function gf_apply_store_value_population($form) {

    $store_field_id = gf_find_field_id_by_css($form, 'dep-store');
    if (!$store_field_id) return $form;

    $email = sanitize_email(rgpost('input_' . $store_field_id));
    if (!$email) return $form;

    $user = get_user_by('email', $email);
    if (!$user) return $form;

    foreach ($form['fields'] as &$field) {

        if (empty($field->cssClass)) continue;

        $css = (string) $field->cssClass;
        if (strpos(' ' . $css . ' ', ' populate-from-store ') === false) continue;

        // core:user_email / core:display_name / core:user_login
        $core_key = gf_extract_css_value($css, 'core:');
        if ($core_key) {
            $core_key = preg_replace('/[^a-z0-9_]/i', '', $core_key);

            if (isset($user->$core_key)) {
                $field->defaultValue = (string) $user->$core_key;
                continue;
            }
        }

        // meta:postcode (ACF first, then user meta)
        $meta_key = gf_extract_css_value($css, 'meta:');
        if ($meta_key) {
            $meta_key = sanitize_key($meta_key);

            $value = '';
            if (function_exists('get_field')) {
                $value = get_field($meta_key, 'user_' . $user->ID);
            }
            if ($value === '' || $value === null) {
                $value = get_user_meta($user->ID, $meta_key, true);
            }

            $field->defaultValue = is_array($value) ? implode(', ', $value) : (string)$value;
            continue;
        }
    }

    return $form;
}

/* =====================================================
   Helpers
===================================================== */

function gf_find_field_id_by_css($form, $css_token) {
    foreach ($form['fields'] as $field) {
        $css = ' ' . (string) rgar($field, 'cssClass') . ' ';
        if (strpos($css, ' ' . $css_token . ' ') !== false) {
            return (int) $field->id;
        }
    }
    return 0;
}

function gf_extract_css_value_by_field_id($form, $field_id, $prefix) {
    foreach ($form['fields'] as $field) {
        if ((int) $field->id !== (int) $field_id) continue;
        return gf_extract_css_value((string) rgar($field, 'cssClass'), $prefix);
    }
    return '';
}

function gf_extract_css_value($css, $prefix) {
    $css = trim((string)$css);
    if ($css === '') return '';
    foreach (preg_split('/\s+/', $css) as $class) {
        if (strpos($class, $prefix) === 0) {
            return substr($class, strlen($prefix));
        }
    }
    return '';
}

function gf_build_role_choices_by_prefix($role_prefix, $exclude_slugs = []) {

    $role_prefix = sanitize_key($role_prefix);
    $exclude = array_flip(array_map('sanitize_key', (array)$exclude_slugs));

    $roles_obj = wp_roles();
    $roles = $roles_obj ? $roles_obj->roles : [];

    $choices = [];

    foreach ($roles as $slug => $data) {
        if (strpos($slug, $role_prefix) !== 0) continue;
        if (isset($exclude[$slug])) continue;

        $choices[] = [
            'text'  => $data['name'],
            'value' => $slug,
        ];
    }

    usort($choices, function($a, $b){
        $an = (int) preg_replace('/\D+/', '', $a['value']);
        $bn = (int) preg_replace('/\D+/', '', $b['value']);
        if ($an && $bn && $an !== $bn) return $an <=> $bn;
        return strcmp($a['value'], $b['value']);
    });

    return $choices;
}

function gf_set_field_choices($form, $field_id, $choices) {
    foreach ($form['fields'] as &$field) {
        if ((int) $field->id !== (int) $field_id) continue;

        if (!property_exists($field, 'choices')) break;

        $field->choices = $choices;

        if (method_exists($field, 'get_entry_inputs') && $field->get_entry_inputs()) {
            $inputs = [];
            $i = 1;
            foreach ($choices as $choice) {
                $inputs[] = [
                    'id'    => $field->id . '.' . $i,
                    'label' => $choice['text'],
                ];
                $i++;
            }
            $field->inputs = $inputs;
        }

        break;
    }
    return $form;
}

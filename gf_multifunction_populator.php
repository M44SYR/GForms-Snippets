/*
 * Gravity Forms: Multi-function Populator (All-in-one, flexible) — FULL RESTORE (with Store-ID fix)
 *
 *
 * Designed to solve a common operational problem where Area / Store / Team
 * structures change frequently. The goal was to create a flexible system
 * where organisational changes could be managed through ACF field groups
 * rather than hard-coded values within form logic and notifications.
 *
 * This allows administrators to update structure, users and roles without
 * modifying code, making the system far more resilient to organisational
 * change.
 *
 * Implementation utilised AI-assisted scaffolding for some boilerplate,
 * but the architecture, integration with Gravity Forms hooks, and dynamic
 * population logic were designed and adapted to fit the specific workflow
 * requirements of the business.
 *
 * Fixes / behaviour:
 * - Store dependency dropdown (dep-store dep-users-by-role) stores USER ID (not email)
 * - populate-from-store core:* / meta:* works with USER ID (and remains backward-compatible with historic email values)
 * - Area leader population supports comma-separated email list via core:user_email
 * - Paged form repopulation fixed for all populators in this script by forcing values into $_POST
 *
 * NOTE:
 * - Historic entries that stored email will still resolve user lookups for helper fields.
 * - GravityView label rendering will be more stable when the Store field stores IDs.
 * - Area leader helper currently returns a fake object with user_email only, so
 *   populate-from-area-leader meta:* cannot resolve real user meta unless that helper is redesigned.
 *
 */

/* ------------------------------------------------------------
   Hooks
------------------------------------------------------------ */

add_filter('gform_pre_render', 'gf_master_populator');
add_filter('gform_pre_validation', 'gf_master_populator');
add_filter('gform_pre_submission_filter', 'gf_master_populator');
add_filter('gform_admin_pre_render', 'gf_master_populator');
add_filter('gform_admin_pre_validation', 'gf_master_populator');
add_filter('gform_pre_process', 'gf_master_populator');

function gf_master_populator($form) {
    $form = gf_apply_toolbox_populators($form);
    $form = gf_apply_paged_area_store_dependency($form);
    $form = gf_apply_store_user_field_population($form);
    $form = gf_apply_area_leader_field_population($form);
    $form = gf_apply_loss_prevention_field_population($form);
    return $form;
}

/* ============================================================
   1) TOOLBOX POPULATORS (standalone choice builders)
============================================================ */

function gf_apply_toolbox_populators($form) {

    foreach ($form['fields'] as &$field) {

        if (empty($field->cssClass) || !property_exists($field, 'choices')) {
            continue;
        }

        $css = trim((string) rgar($field, 'cssClass'));
        if ($css === '') continue;

        $tokens = gf_parse_css_tokens_multi($css);

        $has_any =
            !empty($tokens['roles-prefix']) ||
            !empty($tokens['pop-roles-prefix']) ||
            !empty($tokens['pop-roles-include']) ||
            !empty($tokens['pop-roles-exclude']) ||
            !empty($tokens['pop-users-domain']) ||
            !empty($tokens['pop-users-role']) ||
            !empty($tokens['pop-users-role-any']) ||
            !empty($tokens['pop-users-include']) ||
            !empty($tokens['pop-users-exclude']) ||
            !empty($tokens['pop-acf-choices']) ||
            !empty($tokens['pop-acf-choices-key']);

        if (!$has_any) continue;

        $placeholder = gf_last_token_value($tokens, 'pop-placeholder', '');
        $value_mode  = strtolower(gf_last_token_value($tokens, 'pop-value', 'email')); // email|id|login
        $label_mode  = strtolower(gf_last_token_value($tokens, 'pop-label', 'display_name')); // display_name|first_last

        $choices = [];
        $seen = [];

        gf_add_choice($choices, $seen, $placeholder, '');

        /* -----------------------------
           Roles (union)
        ------------------------------ */

        $prefixes = [];
        foreach (gf_collect_raw_tokens($tokens, 'roles-prefix') as $p) {
            $p = sanitize_key($p);
            if ($p !== '') $prefixes[] = $p;
        }
        foreach (gf_collect_raw_tokens($tokens, 'pop-roles-prefix') as $p) {
            $p = sanitize_key($p);
            if ($p !== '') $prefixes[] = $p;
        }
        $prefixes = array_values(array_unique($prefixes));

        $role_includes = array_map('sanitize_key', gf_collect_csv_tokens($tokens, 'pop-roles-include'));
        $role_excludes = array_map('sanitize_key', gf_collect_csv_tokens($tokens, 'pop-roles-exclude'));
        $role_excludes = array_values(array_unique($role_excludes));
        $role_exclude_map = array_flip($role_excludes);

        if (!empty($prefixes) || !empty($role_includes) || !empty($tokens['pop-acf-choices']) || !empty($tokens['pop-acf-choices-key'])) {

            $roles_obj = wp_roles();
            $roles = $roles_obj ? $roles_obj->roles : [];

            foreach ($roles as $slug => $data) {
                $slug = sanitize_key($slug);

                $match_prefix = false;
                foreach ($prefixes as $pref) {
                    if ($pref !== '' && strpos($slug, $pref) === 0) {
                        $match_prefix = true;
                        break;
                    }
                }

                $match_include = in_array($slug, $role_includes, true);

                if (!$match_prefix && !$match_include) continue;
                if (isset($role_exclude_map[$slug])) continue;

                gf_add_choice($choices, $seen, (string)$data['name'], $slug);
            }

            foreach (gf_collect_raw_tokens($tokens, 'pop-acf-choices-key') as $acf_key) {
                $acf_key = trim((string)$acf_key);
                if ($acf_key === '') continue;
                $acf_choices = gf_get_acf_choices_by_key($acf_key);
                foreach ($acf_choices as $val => $label) {
                    gf_add_choice($choices, $seen, (string)$label, (string)$val);
                }
            }

            foreach (gf_collect_raw_tokens($tokens, 'pop-acf-choices') as $acf_name) {
                $acf_name = sanitize_key($acf_name);
                if ($acf_name === '') continue;
                $acf_choices = gf_get_acf_choices_by_name_best_effort($acf_name);
                foreach ($acf_choices as $val => $label) {
                    gf_add_choice($choices, $seen, (string)$label, (string)$val);
                }
            }
        }

        /* -----------------------------
           Users (union)
        ------------------------------ */

        $user_excludes = array_flip(array_map('intval', gf_collect_csv_tokens($tokens, 'pop-users-exclude')));
        $include_ids   = array_map('intval', gf_collect_csv_tokens($tokens, 'pop-users-include'));

        $filters = [];

        foreach (gf_collect_raw_tokens($tokens, 'pop-users-domain') as $domain) {
            $domain = strtolower(trim((string)$domain));
            if ($domain !== '') $filters[] = ['type' => 'domain', 'value' => $domain];
        }

        foreach (gf_collect_raw_tokens($tokens, 'pop-users-role') as $role) {
            $role = sanitize_key($role);
            if ($role !== '') $filters[] = ['type' => 'role', 'value' => $role];
        }

        $any_roles = gf_collect_csv_tokens($tokens, 'pop-users-role-any');
        if (!empty($any_roles)) {
            $filters[] = ['type' => 'role_any', 'value' => array_map('sanitize_key', $any_roles)];
        }

        if (!empty($include_ids)) {
            $filters[] = ['type' => 'include_ids', 'value' => $include_ids];
        }

        foreach ($filters as $f) {

            $query = [
                'orderby' => 'display_name',
                'order'   => 'ASC',
                'fields'  => ['ID', 'display_name', 'user_email', 'user_login'],
                'number'  => 9999,
            ];

            if ($f['type'] === 'role') {
                $query['role'] = $f['value'];
            } elseif ($f['type'] === 'role_any') {
                $query['role__in'] = $f['value'];
            } elseif ($f['type'] === 'include_ids') {
                $query['include'] = $f['value'];
            }

            $users = get_users($query);

            foreach ($users as $u) {

                $uid = (int)$u->ID;
                if (isset($user_excludes[$uid])) continue;

                if ($f['type'] === 'domain') {
                    $email = strtolower(trim((string)$u->user_email));
                    $d = $email ? substr(strrchr($email, "@"), 1) : '';
                    if ($d !== $f['value']) continue;
                }

                $label = gf_user_label($u, $label_mode);
                $value = gf_user_value($u, $value_mode);

                gf_add_choice($choices, $seen, $label, $value);
            }
        }

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
    }

    return $form;
}

/* ============================================================
   2) OPTIONAL PAGED DEPENDENCY: dep-area -> dep-store
============================================================ */

function gf_apply_paged_area_store_dependency($form) {

    $area_id  = gf_find_field_id_by_css_token($form, 'dep-area');
    $store_id = gf_find_field_id_by_css_token($form, 'dep-store');

    // Populate area list if dep-area exists
    if ($area_id) {
        $area_css = gf_get_field_css($form, $area_id);
        $tokens = gf_parse_css_tokens_multi($area_css);

        $prefixes = [];
        foreach (gf_collect_raw_tokens($tokens, 'roles-prefix') as $p) {
            $p = sanitize_key($p);
            if ($p !== '') $prefixes[] = $p;
        }
        foreach (gf_collect_raw_tokens($tokens, 'pop-roles-prefix') as $p) {
            $p = sanitize_key($p);
            if ($p !== '') $prefixes[] = $p;
        }
        $prefixes = array_values(array_unique($prefixes));

        $include = array_map('sanitize_key', gf_collect_csv_tokens($tokens, 'pop-roles-include'));
        $exclude = array_map('sanitize_key', gf_collect_csv_tokens($tokens, 'pop-roles-exclude'));

        $exclude[] = 'area_leaders';
        $exclude = array_values(array_unique($exclude));

        $role_choices = gf_build_role_choices_by_prefixes_union($prefixes, $include, $exclude);

        foreach (gf_collect_raw_tokens($tokens, 'pop-acf-choices-key') as $acf_key) {
            $acf_key = trim((string)$acf_key);
            if ($acf_key === '') continue;
            $acf_choices = gf_get_acf_choices_by_key($acf_key);
            foreach ($acf_choices as $val => $label) {
                $role_choices[] = ['text' => (string)$label, 'value' => (string)$val];
            }
        }

        foreach (gf_collect_raw_tokens($tokens, 'pop-acf-choices') as $acf_name) {
            $acf_name = sanitize_key($acf_name);
            if ($acf_name === '') continue;
            $acf_choices = gf_get_acf_choices_by_name_best_effort($acf_name);
            foreach ($acf_choices as $val => $label) {
                $role_choices[] = ['text' => (string)$label, 'value' => (string)$val];
            }
        }

        array_unshift($role_choices, ['text' => '', 'value' => '']);
        $form = gf_set_field_choices_simple($form, $area_id, $role_choices);
    }

    if (!$area_id || !$store_id) return $form;

    $store_css = gf_get_field_css($form, $store_id);
    if (strpos(' ' . $store_css . ' ', ' dep-users-by-role ') === false) {
        return $form;
    }

    $selected_role = sanitize_key(rgpost('input_' . $area_id));
    if (!$selected_role) {
        return gf_set_field_choices_simple($form, $store_id, [
            ['text' => '', 'value' => '']
        ]);
    }

    $users = get_users([
        'role'    => $selected_role,
        'orderby' => 'display_name',
        'order'   => 'ASC',
        'fields'  => ['ID', 'display_name', 'user_email'],
        'number'  => 9999,
    ]);

    $choices = [];
    $choices[] = ['text' => '', 'value' => ''];

    foreach ($users as $u) {
        $choices[] = [
            'text'  => (string)$u->display_name,
            'value' => (string)$u->ID,
        ];
    }

    return gf_set_field_choices_simple($form, $store_id, $choices);
}

/* ============================================================
   3) POPULATE FIELDS FROM SELECTED STORE USER (core/meta)
============================================================ */

function gf_apply_store_user_field_population($form) {

    $store_id = gf_find_field_id_by_css_token($form, 'dep-store');
    if (!$store_id) return $form;

    $store_value = rgpost('input_' . $store_id);
    if (!$store_value) return $form;

    $user = null;

    if (ctype_digit((string)$store_value)) {
        $user = get_user_by('id', (int)$store_value);
    } else {
        $email = sanitize_email($store_value);
        if ($email) {
            $user = get_user_by('email', $email);
        }
    }

    if (!$user) return $form;

    foreach ($form['fields'] as &$field) {

        $css = (string) rgar($field, 'cssClass');
        if ($css === '') continue;

        if (strpos(' ' . $css . ' ', ' populate-from-store ') === false) continue;

        $core_key = gf_extract_css_value($css, 'core:');
        if ($core_key) {
            $core_key = preg_replace('/[^a-z0-9_]/i', '', $core_key);
            if (isset($user->$core_key)) {
                $value = (string)$user->$core_key;
                gf_force_field_value($field, $value);
                continue;
            }
        }

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

            $value = is_array($value) ? implode(', ', $value) : (string)$value;
            gf_force_field_value($field, $value);
            continue;
        }
    }

    return $form;
}

/* ============================================================
   4) POPULATE FIELDS FROM AREA LEADER (profile wildcard match)
============================================================ */

function gf_apply_area_leader_field_population($form) {

    $area_id = gf_find_field_id_by_css_token($form, 'dep-area');
    if (!$area_id) return $form;

    $selected_role = sanitize_key(rgpost('input_' . $area_id));
    if (!$selected_role) return $form;

    $roles_obj = wp_roles();
    $roles = $roles_obj ? $roles_obj->roles : [];
    $area_label = isset($roles[$selected_role]['name']) ? (string)$roles[$selected_role]['name'] : '';
    if ($area_label === '') return $form;

    foreach ($form['fields'] as &$field) {

        $css = (string) rgar($field, 'cssClass');
        if ($css === '') continue;

        if (strpos(' ' . $css . ' ', ' populate-from-area-leader ') === false) continue;

        $leader_key = gf_extract_css_value($css, 'leader-key:');
        $leader_key = $leader_key ? sanitize_key($leader_key) : 'area';

        $leader = gf_find_area_leader_by_profile_match($area_label, $leader_key);

        if (!$leader) {
            gf_force_field_value($field, '');
            continue;
        }

        $core_key = gf_extract_css_value($css, 'core:');
        if ($core_key) {
            $core_key = preg_replace('/[^a-z0-9_]/i', '', $core_key);
            if (isset($leader->$core_key)) {
                $value = (string)$leader->$core_key;
                gf_force_field_value($field, $value);
                continue;
            }
        }

        $meta_key = gf_extract_css_value($css, 'meta:');
        if ($meta_key) {
            // Current helper returns a fake object with user_email only.
            // Preserve tag support, but avoid stale values on page changes.
            if (!isset($leader->ID)) {
                gf_force_field_value($field, '');
                continue;
            }

            $meta_key = sanitize_key($meta_key);

            $value = '';
            if (function_exists('get_field')) {
                $value = get_field($meta_key, 'user_' . $leader->ID);
            }
            if ($value === '' || $value === null) {
                $value = get_user_meta($leader->ID, $meta_key, true);
            }

            $value = is_array($value) ? implode(', ', $value) : (string)$value;
            gf_force_field_value($field, $value);
            continue;
        }
    }

    return $form;
}

function gf_find_area_leader_by_profile_match($area_label, $leader_key) {

    $area_norm = gf_normalise_text($area_label);

    $leaders = get_users([
        'role'    => 'area_leaders',
        'orderby' => 'display_name',
        'order'   => 'ASC',
        'fields'  => ['ID', 'user_email', 'display_name', 'user_login'],
        'number'  => 9999,
    ]);

    $matches = [];

    foreach ($leaders as $u) {

        $val = '';

        if (function_exists('get_field')) {
            $val = get_field($leader_key, 'user_' . $u->ID);
        }
        if ($val === '' || $val === null) {
            $val = get_user_meta($u->ID, $leader_key, true);
        }

        if (!is_string($val) || $val === '') continue;

        $val_norm = gf_normalise_text($val);

        if (strpos($val_norm, $area_norm) === 0) {
            if (!empty($u->user_email)) {
                $matches[] = $u->user_email;
            }
        }
    }

    if (empty($matches)) {
        return null;
    }

    $matches = array_values(array_unique($matches));

    // Fake WP_User-like object containing comma-separated emails.
    return (object)[
        'user_email' => implode(', ', $matches)
    ];
}

function gf_normalise_text($text) {
    $text = strtolower(trim((string)$text));
    $text = preg_replace('/\s+/', ' ', $text);
    return $text;
}

/* ============================================================
   5) POPULATE FIELDS FROM SELECTED LOSS PREVENTION USER (core/meta)
============================================================ */

function gf_apply_loss_prevention_field_population($form) {

    $lp_id = gf_find_field_id_by_css_token($form, 'dep-loss-prevention');
    if (!$lp_id) return $form;

    $lp_value = rgpost('input_' . $lp_id);
    if (!$lp_value) return $form;

    $user = ctype_digit((string)$lp_value)
        ? get_user_by('id', (int)$lp_value)
        : get_user_by('email', sanitize_email($lp_value));

    if (!$user) return $form;

    foreach ($form['fields'] as &$field) {

        $css = (string) rgar($field, 'cssClass');
        if ($css === '') continue;

        if (strpos(' ' . $css . ' ', ' populate-from-loss-prevention ') === false) continue;

        $core_key = gf_extract_css_value($css, 'core:');
        if ($core_key) {
            $core_key = preg_replace('/[^a-z0-9_]/i', '', $core_key);
            if (isset($user->$core_key)) {
                $value = (string)$user->$core_key;
                gf_force_field_value($field, $value);
                continue;
            }
        }

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

            $value = is_array($value) ? implode(', ', $value) : (string)$value;
            gf_force_field_value($field, $value);
            continue;
        }
    }

    return $form;
}

/* ============================================================
   Helpers (multi-token aware)
============================================================ */

function gf_parse_css_tokens_multi($css) {

    $tokens = [];

    foreach (preg_split('/\s+/', trim((string)$css)) as $part) {

        if (strpos($part, ':') === false) continue;

        [$key, $value] = explode(':', $part, 2);

        $key = trim($key);
        $value = trim($value);

        if ($key === '') continue;

        if (!isset($tokens[$key])) {
            $tokens[$key] = [];
        }

        $tokens[$key][] = $value;
    }

    return $tokens;
}

function gf_last_token_value($tokens, $key, $default = '') {
    if (empty($tokens[$key])) return $default;
    return (string) end($tokens[$key]);
}

function gf_collect_raw_tokens($tokens, $key) {
    if (empty($tokens[$key])) return [];
    return array_values(array_filter(array_map('trim', (array)$tokens[$key])));
}

function gf_collect_csv_tokens($tokens, $key) {

    $out = [];

    if (empty($tokens[$key])) return $out;

    foreach ((array)$tokens[$key] as $entry) {
        $entry = trim((string)$entry);
        if ($entry === '') continue;
        $parts = preg_split('/[,\|]+/', $entry);
        foreach ($parts as $p) {
            $p = trim($p);
            if ($p === '') continue;
            $out[] = $p;
        }
    }

    return array_values(array_filter($out));
}

function gf_extract_css_value($css, $prefix) {
    foreach (preg_split('/\s+/', trim((string)$css)) as $class) {
        if (strpos($class, $prefix) === 0) {
            return substr($class, strlen($prefix));
        }
    }
    return '';
}

function gf_add_choice(&$choices, &$seen, $text, $value) {
    $key = (string)$value . '|' . (string)$text;
    if (isset($seen[$key])) return;
    $seen[$key] = true;
    $choices[] = ['text' => (string)$text, 'value' => (string)$value];
}

function gf_user_value($u, $mode) {
    $mode = strtolower((string)$mode);
    if ($mode === 'id') return (string)$u->ID;
    if ($mode === 'login') return (string)$u->user_login;
    return (string)$u->user_email;
}

function gf_user_label($u, $mode) {
    $mode = strtolower((string)$mode);
    if ($mode === 'first_last') {
        $first = get_user_meta($u->ID, 'first_name', true);
        $last  = get_user_meta($u->ID, 'last_name', true);
        $name = trim($first . ' ' . $last);
        if ($name !== '') return $name;
    }
    return (string)($u->display_name ?: $u->user_email);
}

function gf_find_field_id_by_css_token($form, $token) {
    foreach ($form['fields'] as $f) {
        $css = ' ' . (string) rgar($f, 'cssClass') . ' ';
        if (strpos($css, ' ' . $token . ' ') !== false) return (int)$f->id;
    }
    return 0;
}

function gf_get_field_css($form, $field_id) {
    foreach ($form['fields'] as $f) {
        if ((int)$f->id === (int)$field_id) return (string) rgar($f, 'cssClass');
    }
    return '';
}

function gf_set_field_choices_simple($form, $field_id, $choices) {
    foreach ($form['fields'] as &$f) {
        if ((int)$f->id !== (int)$field_id) continue;
        if (!property_exists($f, 'choices')) break;

        $f->choices = $choices;

        if (method_exists($f, 'get_entry_inputs') && $f->get_entry_inputs()) {
            $inputs = [];
            $i = 1;
            foreach ($choices as $choice) {
                $inputs[] = ['id' => $f->id . '.' . $i, 'label' => $choice['text']];
                $i++;
            }
            $f->inputs = $inputs;
        }

        break;
    }
    return $form;
}

function gf_force_field_value(&$field, $value) {
    $value = is_array($value) ? implode(', ', $value) : (string)$value;
    $field->defaultValue = $value;
    $_POST['input_' . $field->id] = $value;
}

/**
 * Build roles by union:
 * - matches ANY prefix in $prefixes OR included slugs
 * - excluded slugs removed
 */
function gf_build_role_choices_by_prefixes_union($prefixes, $include_slugs, $exclude_slugs) {

    $prefixes = array_values(array_unique(array_map('sanitize_key', (array)$prefixes)));
    $include = array_flip(array_map('sanitize_key', (array)$include_slugs));
    $exclude = array_flip(array_map('sanitize_key', (array)$exclude_slugs));

    $roles_obj = wp_roles();
    $roles = $roles_obj ? $roles_obj->roles : [];

    $choices = [];

    foreach ($roles as $slug => $data) {

        $slug = sanitize_key($slug);

        $match_prefix = false;
        foreach ($prefixes as $pref) {
            if ($pref !== '' && strpos($slug, $pref) === 0) {
                $match_prefix = true;
                break;
            }
        }

        $match_include = isset($include[$slug]);

        if (!$match_prefix && !$match_include) continue;
        if (isset($exclude[$slug])) continue;

        $choices[] = ['text' => (string)$data['name'], 'value' => (string)$slug];
    }

    usort($choices, function($a, $b) {
        $an = (int) preg_replace('/\D+/', '', $a['value']);
        $bn = (int) preg_replace('/\D+/', '', $b['value']);
        if ($an && $bn && $an !== $bn) return $an <=> $bn;
        return strcmp($a['text'], $b['text']);
    });

    return $choices;
}

/* ============================================================
   ACF Helpers (Choices)
============================================================ */

function gf_get_acf_choices_by_key($field_key) {
    if (!function_exists('acf_get_field')) return [];
    $field = acf_get_field($field_key);
    if (!$field || empty($field['choices']) || !is_array($field['choices'])) return [];
    return $field['choices'];
}

function gf_get_acf_choices_by_name_best_effort($field_name) {
    if (!function_exists('acf_get_field')) return [];
    $field = acf_get_field($field_name);
    if (!$field || empty($field['choices']) || !is_array($field['choices'])) return [];
    return $field['choices'];
}
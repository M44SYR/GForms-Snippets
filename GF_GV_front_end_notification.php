
<?php
/**
 * Gravity Forms – Simplified Notification Resend Interface
 *
 * Purpose
 * -------
 * Operational users occasionally needed to resend form notifications for
 * specific entries. However, granting access to the full Gravity Forms
 * entry management interface introduced unnecessary complexity and risk.
 *
 * Approach
 * --------
 * This snippet creates a minimal interface for resending notifications by
 * detecting a custom query parameter and modifying the WordPress admin
 * interface dynamically.
 *
 * The script hides most of the default admin UI elements and isolates the
 * native notification resend functionality, presenting it in a simplified
 * workflow focused on a single task.
 *
 * Key Features
 * ------------
 * - Targeted UI modification via WordPress admin hooks
 * - CSS-based removal of unnecessary admin components
 * - JavaScript DOM manipulation to isolate the resend panel
 * - Focused workflow for a single operational task
 *
 * Outcome
 * -------
 * Users can safely resend notifications without navigating the full admin
 * interface, reducing the risk of unintended actions and improving usability
 * for operational teams.
 *
 * Notes
 * -----
 * AI-assisted development was used to help generate portions of the
 * implementation structure. The workflow design, access strategy and
 * interface behaviour were adapted to meet the operational requirements
 * of the system.
 */
add_action('admin_head', function () {
    if (
        !is_admin()
        || empty($_GET['gv_resend_only'])
        || empty($_GET['page'])
        || $_GET['page'] !== 'gf_entries'
        || empty($_GET['view'])
        || $_GET['view'] !== 'entry'
    ) {
        return;
    }
    ?>
    <style>
        html, body {
            background: #fff !important;
        }

        body.gv-resend-only {
            margin: 0 !important;
            padding: 0 !important;
            overflow: auto;
        }

        body.gv-resend-only #wpadminbar,
        body.gv-resend-only #adminmenumain,
        body.gv-resend-only #screen-meta,
        body.gv-resend-only .notice,
        body.gv-resend-only .update-nag,
        body.gv-resend-only .wrap > h1,
        body.gv-resend-only .wrap > h2,
        body.gv-resend-only .subsubsub,
        body.gv-resend-only .tablenav,
        body.gv-resend-only .gform-settings-save-container,
        body.gv-resend-only #gf_toolbar,
        body.gv-resend-only .entry-sidebar,
        body.gv-resend-only .entry-header,
        body.gv-resend-only .entry-detail-notes,
        body.gv-resend-only .entry-edit-link,
        body.gv-resend-only .entry-view-print-link,
        body.gv-resend-only .entry-view-trash-link,
        body.gv-resend-only .entry-view-spam-link,
        body.gv-resend-only .entry-view-restore-link,
        body.gv-resend-only .entry-view-delete-link {
            display: none !important;
        }

        body.gv-resend-only #wpcontent,
        body.gv-resend-only #wpbody-content,
        body.gv-resend-only .wrap {
            margin: 0 !important;
            padding: 0 !important;
        }

        body.gv-resend-only #gv-native-resend-shell {
            max-width: 760px;
            margin: 20px auto;
            padding: 20px;
        }

        body.gv-resend-only #gv-native-resend-shell h2 {
            margin: 0 0 16px 0;
            font-size: 20px;
        }
    </style>
    <?php
});

add_action('admin_footer', function () {
    if (
        !is_admin()
        || empty($_GET['gv_resend_only'])
        || empty($_GET['page'])
        || $_GET['page'] !== 'gf_entries'
        || empty($_GET['view'])
        || $_GET['view'] !== 'entry'
    ) {
        return;
    }
    ?>
    <script>
    document.addEventListener('DOMContentLoaded', function () {
        document.body.classList.add('gv-resend-only');

        const resendButton = document.querySelector('input[name="notification_resend"]');
        if (!resendButton) return;

        const panel = resendButton.closest('div');
        if (!panel) return;

        const shell = document.createElement('div');
        shell.id = 'gv-native-resend-shell';
        shell.innerHTML = '<h2>Resend Notifications</h2>';
        shell.appendChild(panel);

        document.body.innerHTML = '';
        document.body.appendChild(shell);
    });
    </script>
    <?php
});
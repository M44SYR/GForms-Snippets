<?php
/**
 * Gravity Forms – Link Recovery Tool for secondary froms that use values passed via form confirmation
 *
 * Purpose
 * -------
 * Built to recover and rebuild pre-populated form links
 * from an existing Gravity Forms entry.
 *
 * This solved an operational problem where users needed a quick way to
 * reconstruct a downstream form link without manually re-entering values
 * or attempting to rebuild query parameters by hand.
 *
 * Approach
 * --------
 * The tool accepts a Gravity Forms Entry ID, retrieves the source entry,
 * maps selected field IDs to the required query-string parameter names,
 * and generates a pre-populated link to the target coaching conversation
 * form.
 *
 * Key Features
 * ------------
 * - Retrieves source data directly from Gravity Forms via GFAPI
 * - Maps internal field IDs to meaningful query parameter names
 * - Rebuilds a usable pre-populated form link automatically
 * - Provides a simple front-end interface for operational users
 * - Includes copy/open actions to support fast reuse
 *
 * Outcome
 * -------
 * This reduced manual rework and provided a practical recovery mechanism
 * when a coaching conversation link needed to be recreated from an
 * existing submission.
 *
 * Notes
 * -----
 * AI-assisted development was used for parts of the implementation.
 * The workflow design, field mapping structure, and recovery approach
 * were iteratively developed to fit the operational process.
 */

ob_start();

$base_url = 'FORM URL GOES HERE';

// Query key => Field ID
$map = [
  'storeName'     => '587',
  'date'          => '21',
  'colleagueName' => '96',
  'coachName'     => '20',

  'opsPercent'    => '309',
  'merchPercent'  => '310',
  'ftgPercent'    => '582',
  'lptPercent'    => '583',
  'peoplePercent' => '584',
  'compPercent'   => '311',
  'adminPercent'  => '585',
  'overallScore'  => '313',

  'kq1'           => '226',
  'kq2'           => '217',
  'kq3'           => '108',
  'kq4'           => '159',
  'kq5'           => '164',
  'kq6'           => '259',
  'kq7'           => '274',
  'kq8'           => '577',
  'kq9'           => '425',
  'kq10'          => '426',
];

$entry_id = isset($_POST['entry_id']) ? (int) $_POST['entry_id'] : 0;

?>

<style>
  .pop-wrap{
    max-width: 900px;
    padding: 18px;
    border: 1px solid #ddd;
    border-radius: 12px;
    font-family: Arial, sans-serif;
    background: #fff;
  }
  .pop-row{
    display:flex;
    gap:10px;
    flex-wrap:wrap;
    align-items:center;
    margin-top:10px;
  }
  .pop-input{
    padding:10px 12px;
    border:1px solid #bbb;
    border-radius:8px;
    min-width:220px;
  }
  .pop-btn, .pop-link{
    padding:10px 12px;
    border:1px solid #bbb;
    border-radius:8px;
    background:#fff;
    cursor:pointer;
    text-decoration:none;
    display:inline-block;
  }
  .pop-ok{
    margin-top:16px;
    padding:12px;
    border:1px solid #cfe3cf;
    background:#f6fff6;
    border-radius:8px;
  }
  .pop-err{
    margin-top:16px;
    padding:12px;
    border:1px solid #e0b4b4;
    background:#fff6f6;
    border-radius:8px;
  }
  .pop-url{
    width:100%;
    margin-top:8px;
    padding:10px 12px;
    border:1px solid #bbb;
    border-radius:8px;
  }
</style>

<div class="pop-wrap">
  <h3 style="margin:0 0 8px 0;">Link Builder</h3>
  <p style="margin:0 0 12px 0;">Enter a Gravity Forms <strong>Entry ID</strong> to build the populated link.</p>

  <?php if (!is_user_logged_in()): ?>
    <div class="pop-err">Please log in to use this tool.</div>
  <?php elseif (!class_exists('GFAPI')): ?>
    <div class="pop-err">Gravity Forms is not available (GFAPI not loaded).</div>
  <?php else: ?>

    <form method="post">
      <div class="pop-row">
        <input class="pop-input" type="number" name="entry_id" value="<?php echo esc_attr($entry_id ?: ''); ?>" placeholder="e.g. 55242" required>
        <button class="pop-btn" type="submit">Build link</button>
      </div>
    </form>

    <?php
    if ($entry_id) {

      $entry = GFAPI::get_entry($entry_id);

      if (is_wp_error($entry) || empty($entry['id'])) {
        echo '<div class="pop-err">Entry not found.</div>';
      } else {

        // Build query string params
        $params = [];
        foreach ($map as $key => $fid) {
          $val = isset($entry[$fid]) ? $entry[$fid] : '';

          if (is_string($val)) {
            $val = trim($val);
          }

          if ($val !== '') {
            $params[$key] = $val;
          }
        }

        $url = add_query_arg($params, $base_url);
        ?>

        <div class="pop-ok">
          <strong>Recovered link:</strong>
          <input id="popUrl" class="pop-url" type="text" readonly value="<?php echo esc_attr($url); ?>">

          <div class="pop-row">
            <a class="pop-link" href="<?php echo esc_url($url); ?>" target="_blank" rel="noopener">Open link</a>
            <button class="pop-btn" type="button"
              onclick="navigator.clipboard.writeText(document.getElementById('popUrl').value)">
              Copy link
            </button>
          </div>

          <p style="margin:10px 0 0 0;font-size:12px;opacity:.75;">
            If any values look blank/wrong, email Rob Massey which parameter name (e.g. <code>storeName</code> or <code>colleagueName</code>).
          </p>
        </div>

        <?php
      }
    }
    ?>

  <?php endif; ?>
</div>

<?php
echo ob_get_clean();
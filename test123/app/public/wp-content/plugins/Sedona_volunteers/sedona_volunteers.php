<?php
/**
 * Plugin Name: Sedona Faculty Directory
 * Description: Displays faculty directory with CV viewer from Sedona Web Services
 * Version: 4.0
 */

if (!defined('ABSPATH')) exit;

define('SEDONA_API_KEY', '9D0B37F8-05E1-4A67-7578FDDFB555FE20');
define('SEDONA_CV_ID', '00002');

// ─── GET ALL MEMBERS ──────────────────────────────────────────────────────────
function sedona_get_all_members() {
    $url = 'http://www.sedonaws.com/sd/smemberinfo.cfc?method=getMembers&key=' . urlencode(SEDONA_API_KEY);
    $response = wp_remote_get($url, array('timeout' => 20));
    if (is_wp_error($response)) return array('error' => $response->get_error_message());

    $body = wp_remote_retrieve_body($response);

    if (!preg_match('/<string>(.*?)<\/string>/s', $body, $matches)) {
        return array('error' => 'API response error: ' . substr($body, 0, 500));
    }

    $xml_string = html_entity_decode($matches[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $xml_string = str_replace(array('<![CDATA[', ']]>'), '', $xml_string);
    $xml_string = trim($xml_string);

    libxml_use_internal_errors(true);
    $xml = simplexml_load_string($xml_string);

    if (!$xml || !isset($xml->members->member)) {
        return array('error' => 'No members found. Raw response: ' . substr($xml_string, 0, 500));
    }

    $members = array();
    foreach ($xml->members->member as $member) {
        $members[] = array(
            'id'        => (string) $member->ss,
            'firstname' => (string) $member->firstname,
            'lastname'  => (string) $member->lastname,
            'middle'    => (string) $member->middle,
            'fullname'  => trim((string) $member->firstname . ' ' . (string) $member->middle . ' ' . (string) $member->lastname),
        );
    }
    return $members;
}

// ─── GET CV DATA ──────────────────────────────────────────────────────────────
function sedona_get_cv($member_id, $cv_id = SEDONA_CV_ID) {
    $url = 'http://www.sedonaws.com/sd/scustomtemplate.cfc?method=getTemplate';
    $url .= '&sedonaKey=' . urlencode(SEDONA_API_KEY);
    $url .= '&memberID='  . urlencode($member_id);
    $url .= '&cvID='      . urlencode($cv_id);
    $url .= '&includeMemo=Yes&includeFormat=Yes';

    $response = wp_remote_get($url, array('timeout' => 30));
    if (is_wp_error($response)) return array('error' => 'Connection failed: ' . $response->get_error_message());

    $body = wp_remote_retrieve_body($response);

    if (strpos($body, '10102') !== false) return array('error' => 'IP_NOT_AUTHORIZED');
    if (strpos($body, '<html') !== false)  return array('error' => 'SERVER_ERROR');

    $xml_string = $body;
    if (preg_match('/<string>(.*?)<\/string>/s', $body, $matches)) {
        $xml_string = html_entity_decode($matches[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $xml_string = str_replace(array('<![CDATA[', ']]>'), '', $xml_string);
        $xml_string = trim($xml_string);
    }

    libxml_use_internal_errors(true);
    $xml = simplexml_load_string($xml_string);
    if (!$xml) return array('error' => 'Could not parse CV data');

    if (isset($xml->header->returncode->code)) {
        $code = (string)$xml->header->returncode->code;
        if ($code != '1') return array('error' => (string)$xml->header->returncode->description);
    }

    $components = array();
    if (isset($xml->components->component)) {
        foreach ($xml->components->component as $comp) {
            if (isset($comp->header)) {
                $components[] = array('type' => 'header', 'content' => (string)$comp->header);
            } elseif (isset($comp->title)) {
                $components[] = array('type' => 'title', 'content' => (string)$comp->title);
            } elseif (isset($comp->section)) {
                $components[] = array('type' => 'section', 'content' => (string)$comp->section);
            } elseif (isset($comp->memo)) {
                $components[] = array('type' => 'memo', 'content' => (string)$comp->memo);
            } elseif (isset($comp->line)) {
                $components[] = array('type' => 'line');
            } else {
                // Data component — parse into a table, deduplicate rows
                foreach ($comp->children() as $child_name => $child) {
                    $title = isset($child->attributes->title) ? (string)$child->attributes->title : ucwords(str_replace('_', ' ', $child_name));
                    $records = array();
                    if (isset($child->data->record)) {
                        foreach ($child->data->record as $record) {
                            $row = array();
                            foreach ($record->children() as $field_name => $field_val) {
                                $val = html_entity_decode(trim((string)$field_val), ENT_QUOTES, 'UTF-8');
                                if ($val !== '') $row[$field_name] = $val;
                            }
                            if (!empty($row)) $records[] = $row;
                        }
                    }
                    if (!empty($records)) {
                        // Remove duplicate rows
                        $seen = array();
                        $unique = array();
                        foreach ($records as $row) {
                            $key = md5(serialize($row));
                            if (!isset($seen[$key])) {
                                $seen[$key] = true;
                                $unique[] = $row;
                            }
                        }
                        $components[] = array('type' => 'data_table', 'title' => $title, 'records' => $unique);
                    }
                }
            }
        }
    }
    return array('success' => true, 'components' => $components);
}

// ─── RENDER CV HTML ───────────────────────────────────────────────────────────
function sedona_render_cv($components) {
    $html = '<div style="font-family: Georgia, serif; max-width: 900px; margin: 0 auto; color: #222;">';
    foreach ($components as $comp) {
        switch ($comp['type']) {
            case 'header':
                $html .= '<div style="text-align:center; border-bottom: 3px solid #333; padding-bottom: 16px; margin-bottom: 24px;">';
                $html .= '<h1 style="margin:0; font-size:26px; letter-spacing:1px;">' . esc_html($comp['content']) . '</h1>';
                $html .= '</div>';
                break;

            case 'title':
                $html .= '<h2 style="font-size:18px; color:#1a3a5c; margin: 28px 0 6px 0; border-bottom: 1px solid #ccc; padding-bottom: 4px;">' . esc_html($comp['content']) . '</h2>';
                break;

            case 'section':
                $html .= '<h3 style="font-size:15px; color:#555; font-style:italic; margin: 16px 0 4px 0;">' . esc_html($comp['content']) . '</h3>';
                break;

            case 'memo':
                $html .= '<p style="margin: 8px 0 16px 0; line-height:1.7;">' . nl2br(esc_html($comp['content'])) . '</p>';
                break;

            case 'line':
                $html .= '<hr style="border:none; border-top:1px solid #ddd; margin:20px 0;">';
                break;

            case 'data_table':
                if (empty($comp['records'])) break;

                // Get column headers from first row keys
                $headers = array_keys($comp['records'][0]);

                $html .= '<div style="margin: 12px 0 24px 0;">';
                if (!empty($comp['title'])) {
                    $html .= '<p style="font-weight:bold; font-size:13px; color:#666; text-transform:uppercase; letter-spacing:1px; margin:0 0 6px 0;">' . esc_html($comp['title']) . '</p>';
                }
                $html .= '<div style="overflow-x:auto;">';
                $html .= '<table style="width:100%; border-collapse:collapse; font-size:13px;">';

                // Header row
                $html .= '<thead><tr style="background:#1a3a5c; color:#fff;">';
                foreach ($headers as $h) {
                    $label = ucwords(str_replace('_', ' ', $h));
                    $html .= '<th style="padding:8px 10px; text-align:left; white-space:nowrap;">' . esc_html($label) . '</th>';
                }
                $html .= '</tr></thead>';

                // Data rows
                $html .= '<tbody>';
                foreach ($comp['records'] as $i => $row) {
                    $bg = $i % 2 === 0 ? '#f9f9f9' : '#ffffff';
                    $html .= '<tr style="background:' . $bg . ';">';
                    foreach ($headers as $h) {
                        $val = isset($row[$h]) ? $row[$h] : '';
                        $html .= '<td style="padding:8px 10px; border-bottom:1px solid #eee; vertical-align:top;">' . esc_html($val) . '</td>';
                    }
                    $html .= '</tr>';
                }
                $html .= '</tbody></table>';
                $html .= '</div></div>';
                break;
        }
    }
    $html .= '</div>';
    return $html;
}

// ─── SHORTCODE: [sedona_full_directory] ───────────────────────────────────────
function shortcode_full_directory($atts) {
    $atts  = shortcode_atts(array('cvid' => SEDONA_CV_ID), $atts);
    $cv_id = sanitize_text_field($atts['cvid']);

    $members = sedona_get_all_members();

    if (isset($members['error'])) {
        return '<div style="color:red; padding:20px; background:#fee; border:1px solid #c00; border-radius:4px;">
            <strong>Error loading members:</strong> ' . esc_html($members['error']) . '
        </div>';
    }

    if (empty($members)) return '<p>No members found.</p>';

    $uid   = uniqid('sfd_');
    $total = count($members);

    ob_start();
    ?>
    <style>
    #<?php echo $uid; ?> { font-family: 'Segoe UI', Arial, sans-serif; max-width: 1000px; margin: 0 auto; }
    #<?php echo $uid; ?> .sfd-header { background: #1a3a5c; color: #fff; padding: 24px 28px; border-radius: 8px 8px 0 0; }
    #<?php echo $uid; ?> .sfd-header h2 { margin: 0 0 4px 0; font-size: 22px; }
    #<?php echo $uid; ?> .sfd-header p { margin: 0; opacity: 0.8; font-size: 14px; }
    #<?php echo $uid; ?> .sfd-search { padding: 16px 20px; background: #f0f4f8; border: 1px solid #dce3ea; border-top: none; }
    #<?php echo $uid; ?> .sfd-search input { width: 100%; padding: 10px 14px; border: 1px solid #ccc; border-radius: 4px; font-size: 14px; box-sizing: border-box; }
    #<?php echo $uid; ?> table { width: 100%; border-collapse: collapse; background: #fff; border: 1px solid #dce3ea; border-top: none; }
    #<?php echo $uid; ?> thead tr { background: #f0f4f8; }
    #<?php echo $uid; ?> th { padding: 12px 16px; text-align: left; font-size: 13px; color: #555; text-transform: uppercase; letter-spacing: .5px; border-bottom: 2px solid #dce3ea; }
    #<?php echo $uid; ?> td { padding: 12px 16px; border-bottom: 1px solid #eee; font-size: 14px; }
    #<?php echo $uid; ?> tbody tr:hover { background: #f7fafd; }
    #<?php echo $uid; ?> .sfd-btn { background: #1a3a5c; color: #fff; border: none; padding: 7px 16px; border-radius: 4px; cursor: pointer; font-size: 13px; }
    #<?php echo $uid; ?> .sfd-btn:hover { background: #245080; }
    #<?php echo $uid; ?> code { background: #eef2f7; padding: 2px 6px; border-radius: 3px; font-size: 12px; }

    /* Modal */
    #modal_<?php echo $uid; ?> { display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,.6); z-index:99999; overflow:auto; }
    #modal_<?php echo $uid; ?> .modal-box { max-width:960px; margin:40px auto; background:#fff; border-radius:8px; box-shadow:0 20px 60px rgba(0,0,0,.3); }
    #modal_<?php echo $uid; ?> .modal-head { background:#1a3a5c; color:#fff; padding:20px 28px; border-radius:8px 8px 0 0; display:flex; justify-content:space-between; align-items:center; }
    #modal_<?php echo $uid; ?> .modal-head h3 { margin:0; font-size:18px; }
    #modal_<?php echo $uid; ?> .modal-close { background:none; border:none; color:#fff; font-size:28px; cursor:pointer; line-height:1; }
    #modal_<?php echo $uid; ?> .modal-body { padding:32px; max-height:80vh; overflow:auto; }
    #modal_<?php echo $uid; ?> .modal-tabs { display:flex; gap:8px; padding:16px 28px; background:#f0f4f8; border-bottom:1px solid #dce3ea; flex-wrap:wrap; }
    #modal_<?php echo $uid; ?> .modal-tab { background:#fff; border:1px solid #ccc; padding:6px 14px; border-radius:20px; cursor:pointer; font-size:13px; }
    #modal_<?php echo $uid; ?> .modal-tab.active { background:#1a3a5c; color:#fff; border-color:#1a3a5c; }

    @keyframes sfd_spin { to { transform: rotate(360deg); } }
    .sfd-spinner { width:36px; height:36px; border:4px solid #eee; border-top-color:#1a3a5c; border-radius:50%; animation:sfd_spin .8s linear infinite; margin:40px auto; }
    </style>

    <div id="<?php echo $uid; ?>">
      <div class="sfd-header">
        <h2>Faculty Directory</h2>
        <p><?php echo $total; ?> members &nbsp;|&nbsp; Template: <?php echo esc_html($cv_id); ?></p>
      </div>

      <div class="sfd-search">
        <input type="text" id="search_<?php echo $uid; ?>" placeholder="Search by name or ID..." onkeyup="sfdSearch_<?php echo $uid; ?>(this.value)">
      </div>

      <table id="table_<?php echo $uid; ?>">
        <thead>
          <tr>
            <th>#</th>
            <th>Name</th>
            <th>Member ID</th>
            <th style="text-align:center;">CV</th>
          </tr>
        </thead>
        <tbody>
          <?php $i = 1; foreach ($members as $m): ?>
          <tr>
            <td style="color:#aaa; width:40px;"><?php echo $i++; ?></td>
            <td><strong><?php echo esc_html($m['fullname']); ?></strong></td>
            <td><code><?php echo esc_html($m['id']); ?></code></td>
            <td style="text-align:center; width:120px;">
              <button class="sfd-btn"
                onclick="sfdOpenCV_<?php echo $uid; ?>('<?php echo esc_js($m['id']); ?>','<?php echo esc_js($m['fullname']); ?>')">
                View CV
              </button>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <!-- MODAL -->
    <div id="modal_<?php echo $uid; ?>">
      <div class="modal-box">
        <div class="modal-head">
          <h3 id="modal_name_<?php echo $uid; ?>">CV Viewer</h3>
          <button class="modal-close" onclick="sfdCloseModal_<?php echo $uid; ?>()">&times;</button>
        </div>
        <div class="modal-tabs" id="modal_tabs_<?php echo $uid; ?>"></div>
        <div class="modal-body" id="modal_body_<?php echo $uid; ?>">
          <div class="sfd-spinner"></div>
        </div>
      </div>
    </div>

    <script>
    // Search
    function sfdSearch_<?php echo $uid; ?>(q) {
        q = q.toLowerCase();
        var rows = document.querySelectorAll('#table_<?php echo $uid; ?> tbody tr');
        rows.forEach(function(row) {
            row.style.display = row.innerText.toLowerCase().includes(q) ? '' : 'none';
        });
    }

    // Open modal and fetch CV
    function sfdOpenCV_<?php echo $uid; ?>(memberId, memberName) {
        document.getElementById('modal_name_<?php echo $uid; ?>').textContent = memberName;
        document.getElementById('modal_tabs_<?php echo $uid; ?>').innerHTML = '';
        document.getElementById('modal_body_<?php echo $uid; ?>').innerHTML = '<div class="sfd-spinner"></div>';
        document.getElementById('modal_<?php echo $uid; ?>').style.display = 'block';
        document.body.style.overflow = 'hidden';

        var xhr = new XMLHttpRequest();
        xhr.open('POST', '<?php echo admin_url('admin-ajax.php'); ?>', true);
        xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
        xhr.onreadystatechange = function() {
            if (xhr.readyState === 4 && xhr.status === 200) {
                var res = JSON.parse(xhr.responseText);
                if (res.error) {
                    document.getElementById('modal_body_<?php echo $uid; ?>').innerHTML =
                        '<p style="color:red; padding:20px;">' + res.error + '</p>';
                } else {
                    // Build tabs
                    var tabsEl = document.getElementById('modal_tabs_<?php echo $uid; ?>');
                    var bodyEl = document.getElementById('modal_body_<?php echo $uid; ?>');
                    var sections = res.sections;
                    tabsEl.innerHTML = '';
                    if (sections.length > 1) {
                        sections.forEach(function(s, idx) {
                            var btn = document.createElement('button');
                            btn.className = 'modal-tab' + (idx === 0 ? ' active' : '');
                            btn.textContent = s.label;
                            btn.onclick = function() {
                                document.querySelectorAll('#modal_tabs_<?php echo $uid; ?> .modal-tab').forEach(function(b){ b.classList.remove('active'); });
                                btn.classList.add('active');
                                bodyEl.innerHTML = s.html;
                            };
                            tabsEl.appendChild(btn);
                        });
                    }
                    bodyEl.innerHTML = sections.length ? sections[0].html : '<p>No CV data available.</p>';
                }
            }
        };
        xhr.send('action=sedona_fetch_cv_json&member_id=' + encodeURIComponent(memberId)
            + '&cv_id=<?php echo esc_js($cv_id); ?>'
            + '&nonce=<?php echo wp_create_nonce('sedona_nonce'); ?>');
    }

    function sfdCloseModal_<?php echo $uid; ?>() {
        document.getElementById('modal_<?php echo $uid; ?>').style.display = 'none';
        document.body.style.overflow = '';
    }

    document.getElementById('modal_<?php echo $uid; ?>').addEventListener('click', function(e) {
        if (e.target === this) sfdCloseModal_<?php echo $uid; ?>();
    });
    </script>
    <?php
    return ob_get_clean();
}
add_shortcode('sedona_full_directory', 'shortcode_full_directory');

// ─── AJAX: Return CV as JSON with sections/tabs ────────────────────────────────
function ajax_fetch_cv_json() {
    check_ajax_referer('sedona_nonce', 'nonce');

    $member_id = sanitize_text_field($_POST['member_id']);
    $cv_id     = sanitize_text_field($_POST['cv_id']);

    $result = sedona_get_cv($member_id, $cv_id);

    if (isset($result['error'])) {
        wp_send_json(array('error' => $result['error']));
    }

    // Group components into sections separated by 'title' components
    $sections   = array();
    $current_label = 'Overview';
    $current_html  = '';

    foreach ($result['components'] as $comp) {
        if ($comp['type'] === 'title') {
            // Save previous section
            if (trim($current_html) !== '') {
                $sections[] = array('label' => $current_label, 'html' => $current_html);
            }
            $current_label = $comp['content'];
            $current_html  = '';
        } else {
            // Build HTML for this component
            switch ($comp['type']) {
                case 'header':
                    $current_html .= '<div style="text-align:center; border-bottom:3px solid #1a3a5c; padding-bottom:16px; margin-bottom:24px;">';
                    $current_html .= '<h1 style="margin:0; font-size:24px; font-family:Georgia,serif;">' . esc_html($comp['content']) . '</h1>';
                    $current_html .= '</div>';
                    break;

                case 'section':
                    $current_html .= '<h3 style="font-size:14px; color:#888; font-style:italic; margin:20px 0 4px 0;">' . esc_html($comp['content']) . '</h3>';
                    break;

                case 'memo':
                    $current_html .= '<p style="line-height:1.7; margin:8px 0 16px 0;">' . nl2br(esc_html($comp['content'])) . '</p>';
                    break;

                case 'line':
                    $current_html .= '<hr style="border:none; border-top:1px solid #eee; margin:20px 0;">';
                    break;

                case 'data_table':
                    if (empty($comp['records'])) break;
                    $headers = array_keys($comp['records'][0]);
                    $current_html .= '<div style="margin:12px 0 24px 0;">';
                    if (!empty($comp['title'])) {
                        $current_html .= '<p style="font-weight:bold; font-size:12px; color:#888; text-transform:uppercase; letter-spacing:1px; margin:0 0 6px 0;">' . esc_html($comp['title']) . '</p>';
                    }
                    $current_html .= '<div style="overflow-x:auto;">';
                    $current_html .= '<table style="width:100%; border-collapse:collapse; font-size:13px; font-family:Arial,sans-serif;">';
                    $current_html .= '<thead><tr style="background:#1a3a5c; color:#fff;">';
                    foreach ($headers as $h) {
                        $label = ucwords(str_replace('_', ' ', $h));
                        $current_html .= '<th style="padding:8px 12px; text-align:left; white-space:nowrap;">' . esc_html($label) . '</th>';
                    }
                    $current_html .= '</tr></thead><tbody>';
                    foreach ($comp['records'] as $ri => $row) {
                        $bg = $ri % 2 === 0 ? '#f9f9f9' : '#fff';
                        $current_html .= '<tr style="background:' . $bg . ';">';
                        foreach ($headers as $h) {
                            $val = isset($row[$h]) ? $row[$h] : '';
                            $current_html .= '<td style="padding:8px 12px; border-bottom:1px solid #eee; vertical-align:top;">' . esc_html($val) . '</td>';
                        }
                        $current_html .= '</tr>';
                    }
                    $current_html .= '</tbody></table></div></div>';
                    break;
            }
        }
    }

    // Push last section
    if (trim($current_html) !== '') {
        $sections[] = array('label' => $current_label, 'html' => $current_html);
    }

    wp_send_json(array('sections' => $sections));
}
add_action('wp_ajax_sedona_fetch_cv_json',        'ajax_fetch_cv_json');
add_action('wp_ajax_nopriv_sedona_fetch_cv_json', 'ajax_fetch_cv_json');

// ─── SHORTCODE: [sedona_member_count] ─────────────────────────────────────────
function shortcode_member_count() {
    $members = sedona_get_all_members();
    if (isset($members['error'])) return 'Error: ' . esc_html($members['error']);
    return '<span style="font-size:28px; font-weight:bold; color:#1a3a5c;">' . count($members) . '</span> <span style="color:#666;">faculty members</span>';
}
add_shortcode('sedona_member_count', 'shortcode_member_count');

// ─── ADMIN PAGE ───────────────────────────────────────────────────────────────
add_action('admin_menu', function() {
    add_menu_page('Sedona Directory', 'Sedona Directory', 'manage_options', 'sedona-dir', 'sedona_dir_admin', 'dashicons-groups', 30);
});

function sedona_dir_admin() {
    $members = sedona_get_all_members();
    ?>
    <div class="wrap">
        <h1>Sedona Faculty Directory</h1>
        <?php if (isset($members['error'])): ?>
            <div class="notice notice-error"><p><strong>Error:</strong> <?php echo esc_html($members['error']); ?></p></div>
        <?php else: ?>
            <div class="notice notice-success"><p>✅ Connected! Found <strong><?php echo count($members); ?></strong> members.</p></div>
            <h2>Available Shortcodes</h2>
            <table class="widefat" style="max-width:600px;">
                <tr><td><code>[sedona_full_directory]</code></td><td>Full faculty directory with CV viewer</td></tr>
                <tr><td><code>[sedona_member_count]</code></td><td>Just show the total member count</td></tr>
            </table>
        <?php endif; ?>
    </div>
    <?php
}
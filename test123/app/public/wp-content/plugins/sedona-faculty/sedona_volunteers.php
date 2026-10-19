<?php
/**
 * Plugin Name: Sedona Faculty Directory — Narrative CV
 * Description: Shows faculty directory and renders CVs in professional narrative format (non-table) from Sedona Web Services
 * Version: 6.0
 * Author: Academic Web Solutions
 *
 * CHANGELOG v6.0
 * - Fixed field name mappings to match actual Sedona XML output (degree_earned, conferring_institution, etc.)
 * - Broadened ALL section keyword arrays so partial/variant titles still match
 * - Fixed sedona_find_component to use case-insensitive partial matching on every keyword
 * - Added sedona_find_section_component() to match 'section' type headers rendered in narrative
 * - Render 'section' type components in narrative output (were silently dropped before)
 * - Fixed contact info extraction — reads from structured data records too, not just memos
 * - Publications: collect ALL matching sub-components, not just the first hit
 * - Added robust fallback chains for every rendered field in every section
 * - Added debug mode: append &sedona_debug=1 to a CV URL to dump raw component data
 */

if (!defined('ABSPATH')) exit;

define('SEDONA_API_KEY', '9D0B37F8-05E1-4A67-7578FDDFB555FE20');
define('SEDONA_CV_ID',   '00002');

/* ============================================================
   1. API HELPERS
   ============================================================ */

function sedona_get_all_members() {
    $url      = 'http://www.sedonaws.com/sd/smemberinfo.cfc?method=getMembers&key=' . urlencode(SEDONA_API_KEY);
    $response = wp_remote_get($url, array('timeout' => 20));
    if (is_wp_error($response)) {
        return array('error' => $response->get_error_message());
    }
    $body = wp_remote_retrieve_body($response);
    if (!preg_match('/<string>(.*?)<\/string>/s', $body, $matches)) {
        return array('error' => 'API response error: ' . substr($body, 0, 300));
    }
    $xml_string = html_entity_decode($matches[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $xml_string = str_replace(array('<![CDATA[', ']]>'), '', $xml_string);
    $xml_string = trim($xml_string);

    libxml_use_internal_errors(true);
    $xml = simplexml_load_string($xml_string);
    if (!$xml || !isset($xml->members->member)) {
        return array('error' => 'No members found. Raw: ' . substr($xml_string, 0, 300));
    }
    $members = array();
    foreach ($xml->members->member as $member) {
        $members[] = array(
            'id'        => (string) $member->ss,
            'firstname' => (string) $member->firstname,
            'lastname'  => (string) $member->lastname,
            'fullname'  => trim((string) $member->firstname . ' ' . (string) $member->lastname),
        );
    }
    return $members;
}

function sedona_get_cv($member_id, $cv_id = SEDONA_CV_ID) {
    $url  = 'http://www.sedonaws.com/sd/scustomtemplate.cfc?method=getTemplate';
    $url .= '&sedonaKey=' . urlencode(SEDONA_API_KEY);
    $url .= '&memberID='  . urlencode($member_id);
    $url .= '&cvID='      . urlencode($cv_id);
    $url .= '&includeMemo=Yes&includeFormat=Yes';

    $response = wp_remote_get($url, array('timeout' => 30));
    if (is_wp_error($response)) {
        return array('error' => 'Connection failed: ' . $response->get_error_message());
    }
    $body = wp_remote_retrieve_body($response);
    if (strpos($body, '10102') !== false) return array('error' => 'IP_NOT_AUTHORIZED');
    if (strpos($body, '<html')  !== false) return array('error' => 'SERVER_ERROR');

    $xml_string = $body;
    if (preg_match('/<string>(.*?)<\/string>/s', $body, $matches)) {
        $xml_string = html_entity_decode($matches[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $xml_string = str_replace(array('<![CDATA[', ']]>'), '', $xml_string);
        $xml_string = trim($xml_string);
    }

    libxml_use_internal_errors(true);
    $xml = simplexml_load_string($xml_string);
    if (!$xml) {
        return array('error' => 'Could not parse CV data. Raw: ' . substr($xml_string, 0, 500));
    }
    if (isset($xml->header->returncode->code)) {
        $code = (string) $xml->header->returncode->code;
        if ($code != '1') {
            return array('error' => (string) $xml->header->returncode->description);
        }
    }

    $components = array();
    if (isset($xml->components->component)) {
        foreach ($xml->components->component as $comp) {
            $is_special = false;

            // ── Named structural elements ──────────────────────────────────
            if (isset($comp->header)) {
                $text = trim((string) $comp->header);
                if ($text !== '') {
                    $components[] = array('type' => 'header', 'content' => $text);
                }
                $is_special = true;
            }
            if (isset($comp->title)) {
                foreach ($comp->title as $title) {
                    $text = trim((string) $title);
                    if ($text !== '') {
                        $components[] = array('type' => 'title', 'content' => $text);
                    }
                }
                $is_special = true;
            }
            if (isset($comp->section)) {
                $text = trim((string) $comp->section);
                if ($text !== '') {
                    $components[] = array('type' => 'section', 'content' => $text);
                }
                $is_special = true;
            }
            if (isset($comp->memo)) {
                $text = trim((string) $comp->memo);
                if ($text !== '') {
                    $components[] = array('type' => 'memo', 'content' => $text);
                }
                $is_special = true;
            }
            if (isset($comp->line)) {
                $components[] = array('type' => 'line');
                $is_special   = true;
            }

            // ── Data child elements ────────────────────────────────────────
            if (!$is_special) {
                foreach ($comp->children() as $child_name => $child) {

                    // Prefer explicit title attribute; fall back to humanised tag name
                    $title = '';
                    if (isset($child->attributes)) {
                        foreach ($child->attributes->children() as $attr_name => $attr_val) {
                            if (strtolower($attr_name) === 'title') {
                                $title = trim((string) $attr_val);
                            }
                        }
                        // Also try direct attribute access
                        $attrs = $child->attributes();
                        if (isset($attrs['title'])) {
                            $title = trim((string) $attrs['title']);
                        }
                    }
                    if ($title === '') {
                        $title = ucwords(str_replace('_', ' ', $child_name));
                    }

                    // Collect records — handle both <record> and <row> children
                    $records   = array();
                    $data_node = isset($child->data) ? $child->data : $child;
                    $record_nodes = array();
                    if (isset($data_node->record)) {
                        foreach ($data_node->record as $r) $record_nodes[] = $r;
                    }
                    if (isset($data_node->row)) {
                        foreach ($data_node->row as $r) $record_nodes[] = $r;
                    }
                    // Fallback: children named neither record nor row
                    if (empty($record_nodes) && isset($child->data)) {
                        foreach ($child->data->children() as $r) $record_nodes[] = $r;
                    }

                    foreach ($record_nodes as $record) {
                        $row = array();
                        foreach ($record->children() as $field_name => $field_val) {
                            $val = trim(html_entity_decode((string) $field_val, ENT_QUOTES, 'UTF-8'));
                            if ($val !== '' && !in_array($field_name, array('ss', 'id', 'memberid', 'member_id'))) {
                                $row[$field_name] = $val;
                            }
                        }
                        if (!empty($row)) {
                            $records[] = $row;
                        }
                    }

                    if (!empty($records)) {
                        // De-duplicate
                        $seen = array(); $unique = array();
                        foreach ($records as $row) {
                            $key = md5(serialize($row));
                            if (!isset($seen[$key])) { $seen[$key] = true; $unique[] = $row; }
                        }
                        $components[] = array(
                            'type'      => 'data_table',
                            'title'     => $title,
                            'tag_name'  => $child_name,   // ← keep raw tag for fallback matching
                            'columns'   => array_keys($unique[0]),
                            'records'   => $unique,
                        );
                    }
                }
            }
        }
    }
    return array('success' => true, 'components' => $components);
}

/* ============================================================
   2. SMART DATA EXTRACTORS
   ============================================================ */

/**
 * Extract name / title / contact from header, title, and memo components.
 * Also scans data_table records for email/phone when not found in memos.
 */
function sedona_extract_header_info($components) {
    $info = array('name' => '', 'titles' => array(), 'email' => '', 'phone' => '', 'address' => '');

    foreach ($components as $comp) {
        if ($comp['type'] === 'header' && $info['name'] === '') {
            $info['name'] = $comp['content'];
        }
        if ($comp['type'] === 'title') {
            $info['titles'][] = $comp['content'];
        }
        if ($comp['type'] === 'memo') {
            $text = $comp['content'];
            if ($info['email'] === '' && strpos($text, '@') !== false) {
                $info['email'] = $text;
            } elseif ($info['phone'] === '' && preg_match('/\d{3}[-.\s]?\d{3}[-.\s]?\d{4}/', $text)) {
                $info['phone'] = $text;
            }
        }
        // Grab email/phone from any data record (e.g. a contact section)
        if ($comp['type'] === 'data_table') {
            foreach ($comp['records'] as $row) {
                if ($info['email'] === '') {
                    foreach (array('email', 'email_address', 'e_mail') as $k) {
                        if (!empty($row[$k])) { $info['email'] = $row[$k]; break; }
                    }
                }
                if ($info['phone'] === '') {
                    foreach (array('phone', 'phone_number', 'telephone', 'office_phone') as $k) {
                        if (!empty($row[$k])) { $info['phone'] = $row[$k]; break; }
                    }
                }
                if ($info['address'] === '') {
                    foreach (array('address', 'office_address', 'office_location', 'room', 'office') as $k) {
                        if (!empty($row[$k])) { $info['address'] = $row[$k]; break; }
                    }
                }
            }
        }
    }
    return $info;
}

/**
 * Find the FIRST data_table component whose title OR tag_name contains ANY of the keywords.
 * Matching is case-insensitive and partial.
 */
function sedona_find_component($components, $keywords) {
    foreach ($components as $comp) {
        if ($comp['type'] !== 'data_table') continue;
        $haystack = strtolower(($comp['title'] ?? '') . ' ' . ($comp['tag_name'] ?? ''));
        foreach ($keywords as $kw) {
            if (stripos($haystack, strtolower($kw)) !== false) {
                return $comp;
            }
        }
    }
    return null;
}

/**
 * Find ALL data_table components matching any keyword (for publications that
 * may be split across multiple components with similar titles).
 */
function sedona_find_all_components($components, $keywords) {
    $out = array();
    foreach ($components as $comp) {
        if ($comp['type'] !== 'data_table') continue;
        $haystack = strtolower(($comp['title'] ?? '') . ' ' . ($comp['tag_name'] ?? ''));
        foreach ($keywords as $kw) {
            if (stripos($haystack, strtolower($kw)) !== false) {
                $out[] = $comp;
                break;
            }
        }
    }
    return $out;
}

/**
 * Merge records from multiple components into one pseudo-component.
 * Deduplicates by title (case-insensitive) to handle Sedona's per-author rows.
 */
function sedona_merge_components($comps_array, $title = '') {
    if (empty($comps_array)) return null;
    $records = array();
    foreach ($comps_array as $c) {
        if (!empty($c['records'])) {
            foreach ($c['records'] as $r) $records[] = $r;
        }
    }
    if (empty($records)) return null;

    // Deduplicate: first by exact row hash, then by normalised title
    $seen_hash  = array();
    $seen_title = array();
    $unique     = array();
    foreach ($records as $row) {
        // Exact duplicate
        $hash = md5(serialize($row));
        if (isset($seen_hash[$hash])) continue;
        $seen_hash[$hash] = true;

        // Same title = same paper listed once per co-author — keep only first occurrence
        $row_title = '';
        foreach (array('title','paper_title','presentation_title','article_title','paptitle','papertitle') as $k) {
            if (!empty($row[$k])) { $row_title = strtolower(trim($row[$k])); break; }
        }
        if ($row_title !== '') {
            if (isset($seen_title[$row_title])) continue;
            $seen_title[$row_title] = true;
        }

        $unique[] = $row;
    }

    if (empty($unique)) return null;
    return array(
        'type'    => 'data_table',
        'title'   => $title ?: $comps_array[0]['title'],
        'records' => $unique,
    );
}

/**
 * Pull a value from a record row using an ordered list of candidate field names.
 * Returns the first non-empty value found, or ''.
 */
function sedona_field($row, $candidates) {
    foreach ($candidates as $k) {
        if (!empty($row[$k])) return $row[$k];
    }
    return '';
}

/* ============================================================
   3. NARRATIVE RENDERERS
   ============================================================ */

function sedona_render_narrative_cv($components) {
    $header = sedona_extract_header_info($components);

    // ── Background ────────────────────────────────────────────────────────
    $education   = sedona_find_component($components, array('academic background', 'academic degree', 'degree', 'education'));
    $work        = sedona_find_component($components, array('work experience', 'employment', 'academic position', 'professional position', 'position held', 'work history'));
    $consulting  = sedona_find_component($components, array('consulting'));
    $paid_service= sedona_find_component($components, array('paid service', 'expert testimony', 'expert witness'));
    $certs       = sedona_find_component($components, array('certification', 'licensure', 'license'));
    $memberships = sedona_find_component($components, array('membership', 'professional association', 'professional society'));
    $skills      = sedona_find_component($components, array('skill', 'expertise', 'competency'));

    // ── Teaching ──────────────────────────────────────────────────────────
    $courses_taught = sedona_find_component($components, array('courses taught', 'course taught', 'teaching load'));
    $teaching_acts  = sedona_find_component($components, array('teaching activit', 'advising', 'dissertation', 'thesis committee'));
    $exec_ed        = sedona_find_component($components, array('executive education', 'professional education', 'executive program'));


    // ── Research / Intellectual Contributions ─────────────────────────────
    // Collect ALL matching components for each pub type so none are dropped
    $ref_articles    = sedona_merge_components(sedona_find_all_components($components, array('refereed article', 'refereed journal', 'journal article')), 'Refereed Articles');
    $ref_proceedings = sedona_merge_components(sedona_find_all_components($components, array('refereed proceeding', 'refereed conference proceeding')), 'Refereed Proceedings');
    $nonref_articles = sedona_merge_components(sedona_find_all_components($components, array('non-refereed article', 'nonrefereed article', 'non refereed article')), 'Non-Refereed Articles');
    $nonref_proc     = sedona_merge_components(sedona_find_all_components($components, array('non-refereed proceeding', 'nonrefereed proceeding', 'non refereed proceeding')), 'Non-Refereed Proceedings');
    $invited         = sedona_merge_components(sedona_find_all_components($components, array('invited article', 'invited review', 'invited chapter', 'invited book')), 'Invited Articles and Reviews');
    $books           = sedona_merge_components(sedona_find_all_components($components, array('book', 'monograph', 'compilation', 'textbook', 'manual')), 'Books and Monographs');
    $chapters        = sedona_merge_components(sedona_find_all_components($components, array('chapter', 'case study', 'reading', 'supplement', 'teaching case')), 'Book Chapters and Cases');
    $pres_ref        = sedona_merge_components(sedona_find_all_components($components, array('presentations of refereed', 'presentation of refereed', 'refereed presentation', 'refereed paper presented', 'refereed papers')), 'Refereed Presentations');
    $pres_nonref     = sedona_merge_components(sedona_find_all_components($components, array('presentations of non-refereed', 'presentation of non-refereed', 'non-refereed presentation', 'nonrefereed presentation', 'invited presentation', 'invited talk')), 'Non-Refereed Presentations');
    $patents         = sedona_merge_components(sedona_find_all_components($components, array('patent', 'invention')), 'Patents and Inventions');
    $reports         = sedona_merge_components(sedona_find_all_components($components, array('research report', 'technical report')), 'Research Reports');
    $grants          = sedona_merge_components(sedona_find_all_components($components, array('grant', 'funded research', 'research funding', 'contract')), 'Grants');
    $creative        = sedona_merge_components(sedona_find_all_components($components, array('creative activit', 'creative work', 'creative product', 'creative output')), 'Creative Activities');
    $wip_review      = sedona_merge_components(sedona_find_all_components($components, array('under review', 'revision', 'paper submitted', 'submitted paper')), 'Papers Under Review');
    $working_papers  = sedona_merge_components(sedona_find_all_components($components, array('working paper', 'work in progress')), 'Working Papers');
    $software        = sedona_merge_components(sedona_find_all_components($components, array('software', 'dataset', 'database')), 'Software and Datasets');
    $other_research  = sedona_merge_components(sedona_find_all_components($components, array('other research', 'other intellectual')), 'Other Research');
    $media           = sedona_merge_components(sedona_find_all_components($components, array('media', 'news', 'press', 'interview')), 'Media');

    // ── Service ───────────────────────────────────────────────────────────
    $inst_service = sedona_find_component($components, array('institutional service', 'service to the institution', 'university service', 'college service', 'departmental service', 'school service', 'faculty service'));
    $prof_service = sedona_find_component($components, array('professional service', 'service to the profession', 'editorial', 'reviewer', 'journal service'));
    $comm_service = sedona_find_component($components, array('community service', 'service to the community', 'civic'));
    $prof_dev     = sedona_find_component($components, array('professional development', 'faculty development', 'workshop attended', 'training'));
    $honors       = sedona_find_component($components, array('honor', 'award', 'recognition', 'fellowship', 'prize'));
    $misc         = sedona_find_component($components, array('miscellaneous', 'other activit', 'other professional'));
    $references   = sedona_find_component($components, array('reference'));

    ob_start();
    ?>
    <div class="ncv-page">

      <!-- ===== HEADER ===== -->
      <header class="ncv-header">
        <?php if ($header['name']): ?>
          <h1 class="ncv-name"><?php echo esc_html($header['name']); ?></h1>
        <?php endif; ?>
        <?php foreach ($header['titles'] as $t): ?>
          <p class="ncv-title-line"><?php echo esc_html($t); ?></p>
        <?php endforeach; ?>
        <div class="ncv-contact-block">
          <?php if ($header['address']): ?>
            <span class="ncv-contact"><?php echo esc_html($header['address']); ?></span>
          <?php endif; ?>
          <?php if ($header['email']): ?>
            <span class="ncv-contact"><?php echo esc_html($header['email']); ?></span>
          <?php endif; ?>
          <?php if ($header['phone']): ?>
            <span class="ncv-contact"><?php echo esc_html($header['phone']); ?></span>
          <?php endif; ?>
        </div>
      </header>

      <!-- ===== EDUCATION ===== -->
      <?php if ($education && !empty($education['records'])): ?>
      <section class="ncv-section">
        <h2 class="ncv-section-title">Education</h2>
        <div class="ncv-education">
          <?php foreach ($education['records'] as $row):
            // Sedona commonly uses: degree_earned, conferring_institution, conferring_year, field_of_study, major, concentration
            $degree = sedona_field($row, array('degree_earned','degree','credential','award'));
            $school = sedona_field($row, array('conferring_institution','school','institution','university','college','organization'));
            $year   = sedona_field($row, array('conferring_year','year','graduation_year','completion_year','date','completion_date','end_year','year_conferred'));
            $field  = sedona_field($row, array('field_of_study','major','discipline','concentration','program','area'));
            $honors_deg = sedona_field($row, array('honors','distinction','magna_cum_laude','cum_laude'));
            if (!$degree && !$school) continue;
            ?>
            <div class="ncv-edu-item">
              <?php if ($degree): ?><span class="ncv-degree"><?php echo esc_html($degree); ?></span><?php endif; ?>
              <?php if ($field): ?><span class="ncv-field"> in <?php echo esc_html($field); ?></span><?php endif; ?>
              <?php if ($school): ?><span class="ncv-school">, <?php echo esc_html($school); ?></span><?php endif; ?>
              <?php if ($year): ?><span class="ncv-year"> (<?php echo esc_html($year); ?>)</span><?php endif; ?>
              <?php if ($honors_deg): ?><span class="ncv-honors"> — <?php echo esc_html($honors_deg); ?></span><?php endif; ?>
            </div>
          <?php endforeach; ?>
        </div>
      </section>
      <?php endif; ?>

      <!-- ===== ACADEMIC / PROFESSIONAL POSITIONS ===== -->
      <?php if ($work && !empty($work['records'])): ?>
      <section class="ncv-section">
        <h2 class="ncv-section-title">Academic and Professional Positions</h2>
        <div class="ncv-positions">
          <?php foreach ($work['records'] as $row):
            // Sedona commonly: academic_rank, rank, title, position_title, entity, institution, department, from_year, until_year
            $rank     = sedona_field($row, array('academic_rank','rank','title','position_title','position','role','job_title'));
            $entity   = sedona_field($row, array('entity','institution','organization','university','employer','company','college','school'));
            $dept     = sedona_field($row, array('department','dept','unit','division','college','school_name'));
            $from     = sedona_field($row, array('from_year','from','start_year','start_date','begin_year','begin','date_start'));
            $until    = sedona_field($row, array('until_year','until','end_year','end_date','to_year','to','date_end','present'));
            $location = sedona_field($row, array('location','city','state','country','city_state'));
            if (!$rank && !$entity) continue;
            ?>
            <div class="ncv-position">
              <p class="ncv-position-line">
                <?php if ($rank): ?><strong><?php echo esc_html($rank); ?></strong><?php endif; ?>
                <?php if ($entity): ?>, <?php echo esc_html($entity); ?><?php endif; ?>
                <?php if ($dept): ?>, <?php echo esc_html($dept); ?><?php endif; ?>
                <?php if ($location): ?>, <?php echo esc_html($location); ?><?php endif; ?>
              </p>
              <?php if ($from || $until): ?>
                <p class="ncv-position-dates">
                  <?php
                  if ($from && $until) echo esc_html($from) . ' – ' . esc_html($until);
                  elseif ($from)       echo esc_html($from) . ' – Present';
                  else                 echo esc_html($until);
                  ?>
                </p>
              <?php endif; ?>
            </div>
          <?php endforeach; ?>
        </div>
      </section>
      <?php endif; ?>

      <!-- ===== CERTIFICATIONS ===== -->
      <?php if ($certs && !empty($certs['records'])): ?>
      <section class="ncv-section">
        <h2 class="ncv-section-title">Certifications and Licensures</h2>
        <ul class="ncv-list">
          <?php foreach ($certs['records'] as $row):
            $name = sedona_field($row, array('certification_name','certification','license_name','license','credential','credential_name','title','description'));
            $org  = sedona_field($row, array('issuing_organization','organization','issuing_body','issued_by','authority'));
            $year = sedona_field($row, array('year','date','issue_year','issued_year','expiration_year','valid_thru'));
            if (!$name) continue;
            ?>
            <li>
              <?php echo esc_html($name); ?>
              <?php if ($org): ?> — <?php echo esc_html($org); ?><?php endif; ?>
              <?php if ($year): ?> (<?php echo esc_html($year); ?>)<?php endif; ?>
            </li>
          <?php endforeach; ?>
        </ul>
      </section>
      <?php endif; ?>

      <!-- ===== TEACHING ===== -->
      <?php if (($courses_taught && !empty($courses_taught['records'])) || ($teaching_acts && !empty($teaching_acts['records'])) || ($exec_ed && !empty($exec_ed['records']))): ?>
      <section class="ncv-section">
        <h2 class="ncv-section-title">Teaching</h2>

        <?php if ($courses_taught && !empty($courses_taught['records'])): ?>
          <h3 class="ncv-subtitle">Courses Taught</h3>
          <?php
          $seen_courses = array(); $unique_courses = array();
          foreach ($courses_taught['records'] as $row) {
              $course = sedona_field($row, array('course_name','course_title','course','title','description','class_name','class_title'));
              $code   = sedona_field($row, array('course_number','course_code','number','code','prefix_number','course_id'));
              if (!$course && !$code) continue;
              $key = strtolower(trim($code . '|' . $course));
              if (!isset($seen_courses[$key])) {
                  $seen_courses[$key] = true;
                  $unique_courses[] = array('code' => $code, 'course' => $course);
              }
          }
          ?>
          <ul class="ncv-inline-list">
            <?php foreach ($unique_courses as $c): ?>
              <li>
                <?php if ($c['code'] && $c['course']): ?>
                  <strong><?php echo esc_html($c['code']); ?></strong> — <?php echo esc_html($c['course']); ?>
                <?php elseif ($c['code']): ?>
                  <strong><?php echo esc_html($c['code']); ?></strong>
                <?php else: ?>
                  <?php echo esc_html($c['course']); ?>
                <?php endif; ?>
              </li>
            <?php endforeach; ?>
          </ul>
        <?php endif; ?>

        <?php if ($teaching_acts && !empty($teaching_acts['records'])): ?>
          <h3 class="ncv-subtitle">Teaching Activities and Advising</h3>
          <ul class="ncv-list">
            <?php foreach ($teaching_acts['records'] as $row):
              $desc   = sedona_field($row, array('activity','description','title','role','activity_description'));
              $detail = sedona_field($row, array('details','student_name','student','name','notes','additional'));
              $year   = sedona_field($row, array('year','date','from_year','until_year'));
              if (!$desc) continue;
              ?>
              <li>
                <?php echo esc_html($desc); ?>
                <?php if ($detail): ?> — <?php echo esc_html($detail); ?><?php endif; ?>
                <?php if ($year): ?> (<?php echo esc_html($year); ?>)<?php endif; ?>
              </li>
            <?php endforeach; ?>
          </ul>
        <?php endif; ?>

        <?php if ($exec_ed && !empty($exec_ed['records'])): ?>
          <h3 class="ncv-subtitle">Executive and Professional Education</h3>
          <ul class="ncv-list">
            <?php foreach ($exec_ed['records'] as $row):
              $prog = sedona_field($row, array('program','program_name','title','activity','course','description'));
              $aud  = sedona_field($row, array('audience','participants','organization','entity','client','company'));
              $year = sedona_field($row, array('year','date','from_year','until_year'));
              if (!$prog) continue;
              ?>
              <li>
                <?php echo esc_html($prog); ?>
                <?php if ($aud): ?> — <?php echo esc_html($aud); ?><?php endif; ?>
                <?php if ($year): ?> (<?php echo esc_html($year); ?>)<?php endif; ?>
              </li>
            <?php endforeach; ?>
          </ul>
        <?php endif; ?>

      </section>
      <?php endif; ?>

      <!-- ===== PUBLICATIONS & INTELLECTUAL CONTRIBUTIONS ===== -->
      <?php
      $pub_sections = array(
          'Refereed Articles'                   => $ref_articles,
          'Refereed Proceedings'                => $ref_proceedings,
          'Books and Monographs'                => $books,
          'Book Chapters and Cases'             => $chapters,
          'Invited Articles and Reviews'        => $invited,
          'Non-Refereed Articles'               => $nonref_articles,
          'Non-Refereed Proceedings'            => $nonref_proc,
          'Research Reports'                    => $reports,
          'Working Papers'                      => $working_papers,
          'Papers Under Review / In Revision'   => $wip_review,
          'Software and Datasets'               => $software,
          'Other Research'                      => $other_research,
      );
      $has_pubs = false;
      foreach ($pub_sections as $ps) { if ($ps && !empty($ps['records'])) { $has_pubs = true; break; } }
      ?>
      <?php if ($has_pubs): ?>
      <section class="ncv-section">
        <h2 class="ncv-section-title">Publications and Intellectual Contributions</h2>
        <?php foreach ($pub_sections as $sec_title => $sec_comp):
          if (!$sec_comp || empty($sec_comp['records'])) continue;
          ?>
          <h3 class="ncv-subtitle"><?php echo esc_html($sec_title); ?></h3>
          <ol class="ncv-pub-list">
            <?php foreach ($sec_comp['records'] as $row):
              echo sedona_format_citation($row);
            endforeach; ?>
          </ol>
        <?php endforeach; ?>
      </section>
      <?php endif; ?>

      <!-- ===== PRESENTATIONS ===== -->
      <?php if (($pres_ref && !empty($pres_ref['records'])) || ($pres_nonref && !empty($pres_nonref['records'])) || ($creative && !empty($creative['records'])) || ($media && !empty($media['records']))): ?>
      <section class="ncv-section">
        <h2 class="ncv-section-title">Presentations and Creative Activities</h2>
        <?php if ($pres_ref && !empty($pres_ref['records'])): ?>
          <h3 class="ncv-subtitle">Refereed Conference Presentations</h3>
          <ol class="ncv-pub-list">
            <?php foreach ($pres_ref['records'] as $row) { echo sedona_format_citation($row); } ?>
          </ol>
        <?php endif; ?>
        <?php if ($pres_nonref && !empty($pres_nonref['records'])): ?>
          <h3 class="ncv-subtitle">Invited and Non-Refereed Presentations</h3>
          <ol class="ncv-pub-list">
            <?php foreach ($pres_nonref['records'] as $row) { echo sedona_format_citation($row); } ?>
          </ol>
        <?php endif; ?>
        <?php if ($creative && !empty($creative['records'])): ?>
          <h3 class="ncv-subtitle">Creative Activities</h3>
          <ol class="ncv-pub-list">
            <?php foreach ($creative['records'] as $row) { echo sedona_format_citation($row); } ?>
          </ol>
        <?php endif; ?>
        <?php if ($media && !empty($media['records'])): ?>
          <h3 class="ncv-subtitle">Media Mentions and Interviews</h3>
          <ul class="ncv-list">
            <?php foreach ($media['records'] as $row):
              $title  = sedona_field($row, array('title','headline','article_title','description','topic'));
              $outlet = sedona_field($row, array('media_outlet','publication','outlet','source','channel','network','program','show'));
              $year   = sedona_field($row, array('year','date','publication_year'));
              ?>
              <li>
                <?php if ($title): ?><?php echo esc_html($title); ?><?php endif; ?>
                <?php if ($outlet): ?> — <em><?php echo esc_html($outlet); ?></em><?php endif; ?>
                <?php if ($year): ?> (<?php echo esc_html($year); ?>)<?php endif; ?>
              </li>
            <?php endforeach; ?>
          </ul>
        <?php endif; ?>
      </section>
      <?php endif; ?>

      <!-- ===== GRANTS ===== -->
      <?php if ($grants && !empty($grants['records'])): ?>
      <section class="ncv-section">
        <h2 class="ncv-section-title">Grants and Funded Research</h2>
        <ul class="ncv-list">
          <?php foreach ($grants['records'] as $row):
            $title  = sedona_field($row, array('project_title','title','grant_title','project_name','name','description'));
            $agency = sedona_field($row, array('funding_agency','agency','funding_source','sponsor','organization','entity','grantor'));
            $role   = sedona_field($row, array('role','pi_role','investigator_role','your_role'));
            $amount = sedona_field($row, array('amount','funded_amount','award_amount','total_amount','funding_amount','budget'));
            $from   = sedona_field($row, array('from_year','from','start_year','start_date','begin_year'));
            $until  = sedona_field($row, array('until_year','until','end_year','end_date','to_year'));
            $year   = sedona_field($row, array('year','date'));
            $period = '';
            if ($from && $until) $period = $from . '–' . $until;
            elseif ($from)       $period = $from . '–Present';
            elseif ($year)       $period = $year;
            if (!$title && !$agency) continue;
            ?>
            <li>
              <?php if ($title): ?><em><?php echo esc_html($title); ?></em><?php endif; ?>
              <?php if ($agency): ?> — <?php echo esc_html($agency); ?><?php endif; ?>
              <?php if ($role): ?> (<?php echo esc_html($role); ?>)<?php endif; ?>
              <?php if ($amount): ?> — <?php echo esc_html($amount); ?><?php endif; ?>
              <?php if ($period): ?> [<?php echo esc_html($period); ?>]<?php endif; ?>
            </li>
          <?php endforeach; ?>
        </ul>
      </section>
      <?php endif; ?>

      <!-- ===== PATENTS ===== -->
      <?php if ($patents && !empty($patents['records'])): ?>
      <section class="ncv-section">
        <h2 class="ncv-section-title">Patents and Inventions</h2>
        <ul class="ncv-list">
          <?php foreach ($patents['records'] as $row):
            $title  = sedona_field($row, array('patent_title','title','invention_title','name','patent','description'));
            $num    = sedona_field($row, array('patent_number','number','application_number','serial_number'));
            $status = sedona_field($row, array('status','patent_status','filing_status'));
            $year   = sedona_field($row, array('year','issue_year','filing_year','date'));
            if (!$title) continue;
            ?>
            <li>
              <?php echo esc_html($title); ?>
              <?php if ($num): ?> — Patent No. <?php echo esc_html($num); ?><?php endif; ?>
              <?php if ($status): ?> [<?php echo esc_html($status); ?>]<?php endif; ?>
              <?php if ($year): ?> (<?php echo esc_html($year); ?>)<?php endif; ?>
            </li>
          <?php endforeach; ?>
        </ul>
      </section>
      <?php endif; ?>

      <!-- ===== SERVICE ===== -->
      <?php if (($prof_service && !empty($prof_service['records'])) || ($inst_service && !empty($inst_service['records'])) || ($comm_service && !empty($comm_service['records']))): ?>
      <section class="ncv-section">
        <h2 class="ncv-section-title">Service</h2>

        <?php if ($inst_service && !empty($inst_service['records'])): ?>
          <h3 class="ncv-subtitle">Service to the Institution</h3>
          <ul class="ncv-list">
            <?php foreach ($inst_service['records'] as $row):
              $role = sedona_field($row, array('role','activity','committee','title','position','description','service_role','assignment'));
              $dept = sedona_field($row, array('department','dept','unit','division','college','entity','organization','school'));
              $from  = sedona_field($row, array('from_year','from','start_year','begin_year'));
              $until = sedona_field($row, array('until_year','until','end_year','to_year'));
              $year  = sedona_field($row, array('year','date','academic_year'));
              $period = '';
              if ($from && $until) $period = $from . '–' . $until;
              elseif ($from)       $period = $from . '–Present';
              elseif ($year)       $period = $year;
              if (!$role) continue;
              ?>
              <li>
                <?php echo esc_html($role); ?>
                <?php if ($dept): ?>, <?php echo esc_html($dept); ?><?php endif; ?>
                <?php if ($period): ?> (<?php echo esc_html($period); ?>)<?php endif; ?>
              </li>
            <?php endforeach; ?>
          </ul>
        <?php endif; ?>

        <?php if ($prof_service && !empty($prof_service['records'])): ?>
          <h3 class="ncv-subtitle">Service to the Profession</h3>
          <ul class="ncv-list">
            <?php foreach ($prof_service['records'] as $row):
              $role = sedona_field($row, array('role','activity','title','position','service_role','description','function'));
              $org  = sedona_field($row, array('organization','journal','entity','association','society','publisher','body'));
              $from  = sedona_field($row, array('from_year','from','start_year','begin_year'));
              $until = sedona_field($row, array('until_year','until','end_year','to_year'));
              $year  = sedona_field($row, array('year','date'));
              $period = '';
              if ($from && $until) $period = $from . '–' . $until;
              elseif ($from)       $period = $from . '–Present';
              elseif ($year)       $period = $year;
              if (!$role) continue;
              ?>
              <li>
                <?php echo esc_html($role); ?>
                <?php if ($org): ?>, <?php echo esc_html($org); ?><?php endif; ?>
                <?php if ($period): ?> (<?php echo esc_html($period); ?>)<?php endif; ?>
              </li>
            <?php endforeach; ?>
          </ul>
        <?php endif; ?>

        <?php if ($comm_service && !empty($comm_service['records'])): ?>
          <h3 class="ncv-subtitle">Service to the Community</h3>
          <ul class="ncv-list">
            <?php foreach ($comm_service['records'] as $row):
              $role = sedona_field($row, array('role','activity','title','position','description'));
              $org  = sedona_field($row, array('organization','entity','group','agency','nonprofit','body'));
              $from  = sedona_field($row, array('from_year','from','start_year','begin_year'));
              $until = sedona_field($row, array('until_year','until','end_year','to_year'));
              $year  = sedona_field($row, array('year','date'));
              $period = '';
              if ($from && $until) $period = $from . '–' . $until;
              elseif ($from)       $period = $from . '–Present';
              elseif ($year)       $period = $year;
              if (!$role) continue;
              ?>
              <li>
                <?php echo esc_html($role); ?>
                <?php if ($org): ?>, <?php echo esc_html($org); ?><?php endif; ?>
                <?php if ($period): ?> (<?php echo esc_html($period); ?>)<?php endif; ?>
              </li>
            <?php endforeach; ?>
          </ul>
        <?php endif; ?>
      </section>
      <?php endif; ?>

      <!-- ===== PROFESSIONAL MEMBERSHIPS ===== -->
      <?php if ($memberships && !empty($memberships['records'])): ?>
      <section class="ncv-section">
        <h2 class="ncv-section-title">Professional Memberships</h2>
        <ul class="ncv-inline-list">
          <?php foreach ($memberships['records'] as $row):
            $org = sedona_field($row, array('organization','organization_name','association','society','membership','group','body','name'));
            if (!$org) continue;
            ?>
            <li><?php echo esc_html($org); ?></li>
          <?php endforeach; ?>
        </ul>
      </section>
      <?php endif; ?>

      <!-- ===== HONORS / AWARDS ===== -->
      <?php if ($honors && !empty($honors['records'])): ?>
      <section class="ncv-section">
        <h2 class="ncv-section-title">Honors and Awards</h2>
        <ul class="ncv-list">
          <?php foreach ($honors['records'] as $row):
            $title = sedona_field($row, array('honor_name','honor','award_name','award','title','name','recognition','fellowship'));
            $org   = sedona_field($row, array('awarding_organization','organization','awarding_body','entity','source','granting_body'));
            $year  = sedona_field($row, array('year','award_year','date'));
            if (!$title) continue;
            ?>
            <li>
              <?php echo esc_html($title); ?>
              <?php if ($org): ?>, <?php echo esc_html($org); ?><?php endif; ?>
              <?php if ($year): ?> (<?php echo esc_html($year); ?>)<?php endif; ?>
            </li>
          <?php endforeach; ?>
        </ul>
      </section>
      <?php endif; ?>

      <!-- ===== PROFESSIONAL DEVELOPMENT ===== -->
      <?php if ($prof_dev && !empty($prof_dev['records'])): ?>
      <section class="ncv-section">
        <h2 class="ncv-section-title">Professional Development</h2>
        <ul class="ncv-list">
          <?php foreach ($prof_dev['records'] as $row):
            $activity = sedona_field($row, array('activity','activity_name','title','program','workshop','seminar','course','description','event'));
            $loc      = sedona_field($row, array('location','city','institution','organization','entity','host','sponsor'));
            $year     = sedona_field($row, array('year','date','from_year','until_year'));
            if (!$activity) continue;
            ?>
            <li>
              <?php echo esc_html($activity); ?>
              <?php if ($loc): ?>, <?php echo esc_html($loc); ?><?php endif; ?>
              <?php if ($year): ?> (<?php echo esc_html($year); ?>)<?php endif; ?>
            </li>
          <?php endforeach; ?>
        </ul>
      </section>
      <?php endif; ?>

      <!-- ===== SKILLS ===== -->
      <?php if ($skills && !empty($skills['records'])): ?>
      <section class="ncv-section">
        <h2 class="ncv-section-title">Skills</h2>
        <ul class="ncv-inline-list">
          <?php foreach ($skills['records'] as $row):
            $skill = sedona_field($row, array('skill','skill_name','name','description','competency','expertise','area'));
            if (!$skill) continue;
            ?>
            <li><?php echo esc_html($skill); ?></li>
          <?php endforeach; ?>
        </ul>
      </section>
      <?php endif; ?>

      <!-- ===== CONSULTING ===== -->
      <?php if ($consulting && !empty($consulting['records'])): ?>
      <section class="ncv-section">
        <h2 class="ncv-section-title">Consulting Experience</h2>
        <ul class="ncv-list">
          <?php foreach ($consulting['records'] as $row):
            $desc   = sedona_field($row, array('description','activity','title','project','engagement','nature_of_work'));
            $client = sedona_field($row, array('client','entity','organization','company','firm','client_organization'));
            $from   = sedona_field($row, array('from_year','from','start_year'));
            $until  = sedona_field($row, array('until_year','until','end_year'));
            $year   = sedona_field($row, array('year','date'));
            $period = '';
            if ($from && $until) $period = $from . '–' . $until;
            elseif ($from)       $period = $from . '–Present';
            elseif ($year)       $period = $year;
            if (!$desc && !$client) continue;
            ?>
            <li>
              <?php echo esc_html($desc); ?>
              <?php if ($client): ?> — <?php echo esc_html($client); ?><?php endif; ?>
              <?php if ($period): ?> (<?php echo esc_html($period); ?>)<?php endif; ?>
            </li>
          <?php endforeach; ?>
        </ul>
      </section>
      <?php endif; ?>

      <!-- ===== PAID SERVICE ===== -->
      <?php if ($paid_service && !empty($paid_service['records'])): ?>
      <section class="ncv-section">
        <h2 class="ncv-section-title">Paid Service and Expert Testimony</h2>
        <ul class="ncv-list">
          <?php foreach ($paid_service['records'] as $row):
            $desc   = sedona_field($row, array('description','activity','title','service','nature_of_work'));
            $entity = sedona_field($row, array('entity','organization','client','company','agency'));
            $from   = sedona_field($row, array('from_year','from','start_year'));
            $until  = sedona_field($row, array('until_year','until','end_year'));
            $year   = sedona_field($row, array('year','date'));
            $period = '';
            if ($from && $until) $period = $from . '–' . $until;
            elseif ($from)       $period = $from . '–Present';
            elseif ($year)       $period = $year;
            if (!$desc && !$entity) continue;
            ?>
            <li>
              <?php echo esc_html($desc); ?>
              <?php if ($entity): ?> — <?php echo esc_html($entity); ?><?php endif; ?>
              <?php if ($period): ?> (<?php echo esc_html($period); ?>)<?php endif; ?>
            </li>
          <?php endforeach; ?>
        </ul>
      </section>
      <?php endif; ?>

      <!-- ===== REFERENCES ===== -->
      <?php if ($references && !empty($references['records'])): ?>
      <section class="ncv-section">
        <h2 class="ncv-section-title">References</h2>
        <div class="ncv-references">
          <?php foreach ($references['records'] as $row):
            $fname = sedona_field($row, array('first_name','firstname'));
            $lname = sedona_field($row, array('last_name','lastname'));
            $name  = sedona_field($row, array('name','full_name','reference_name'));
            if (!$name) $name = trim("$fname $lname");
            $title = sedona_field($row, array('title','position','rank','job_title'));
            $inst  = sedona_field($row, array('institution','organization','university','employer','entity','company'));
            $email = sedona_field($row, array('email','email_address','e_mail'));
            $phone = sedona_field($row, array('phone','phone_number','telephone'));
            if (!$name) continue;
            ?>
            <div class="ncv-reference">
              <p class="ncv-ref-name"><?php echo esc_html($name); ?></p>
              <?php if ($title): ?><p class="ncv-ref-title"><?php echo esc_html($title); ?></p><?php endif; ?>
              <?php if ($inst):  ?><p class="ncv-ref-inst"><?php echo esc_html($inst); ?></p><?php endif; ?>
              <?php if ($email): ?><p class="ncv-ref-contact"><?php echo esc_html($email); ?></p><?php endif; ?>
              <?php if ($phone): ?><p class="ncv-ref-contact"><?php echo esc_html($phone); ?></p><?php endif; ?>
            </div>
          <?php endforeach; ?>
        </div>
      </section>
      <?php endif; ?>

      <!-- ===== MISC ===== -->
      <?php if ($misc && !empty($misc['records'])): ?>
      <section class="ncv-section">
        <h2 class="ncv-section-title">Other Professional Activities</h2>
        <ul class="ncv-list">
          <?php foreach ($misc['records'] as $row):
            $desc = sedona_field($row, array('description','activity','title','name','detail'));
            if (!$desc) {
                // Fall back: concatenate all non-empty values
                $desc = implode(' — ', array_filter(array_values($row)));
            }
            if (!$desc) continue;
            ?>
            <li><?php echo esc_html($desc); ?></li>
          <?php endforeach; ?>
        </ul>
      </section>
      <?php endif; ?>

      <!-- ===== UNMATCHED DATA TABLES (catch-all safety net) ===== -->
      <?php
      // Build set of all component titles already rendered above
      $rendered_keywords = array(
          'academic background','academic degree','degree','education',
          'work experience','employment','academic position','professional position','position held','work history',
          'consulting','paid service','expert testimony','expert witness',
          'certification','licensure','license',
          'membership','professional association','professional society',
          'skill','expertise','competency',
          'courses taught','course taught','teaching load',
          'teaching schedule','teaching sched','schedule',
          'teaching activit','advising','dissertation','thesis committee',
          'executive education','professional education','executive program',
          'refereed article','refereed journal','journal article',
          'refereed proceeding','refereed conference proceeding',
          'non-refereed article','nonrefereed article','non refereed article',
          'non-refereed proceeding','nonrefereed proceeding','non refereed proceeding',
          'invited article','invited review','invited chapter','invited book',
          'book','monograph','compilation','textbook','manual',
          'chapter','case study','reading','supplement','teaching case',
          'presentations of refereed','presentation of refereed','refereed presentation','refereed paper presented','refereed papers',
          'presentations of non-refereed','presentation of non-refereed','non-refereed presentation','nonrefereed presentation','invited presentation','invited talk',
          'patent','invention',
          'research report','technical report',
          'grant','funded research','research funding','contract',
          'creative activit','creative work','creative product','creative output',
          'under review','revision','paper submitted','submitted paper',
          'working paper','work in progress',
          'software','dataset','database',
          'other research','other intellectual',
          'media','news','press','interview',
          'institutional service','service to the institution','university service','college service','departmental service','school service','faculty service',
          'professional service','service to the profession','editorial','reviewer','journal service',
          'community service','service to the community','civic',
          'professional development','faculty development','workshop attended','training',
          'honor','award','recognition','fellowship','prize',
          'miscellaneous','other activit','other professional',
          'reference',
      );

      $catchall = array();
      foreach ($components as $comp) {
          if ($comp['type'] !== 'data_table' || empty($comp['records'])) continue;
          $haystack = strtolower(($comp['title'] ?? '') . ' ' . ($comp['tag_name'] ?? ''));
          $already_rendered = false;
          foreach ($rendered_keywords as $kw) {
              if (stripos($haystack, $kw) !== false) { $already_rendered = true; break; }
          }
          if (!$already_rendered) {
              $catchall[] = $comp;
          }
      }
      if (!empty($catchall)): ?>
      <section class="ncv-section">
        <h2 class="ncv-section-title">Additional Information</h2>
        <?php foreach ($catchall as $comp):
          if (empty($comp['records'])) continue;
          ?>
          <h3 class="ncv-subtitle"><?php echo esc_html($comp['title']); ?></h3>
          <ul class="ncv-list">
            <?php foreach ($comp['records'] as $row):
              $parts = array_filter(array_values($row));
              if (empty($parts)) continue;
              ?>
              <li><?php echo esc_html(implode(' — ', $parts)); ?></li>
            <?php endforeach; ?>
          </ul>
        <?php endforeach; ?>
      </section>
      <?php endif; ?>

    </div>
    <?php
    return ob_get_clean();
}

/**
 * Format a publication / presentation row into a clean academic citation.
 *
 * Sedona stores author names split across parallel first-name and last-name
 * fields (firstname1/lastname1, firstname2/lastname2 … or fname1/lname1, etc.)
 * This function reconstructs a readable author string from those fields, then
 * falls back to any pre-combined author field if none are found.
 */
function sedona_format_citation($row) {

    // ── 1. Reconstruct authors from split first/last fields ───────────────
    // Sedona uses patterns like: firstname1+lastname1, fname1+lname1,
    // author_first_name1+author_last_name1, first1+last1, etc.
    $authors = '';
    $author_parts = array();

    // Collect all keys once for efficient scanning
    $keys = array_keys($row);

    // Strategy A: numbered parallel first/last pairs
    $fn_prefixes = array('firstname','fname','first_name','author_first','authorfirst','pfirst','first');
    $ln_prefixes = array('lastname','lname','last_name','author_last','authorlast','plast','last');

    // Find the highest index present (support up to 20 authors)
    for ($i = 1; $i <= 20; $i++) {
        $fn = ''; $ln = '';
        foreach ($fn_prefixes as $p) {
            if (isset($row[$p . $i]) && $row[$p . $i] !== '') { $fn = $row[$p . $i]; break; }
        }
        foreach ($ln_prefixes as $p) {
            if (isset($row[$p . $i]) && $row[$p . $i] !== '') { $ln = $row[$p . $i]; break; }
        }
        if ($fn === '' && $ln === '') break; // no more authors at this index
        $name = trim($fn . ' ' . $ln);
        if ($name !== '') $author_parts[] = $name;
    }

    // Strategy B: un-numbered single first/last pair
    if (empty($author_parts)) {
        $fn = sedona_field($row, array('firstname','fname','first_name','author_first_name','pfirst'));
        $ln = sedona_field($row, array('lastname','lname','last_name','author_last_name','plast'));
        if ($fn || $ln) $author_parts[] = trim($fn . ' ' . $ln);
    }

    if (!empty($author_parts)) {
        $authors = implode(', ', $author_parts);
    }

    // Strategy C: pre-combined author field (articles / books)
    if ($authors === '') {
        $authors = sedona_field($row, array(
            'authors','author','co_authors','co_author','author_names',
            'all_authors','author_list','faculty_author','faculty_name',
        ));
    }

    // ── 2. Title ──────────────────────────────────────────────────────────
    $title = sedona_field($row, array(
        'title','paper_title','presentation_title','article_title',
        'book_title_main','chapter_title','work_title','paptitle','papertitle',
    ));

    // ── 3. Venue ──────────────────────────────────────────────────────────
    $journal   = sedona_field($row, array('journal','journal_name','publication','periodical','outlet','pub_name'));
    $book      = sedona_field($row, array('book_title','edited_volume','edited_book','editor_volume'));
    $conf      = sedona_field($row, array('conference','conference_name','proceedings','proceeding_name','meeting','event','symposium','forum','confname','conf_name'));
    $publisher = sedona_field($row, array('publisher','publishing_house','press','city_publisher'));
    $edition   = sedona_field($row, array('edition','ed'));
    $editors   = sedona_field($row, array('editors','editor','edited_by'));

    // ── 4. Date ───────────────────────────────────────────────────────────
    $year = sedona_field($row, array('year','pub_year','publication_year','year_published','date','papyear'));

    // ── 5. Volume / Issue / Pages ─────────────────────────────────────────
    $volume = sedona_field($row, array('volume','vol'));
    $issue  = sedona_field($row, array('issue','number','no','iss'));
    $pages  = sedona_field($row, array('pages','page_numbers','page_range','pp','pagerange'));

    // ── 6. Location (presentations) ───────────────────────────────────────
    $location_pres = sedona_field($row, array('location','city','city_state','presentation_location','preslocation','pres_location','paplocation'));

    // ── 7. Extras ─────────────────────────────────────────────────────────
    $status = sedona_field($row, array('status','review_status','publication_status','acceptance_status','papstatus'));
    $doi    = sedona_field($row, array('doi','digital_object_identifier'));
    $url    = sedona_field($row, array('url','link','web_address','uri'));

    // ── 8. Build citation string ──────────────────────────────────────────
    $out = '<li class="ncv-citation">';

    if ($authors) {
        $out .= '<span class="ncv-cite-authors">' . esc_html($authors) . '</span>. ';
    }
    if ($year) {
        $out .= '(' . esc_html($year) . '). ';
    }
    if ($title) {
        $out .= '<span class="ncv-cite-title">' . esc_html($title) . '</span>. ';
    }

    $venue_parts = array();
    if ($journal)   $venue_parts[] = '<em>' . esc_html($journal) . '</em>';
    if ($book)      $venue_parts[] = 'In <em>' . esc_html($book) . '</em>' . ($editors ? ' (Ed. ' . esc_html($editors) . ')' : '');
    if ($conf)      $venue_parts[] = '<em>' . esc_html($conf) . '</em>';
    if ($publisher) $venue_parts[] = esc_html($publisher) . ($edition ? ', ' . esc_html($edition) . ' ed.' : '');

    if (!empty($venue_parts)) {
        $out .= implode(', ', $venue_parts);
        $detail = array();
        if ($volume) $detail[] = esc_html($volume);
        if ($issue)  $detail[] = '(' . esc_html($issue) . ')';
        if ($pages)  $detail[] = esc_html($pages);
        if (!empty($detail)) $out .= ', ' . implode('', $detail);
        $out .= '.';
    }

    if ($location_pres) {
        $out .= ' <span class="ncv-cite-location">' . esc_html($location_pres) . '.</span>';
    }
    if ($status && !in_array(strtolower($status), array('presented','published','accepted'))) {
        // Only show status if it adds info beyond "Presented"
        $out .= ' <span class="ncv-cite-status">[' . esc_html($status) . ']</span>';
    }
    if ($doi) {
        $out .= ' <a href="https://doi.org/' . esc_attr($doi) . '" target="_blank" class="ncv-cite-link">DOI</a>';
    } elseif ($url && filter_var($url, FILTER_VALIDATE_URL)) {
        $out .= ' <a href="' . esc_url($url) . '" target="_blank" class="ncv-cite-link">[Link]</a>';
    }

    $out .= '</li>';
    return $out;
}

/* ============================================================
   4. SHORTCODES & DIRECTORY
   ============================================================ */

function shortcode_full_directory($atts) {
    $atts  = shortcode_atts(array('cvid' => SEDONA_CV_ID), $atts);
    $cv_id = sanitize_text_field($atts['cvid']);
    $members = sedona_get_all_members();
    if (isset($members['error'])) {
        return '<div class="sdir-error"><strong>Error loading members:</strong> '
            . esc_html($members['error'])
            . '<br><small>Check your API key and server IP whitelist in Sedona settings.</small></div>';
    }
    if (empty($members)) {
        return '<p>No members found in the system.</p>';
    }
    sedona_enqueue_styles();
    ob_start();
    ?>
    <div class="sdir-wrap">
      <div class="sdir-header">
        <h2 class="sdir-title">Faculty Directory</h2>
        <span class="sdir-count"><?php echo count($members); ?> members</span>
      </div>
      <div class="sdir-table-wrap">
        <table class="sdir-table">
          <thead>
            <tr><th>#</th><th>Name</th><th>Member ID</th><th>CV</th></tr>
          </thead>
          <tbody>
            <?php $i = 1; foreach ($members as $member): ?>
            <tr>
              <td class="sdir-num"><?php echo $i++; ?></td>
              <td class="sdir-name"><?php echo esc_html($member['fullname']); ?></td>
              <td class="sdir-id"><code><?php echo esc_html($member['id']); ?></code></td>
              <td class="sdir-action">
                <a href="<?php echo esc_url(add_query_arg(array(
                    'sedona_cv'   => '1',
                    'member_id'   => urlencode($member['id']),
                    'member_name' => urlencode($member['fullname']),
                    'cv_id'       => $cv_id,
                ))); ?>" target="_blank" class="sdir-btn">View CV</a>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
    <?php
    return ob_get_clean();
}
add_shortcode('sedona_full_directory', 'shortcode_full_directory');

function shortcode_member_count() {
    $members = sedona_get_all_members();
    if (isset($members['error'])) {
        return 'Error: ' . esc_html($members['error']);
    }
    return '<span style="font-size:28px;font-weight:bold;color:#8b1a1a;">'
         . count($members)
         . '</span> <span style="color:#666;">faculty members</span>';
}
add_shortcode('sedona_member_count', 'shortcode_member_count');

/* ============================================================
   5. CV PAGE (narrative + debug mode)
   ============================================================ */

add_action('template_redirect', function() {
    if (!isset($_GET['sedona_cv']) || $_GET['sedona_cv'] !== '1') return;

    $member_id   = sanitize_text_field(urldecode($_GET['member_id']   ?? ''));
    $member_name = sanitize_text_field(urldecode($_GET['member_name'] ?? ''));
    $cv_id       = sanitize_text_field($_GET['cv_id']     ?? SEDONA_CV_ID);
    $debug_mode  = isset($_GET['sedona_debug']) && current_user_can('manage_options');

    if (empty($member_id)) wp_die('No member ID provided.');

    $result = sedona_get_cv($member_id, $cv_id);

    // Inject name as header if the CV didn't include one
    if (!isset($result['error']) && !empty($member_name)) {
        $has_header = false;
        foreach ($result['components'] as $c) {
            if ($c['type'] === 'header') { $has_header = true; break; }
        }
        if (!$has_header) {
            array_unshift($result['components'], array('type' => 'header', 'content' => $member_name));
        }
    }
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
      <meta charset="UTF-8">
      <meta name="viewport" content="width=device-width, initial-scale=1.0">
      <title><?php echo esc_html($member_name); ?> — Faculty CV</title>
      <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        body {
          font-family: 'Georgia', 'Times New Roman', serif;
          font-size: 11.5pt;
          line-height: 1.6;
          color: #1a1a1a;
          background: #f4f4f0;
          padding: 30px 20px;
        }
        .ncv-page {
          max-width: 850px;
          margin: 0 auto;
          background: #fff;
          border: 1px solid #ccc;
          padding: 50px 60px;
        }
        /* Header */
        .ncv-header {
          text-align: center;
          margin-bottom: 28px;
          padding-bottom: 18px;
          border-bottom: 2px solid #1a1a1a;
        }
        .ncv-name {
          font-size: 22pt;
          font-weight: bold;
          color: #000;
          margin-bottom: 6px;
          letter-spacing: 0.3px;
        }
        .ncv-title-line { font-size: 11.5pt; color: #333; margin: 2px 0; }
        .ncv-contact-block { margin-top: 8px; display: flex; flex-wrap: wrap; justify-content: center; gap: 4px 18px; }
        .ncv-contact { font-size: 10.5pt; color: #444; }
        /* Sections */
        .ncv-section { margin-bottom: 22px; }
        .ncv-section-title {
          font-size: 11.5pt;
          font-weight: bold;
          color: #1a1a1a;
          text-transform: uppercase;
          letter-spacing: 0.8px;
          border-bottom: 1px solid #1a1a1a;
          padding-bottom: 3px;
          margin-bottom: 10px;
        }
        .ncv-subtitle {
          font-size: 11pt;
          font-weight: bold;
          color: #333;
          font-style: italic;
          margin: 14px 0 6px 0;
        }
        /* Education */
        .ncv-edu-item { margin-bottom: 5px; }
        .ncv-degree  { font-weight: bold; }
        .ncv-school  { font-style: italic; }
        .ncv-year    { color: #444; }
        .ncv-field   {}
        .ncv-honors  { color: #555; font-size: 10.5pt; }
        /* Positions */
        .ncv-position { margin-bottom: 10px; }
        .ncv-position-line { margin-bottom: 1px; }
        .ncv-position-dates { font-size: 10.5pt; color: #555; font-style: italic; }
        /* Lists */
        .ncv-list { list-style: disc; padding-left: 24px; margin-bottom: 8px; }
        .ncv-list li { margin-bottom: 4px; }
        .ncv-course-where { color: #555; font-size: 10.5pt; }
        .ncv-inline-list { list-style: none; padding-left: 0; display: flex; flex-wrap: wrap; gap: 6px 18px; }
        .ncv-inline-list li { position: relative; padding-left: 12px; }
        .ncv-inline-list li::before { content: "•"; position: absolute; left: 0; color: #555; }
        /* Publications */
        .ncv-pub-list { list-style: none; padding-left: 0; margin-bottom: 8px; counter-reset: pub-counter; }
        .ncv-pub-list li {
          margin-bottom: 8px;
          padding-left: 30px;
          text-indent: -30px;
          text-align: left;
          counter-increment: pub-counter;
        }
        .ncv-pub-list li::before {
          content: counter(pub-counter) ". ";
          font-weight: 600;
          color: #555;
        }
        .ncv-cite-authors  { font-weight: 600; }
        .ncv-cite-title    { font-style: italic; }
        .ncv-cite-status   { color: #666; font-size: 10pt; }
        .ncv-cite-location { color: #555; font-size: 10.5pt; }
        .ncv-cite-link     { color: #8b1a1a; text-decoration: none; font-size: 10pt; }
        .ncv-cite-link:hover { text-decoration: underline; }
        /* References */
        .ncv-references { display: grid; grid-template-columns: repeat(auto-fill, minmax(220px, 1fr)); gap: 16px; }
        .ncv-reference { font-size: 10.5pt; }
        .ncv-ref-name { font-weight: bold; margin-bottom: 2px; }
        .ncv-ref-title { font-style: italic; color: #333; }
        .ncv-ref-inst { color: #444; }
        .ncv-ref-contact { color: #555; font-size: 10pt; }
        /* Print bar */
        .ncv-print-bar { max-width: 850px; margin: 0 auto 14px auto; display: flex; gap: 8px; justify-content: flex-end; }
        .ncv-print-btn { background: #1a1a1a; color: #fff; border: none; padding: 8px 18px; font-size: 11pt; cursor: pointer; border-radius: 3px; font-family: Georgia, serif; }
        .ncv-print-btn:hover { background: #333; }
        .ncv-close-btn { background: #666; }
        .ncv-close-btn:hover { background: #444; }
        /* Error */
        .ncv-error { background: #fee; border: 1px solid #c00; padding: 20px; border-radius: 4px; color: #900; }
        /* Debug */
        .ncv-debug { background: #f0f8ff; border: 1px solid #aaa; padding: 16px 20px; max-width: 850px; margin: 0 auto 20px; font-family: monospace; font-size: 10pt; overflow-x: auto; }
        .ncv-debug summary { cursor: pointer; font-weight: bold; margin-bottom: 8px; }
        /* Print */
        @media print {
          .ncv-print-bar,.ncv-debug { display: none !important; }
          body { background: white; padding: 0; }
          .ncv-page { border: none; padding: 30px 40px; max-width: 100%; }
          a { text-decoration: none; color: #000; }
          .ncv-cite-link { display: none; }
        }
        @media (max-width: 640px) {
          body { padding: 10px; }
          .ncv-page { padding: 24px; }
          .ncv-references { grid-template-columns: 1fr; }
          .ncv-contact-block { flex-direction: column; align-items: center; gap: 2px; }
        }
      </style>
    </head>
    <body>
      <div class="ncv-print-bar">
        <button class="ncv-print-btn" onclick="window.print()">🖨 Print / Save PDF</button>
        <button class="ncv-print-btn ncv-close-btn" onclick="window.close()">✕ Close</button>
      </div>

      <?php if ($debug_mode && !isset($result['error'])): ?>
      <!-- DEBUG: Add &sedona_debug=1 to URL (admin only) to see raw component dump -->
      <details class="ncv-debug">
        <summary>🔍 Debug: Raw Components (<?php echo count($result['components']); ?> total)</summary>
        <pre><?php
          foreach ($result['components'] as $i => $c) {
              echo "[$i] type={$c['type']}";
              if (!empty($c['title']))   echo " | title=\"{$c['title']}\"";
              if (!empty($c['tag_name']))echo " | tag=\"{$c['tag_name']}\"";
              if (!empty($c['content'])) echo " | content=\"" . substr($c['content'], 0, 80) . "\"";
              if (!empty($c['records'])) {
                  echo " | records=" . count($c['records']);
                  echo "\n    FIELDS: " . implode(', ', array_keys($c['records'][0]));
                  if (!empty($c['records'][0])) {
                      echo "\n    SAMPLE: ";
                      $sample = array();
                      foreach (array_slice($c['records'][0], 0, 5, true) as $k => $v) {
                          $sample[] = "$k=" . substr($v, 0, 30);
                      }
                      echo implode(', ', $sample);
                  }
              }
              echo "\n";
          }
        ?></pre>
      </details>
      <?php endif; ?>

      <?php if (isset($result['error'])): ?>
        <div class="ncv-page">
          <div class="ncv-error">
            <strong>Could not load CV.</strong><br>
            <?php echo esc_html($result['error']); ?><br>
            <small>Member ID: <?php echo esc_html($member_id); ?></small>
          </div>
        </div>
      <?php else: ?>
        <?php echo sedona_render_narrative_cv($result['components']); ?>
      <?php endif; ?>
    </body>
    </html>
    <?php
    exit;
});

/* ============================================================
   6. DIRECTORY STYLES
   ============================================================ */

function sedona_enqueue_styles() {
    static $added = false;
    if ($added) return;
    $added = true;
    add_action('wp_head', function() { ?>
    <style>
      .sdir-error { background:#fee; border:1px solid #c00; padding:16px 20px; border-radius:4px; color:#900; font-family:Arial,sans-serif; }
      .sdir-wrap { max-width:900px; margin:0 auto; font-family:Arial,sans-serif; }
      .sdir-header { background:#1a1a1a; color:#fff; padding:16px 24px; border-radius:4px 4px 0 0; display:flex; justify-content:space-between; align-items:center; }
      .sdir-title { margin:0; font-size:20px; font-weight:bold; letter-spacing:0.5px; }
      .sdir-count { background:rgba(255,255,255,0.2); padding:4px 12px; border-radius:20px; font-size:13px; }
      .sdir-table-wrap { border:1px solid #ddd; border-top:none; overflow-x:auto; background:#fff; }
      .sdir-table { width:100%; border-collapse:collapse; font-size:14px; }
      .sdir-table thead th { background:#f5f5f5; padding:12px 16px; text-align:left; border-bottom:2px solid #ddd; font-size:13px; color:#444; font-weight:bold; }
      .sdir-table tbody td { padding:11px 16px; border-bottom:1px solid #eee; vertical-align:middle; }
      .sdir-table tbody tr:nth-child(even) td { background:#fafafa; }
      .sdir-table tbody tr:hover td { background:#f0f0f0; }
      .sdir-num { color:#999; font-size:12px; width:40px; }
      .sdir-name { font-weight:600; color:#1a1a1a; }
      .sdir-id code { background:#f0f0f0; padding:2px 7px; border-radius:3px; font-size:12px; color:#555; }
      .sdir-btn { display:inline-block; background:#1a1a1a; color:#fff !important; padding:6px 14px; border-radius:4px; font-size:12px; text-decoration:none !important; font-weight:600; transition:background 0.2s; }
      .sdir-btn:hover { background:#333 !important; color:#fff !important; }
    </style>
    <?php });
}

/* ============================================================
   7. ADMIN PAGE
   ============================================================ */

add_action('admin_menu', function() {
    add_menu_page('Sedona Directory', 'Sedona Directory', 'manage_options', 'sedona-dir', 'sedona_dir_admin', 'dashicons-groups', 30);
});

function sedona_dir_admin() {
    $members = sedona_get_all_members();
    ?>
    <div class="wrap">
      <h1>Sedona Faculty Directory</h1>
      <?php if (isset($members['error'])): ?>
        <div class="notice notice-error">
          <p><strong>Error:</strong> <?php echo esc_html($members['error']); ?></p>
          <p>Check your API key and make sure your server's IP is whitelisted in Sedona Web Service Settings.</p>
        </div>
      <?php else: ?>
        <div class="notice notice-success">
          <p>✅ Connected to Sedona. Found <strong><?php echo count($members); ?></strong> faculty members.</p>
        </div>
        <h2>Shortcodes</h2>
        <table class="widefat" style="max-width:600px;margin-bottom:20px;">
          <tr><td><code>[sedona_full_directory]</code></td><td>Full faculty list with View CV buttons (uses CV ID <?php echo SEDONA_CV_ID; ?>)</td></tr>
          <tr><td><code>[sedona_full_directory cvid="00001"]</code></td><td>Use a different CV template ID</td></tr>
          <tr><td><code>[sedona_member_count]</code></td><td>Show total faculty count</td></tr>
        </table>
        <p><strong>Tip:</strong> Add <code>&amp;sedona_debug=1</code> to any CV URL while logged in as admin to see the raw data dump — very useful for diagnosing missing fields.</p>
        <h2>Member List (first 20)</h2>
        <table class="widefat">
          <thead><tr><th>#</th><th>Name</th><th>ID</th><th>Test CV</th></tr></thead>
          <tbody>
            <?php foreach (array_slice($members, 0, 20) as $i => $m): ?>
            <tr>
              <td><?php echo $i + 1; ?></td>
              <td><?php echo esc_html($m['fullname']); ?></td>
              <td><code><?php echo esc_html($m['id']); ?></code></td>
              <td>
                <a href="<?php echo esc_url(add_query_arg(array(
                    'sedona_cv'    => '1',
                    'member_id'    => urlencode($m['id']),
                    'member_name'  => urlencode($m['fullname']),
                    'cv_id'        => SEDONA_CV_ID,
                    'sedona_debug' => '1',
                ), home_url('/'))); ?>" target="_blank" class="button">View CV + Debug</a>
              </td>
            </tr>
            <?php endforeach; ?>
            <?php if (count($members) > 20): ?>
            <tr><td colspan="4" style="text-align:center;color:#888;font-style:italic;">… and <?php echo count($members) - 20; ?> more members</td></tr>
            <?php endif; ?>
          </tbody>
        </table>
      <?php endif; ?>
    </div>
    <?php
}
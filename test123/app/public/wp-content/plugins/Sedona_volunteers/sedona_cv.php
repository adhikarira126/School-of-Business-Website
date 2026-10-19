<?php
// Add this to your existing sedona-volunteers.php file

// This is the template ID for College CV from your documentation
define('SEDONA_CV_ID', '00002');

// This function gets the CV data for a specific member using the Template API
function sedona_get_member_cv($member_id) {
    
    // Build the URL to get the template data
    // We use the scustomtemplate.cfc endpoint with getTemplate method
    $url = 'http://www.sedonaws.com/sd/scustomtemplate.cfc?method=getTemplate';
    $url .= '&key=' . urlencode(SEDONA_API_KEY);
    $url .= '&memberID=' . urlencode($member_id);
    $url .= '&cvID=' . SEDONA_CV_ID;
    $url .= '&includeMemo=Yes';
    $url .= '&includeFormat=Yes';
    
    // Send request to Sedona
    $response = wp_remote_get($url, array('timeout' => 30));
    
    // Check for connection errors
    if (is_wp_error($response)) {
        return array('error' => 'Connection failed: ' . $response->get_error_message());
    }
    
    // Get the response body
    $body = wp_remote_retrieve_body($response);
    
    // Check if we got an error response
    if (strpos($body, '<returncode>') !== false && strpos($body, '<code>1</code>') === false) {
        if (preg_match('/<description>(.*?)<\/description>/', $body, $matches)) {
            return array('error' => 'Sedona Error: ' . $matches[1]);
        }
    }
    
    // Extract XML from WDDX wrapper (same as before)
    if (preg_match('/<string>(.*?)<\/string>/s', $body, $matches)) {
        $xml_string = $matches[1];
        
        // Decode HTML entities
        $xml_string = html_entity_decode($xml_string, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        
        // Remove CDATA
        $xml_string = str_replace('<![CDATA[', '', $xml_string);
        $xml_string = str_replace(']]>', '', $xml_string);
        
        // Clean up
        $xml_string = trim($xml_string);
        
        // Parse XML
        libxml_use_internal_errors(true);
        $xml = simplexml_load_string($xml_string);
        
        if ($xml === false) {
            return array('error' => 'Could not parse CV data');
        }
        
        // Extract components from the CV
        $cv_data = array();
        
        if (isset($xml->components->component)) {
            foreach ($xml->components->component as $component) {
                // Get component type and content
                $component_type = $component->getName();
                $component_data = array();
                
                // Check what type of component this is
                if (isset($component->header)) {
                    $component_data['type'] = 'header';
                    $component_data['content'] = (string) $component->header;
                } elseif (isset($component->title)) {
                    $component_data['type'] = 'title';
                    $component_data['content'] = (string) $component->title;
                } elseif (isset($component->section)) {
                    $component_data['type'] = 'section';
                    $component_data['content'] = (string) $component->section;
                } elseif (isset($component->memo)) {
                    $component_data['type'] = 'memo';
                    $component_data['content'] = (string) $component->memo;
                } else {
                    // Regular data component
                    $component_data['type'] = 'data';
                    $component_data['content'] = $component->asXML();
                }
                
                $cv_data[] = $component_data;
            }
        }
        
        return array('success' => true, 'data' => $cv_data, 'raw' => $xml_string);
    }
    
    return array('error' => 'Could not retrieve CV data');
}

// Shortcode to display a member's CV: [sedona_cv memberid="0090046UWO"]
function sedona_cv_shortcode($atts) {
    
    // Get the member ID from the shortcode attributes
    $atts = shortcode_atts(array(
        'memberid' => '',
    ), $atts);
    
    $member_id = $atts['memberid'];
    
    // Check if member ID was provided
    if (empty($member_id)) {
        return '<p style="color: red;">Error: Please provide a member ID. Example: [sedona_cv memberid="0090046UWO"]</p>';
    }
    
    // Get the CV data
    $cv = sedona_get_member_cv($member_id);
    
    // Check for errors
    if (isset($cv['error'])) {
        return '<p style="color: red;">Error: ' . esc_html($cv['error']) . '</p>';
    }
    
    // Build the output
    $output = '<div class="sedona-cv" style="font-family: Arial, sans-serif; max-width: 800px; margin: 0 auto;">';
    
    // Loop through each component and display it
    foreach ($cv['data'] as $component) {
        switch ($component['type']) {
            case 'header':
                $output .= '<h1 style="color: #333; border-bottom: 2px solid #333; padding-bottom: 10px;">' . esc_html($component['content']) . '</h1>';
                break;
                
            case 'title':
                $output .= '<h2 style="color: #666; margin-top: 30px;">' . esc_html($component['content']) . '</h2>';
                break;
                
            case 'section':
                $output .= '<h3 style="color: #999; font-style: italic; margin-top: 20px;">' . esc_html($component['content']) . '</h3>';
                break;
                
            case 'memo':
                $output .= '<p style="background: #f5f5f5; padding: 15px; border-left: 4px solid #ccc;">' . nl2br(esc_html($component['content'])) . '</p>';
                break;
                
            case 'data':
                // For data components, just show the content
                $output .= '<div style="margin: 10px 0; padding: 10px; background: #fafafa;">' . esc_html($component['content']) . '</div>';
                break;
        }
    }
    
    $output .= '</div>';
    
    // Add debug info for admins
    if (current_user_can('manage_options')) {
        $output .= '<details style="margin-top: 20px; border: 1px solid #ccc; padding: 10px;">';
        $output .= '<summary>Debug: Raw XML Data</summary>';
        $output .= '<pre style="background: #f4f4f4; padding: 10px; overflow: auto; font-size: 11px;">' . esc_html($cv['raw']) . '</pre>';
        $output .= '</details>';
    }
    
    return $output;
}

// Register the shortcode
add_shortcode('sedona_cv', 'sedona_cv_shortcode');
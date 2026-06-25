<?php
/*
Plugin Name: Device Details
Plugin URI: https://github.com/SachinSAgrawal/YOURLS-Device-Details
Description: Displays click details, including IP/location, device information, a parsed user-agent, and more
Version: 3.2
Author: Sachin Agrawal
Author URI: https://sachinsagrawal.github.io/
*/

if ( !defined( 'YOURLS_ABSPATH' ) ) die();

// Add a settings page in the YOURLS admin interface
yourls_add_action( 'plugins_loaded', 'dd_admin_page_init' );
function dd_admin_page_init() {
    yourls_register_plugin_page( 'dd_settings', 'Device Details Settings', 'dd_settings_page_display' );
}

// Display the settings page with a form to input tokens
function dd_settings_page_display() {
    if( isset( $_POST['dd_token'] ) && isset( $_POST['dd_signature'] ) ) {
        yourls_verify_nonce( 'dd_settings_nonce' );
        
        $has_error = false;

        // Handle the IPinfo token
        $submitted_token = trim( $_POST['dd_token'] );
        $token_mask = str_repeat('•', 14);

        if ( $submitted_token !== $token_mask ) {
            if ( empty($submitted_token) || preg_match('/^[a-z0-9]{14}$/', $submitted_token) ) {
                yourls_update_option( 'dd_ipinfo_token', $submitted_token );
            } else {
                echo "<p style='color:red; font-weight:bold;'>Error: The IPinfo token must be exactly 14 characters long and contain only lowercase letters and numbers.</p>";
                $has_error = true;
            }
        }

        // Handle API Signature token
        $submitted_signature = strip_tags( trim( $_POST['dd_signature'] ) );
        $sig_mask = str_repeat('•', 10);
        
        if ( $submitted_signature !== $sig_mask ) {
            yourls_update_option( 'dd_api_signature', $submitted_signature );
        }

        if ( !$has_error ) {
            echo "<p style='color:green; font-weight:bold;'>Settings saved successfully!</p>";
        }
    }

    $token = yourls_get_option( 'dd_ipinfo_token', '' );
    $signature = yourls_get_option( 'dd_api_signature', '' );
    $nonce = yourls_create_nonce( 'dd_settings_nonce' );
    
    // Mask tokens for display
    $display_token = !empty($token) ? str_repeat('•', 14) : '';
    $display_signature = !empty($signature) ? str_repeat('•', 10) : '';
    
    echo "<main class='sub_wrap'>";
    echo "<h2>Device Details Settings</h2>";
    echo "<form method='post'>";
    echo "<input type='hidden' name='nonce' value='$nonce' />";
    
    echo "<h3>API Signature</h3>";
    echo "<p>Enter your YOURLS API signature token. Its required to generate the time-limited signature for AJAX logging and can be found in the 'Tools' page on the sidebar.</p>";
    echo "<p><label for='dd_signature'><b>API Signature:</b></label> <input type='text' id='dd_signature' name='dd_signature' value='" . $display_signature . "' size='30' /></p>";

    echo "<h3>IPinfo Token</h3>";
    echo "<p>Enter your <a href='https://ipinfo.io/' target='_blank'>ipinfo.io</a> API token below. This is used to fetch ISP information and map IP addresses to locations on the stats page. You can get a free token <a href='https://ipinfo.io/signup' target='_blank'>here</a>.</p>";
    echo "<p><label for='dd_token'><b>IPinfo Token:</b></label> <input type='text' id='dd_token' name='dd_token' value='" . $display_token . "' size='30' maxlength='14' /></p>";
    
    echo "<p><input type='submit' class='button button-primary' value='Save' /></p>";
    echo "</form>";
    echo "</main>";
}

// Prevent the native logging of clicks which doesn't require JavaScript
yourls_add_filter( 'shunt_log_redirect', 'dd_prevent_native_logging' );
function dd_prevent_native_logging( $return, $keyword ) {
    return true; 
}

// Inject the device info collector script into the redirect page
yourls_add_action( 'redirect_shorturl', 'dd_inject_collector_script' );
function dd_inject_collector_script( $args ) {
    $keyword = isset($args[1]) ? yourls_sanitize_keyword( $args[1] ) : '';
    
    if ( empty($keyword) ) {
        $path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
        $keyword = yourls_sanitize_keyword( basename($path) );
    }
    
    $plugin_url = yourls_plugin_url( dirname( __FILE__ ) );
    
    // Generate a time-limited signature for the request
    $secret_token = yourls_get_option( 'dd_api_signature', '' );
    $timestamp    = time();
    $time_sig     = md5( $timestamp . $secret_token );
    
    // Construct the endpoint with timestamp and temporary signature
    $endpoint = YOURLS_SITE . '/yourls-api.php?timestamp=' . $timestamp . '&signature=' . $time_sig . '&action=log_device_info&format=json';
    ?>
    <script src="<?php echo $plugin_url; ?>/uaparser.js"></script>
    <script src="<?php echo $plugin_url; ?>/incognito.js"></script>
    
    <script>
    // Gather device information and parse user-agent
    (function() {
        var keyword  = <?php echo json_encode( $keyword ); ?>;
        var endpoint = <?php echo json_encode( $endpoint ); ?>;
        
        var trueReferrer = document.referrer || 'direct';

        var payload = {
            la: navigator.language || '',
            or: typeof window.orientation !== 'undefined' ? window.orientation : '',
            to: ('ontouchstart' in window) || navigator.maxTouchPoints > 0 ? 'Yes' : 'No',
            si: screen.width + 'x' + screen.height,
            ba: '',
            in: 'No',
            ad: 'No',
            os: '', br: '', dm: '', dv: '', dt: '', en: ''
        };

        if (typeof UAParser !== 'undefined') {
            var uap = new UAParser();
            var result = uap.getResult();
            payload.os = result.os.name ? result.os.name + (result.os.version ? ' ' + result.os.version : '') : '';
            payload.br = result.browser.name ? result.browser.name + (result.browser.version ? ' ' + result.browser.version : '') : '';
            payload.dm = result.device.model || '';
            payload.dv = result.device.vendor || '';
            payload.dt = result.device.type || '';
            payload.en = result.engine.name ? result.engine.name + (result.engine.version ? ' ' + result.engine.version : '') : '';
        }

        var ADS_URL = "https://pagead2.googlesyndication.com/pagead/js/adsbygoogle.js";
        function detectAdBlock() {
            return fetch(ADS_URL, { method: 'HEAD', mode: 'no-cors' })
                .then(function() { payload.ad = 'No'; })
                .catch(function() { payload.ad = 'Yes'; });
        }

        // Send the collected information to the server via AJAX POST
        function sendDeviceInfo() {
            for (var key in payload) {
                if (payload[key] === '' || payload[key] === null) {
                    delete payload[key];
                }
            }
            
            var jsonStr = JSON.stringify(payload);

            fetch( endpoint, {
                method:  'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body:    'keyword=' + encodeURIComponent( keyword ) +
                         '&ref=' + encodeURIComponent( trueReferrer ) +
                         '&deviceInfo=' + encodeURIComponent( jsonStr )
            }).catch(function(e){}); 
        }

        Promise.all([
            detectAdBlock(),
            (typeof detectIncognito !== 'undefined' ? detectIncognito().then(function(res) { 
                payload.in = res.isPrivate ? 'Yes' : 'No'; 
            }).catch(function(e){}) : Promise.resolve()),
            ('getBattery' in navigator ? navigator.getBattery().then(function(b) { 
                payload.ba = Math.round(b.level * 100) + '%'; 
            }).catch(function(e){}) : Promise.resolve())
        ]).then(function() {
            sendDeviceInfo();
        });
    })();
    </script>
    <?php
}

// Register the AJAX endpoint to receive the device information
yourls_add_action( 'api_action_log_device_info', 'dd_receive_device_info' );
function dd_receive_device_info() {
    $keyword      = isset( $_POST['keyword'] )    ? yourls_sanitize_keyword( $_POST['keyword'] ) : '';
    $deviceinfo   = isset( $_POST['deviceInfo'] ) ? $_POST['deviceInfo'] : '{}';
    $original_ref = isset( $_POST['ref'] )        ? $_POST['ref'] : 'direct';

    if ( !$keyword ) {
        yourls_send_json_error( [ 'message' => 'Missing keyword' ] );
    }

    $table = YOURLS_DB_TABLE_LOG;
    $ip    = yourls_get_IP();
    
    $marker   = ' - ';
    $json_len = strlen($deviceinfo);
    
    $allowed_ref_len = 255 - strlen($marker) - $json_len;
    
    if ( $allowed_ref_len > 0 ) {
        $actual_referrer = substr( $original_ref, 0, $allowed_ref_len );
        $combined_referrer = $actual_referrer . $marker . $deviceinfo;
    } else {
        $combined_referrer = substr( $original_ref, 0, 10 ) . $marker . substr( $deviceinfo, 0, 240 );
    }

    $binds = [
        'now'      => date( 'Y-m-d H:i:s' ),
        'keyword'  => $keyword,
        'referrer' => $combined_referrer,
        'ua'       => substr( yourls_get_user_agent(), 0, 255 ),
        'ip'       => $ip,
        'location' => yourls_geo_ip_to_countrycode( $ip ),
    ];

    // Insert the log entry into the database
    try {
        $result = yourls_get_db()->fetchAffected(
            "INSERT INTO `$table`
             (click_time, shorturl, referrer, user_agent, ip_address, country_code)
             VALUES (:now, :keyword, :referrer, :ua, :ip, :location)",
            $binds
        );
        yourls_send_json_success( [ 'message' => 'Logged successfully', 'rows' => $result ] );
    } catch ( Exception $e ) {
        yourls_send_json_error( [ 'message' => 'Database insertion error' ] );
    }
}

// Helper function to get IP information
function get_ip_info($ip) {
    $token = trim( yourls_get_option( 'dd_ipinfo_token', '' ) );
    $url = "https://ipinfo.io/{$ip}/json";
    if ( !empty($token) ) {
        $url .= "?token=" . $token;
    }
    
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 3);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); 
    curl_setopt($ch, CURLOPT_REFERER, YOURLS_SITE);
    
    $response = curl_exec($ch);
    $curl_err = curl_error($ch);
    curl_close($ch);
    
    $data = json_decode($response, true);
    if (!is_array($data)) {
        $data = [];
    }
    
    $data['_debug_raw'] = $response;
    $data['_debug_err'] = $curl_err;
    
    return $data;
}

// Helper functions to convert timezone offsets
function get_timezone_offset($timezone) {
    try {
        $timezone_object = new DateTimeZone($timezone);
        $datetime = new DateTime("now", $timezone_object);
        return $timezone_object->getOffset($datetime) / 60;
    } catch (Exception $e) {
        return 0;
    }
}

function timezone_offset_to_gmt_offset($timezone_offset) {
    $timezone_offset = intval($timezone_offset);
    $sign = $timezone_offset < 0 ? '-' : '+';
    
    $abs_offset = abs($timezone_offset);
    $hours = floor($abs_offset / 60);
    $minutes = $abs_offset % 60;
    
    if ($minutes == 0) {
        return 'GMT' . $sign . $hours;
    } else {
        return 'GMT' . $sign . $hours . ':' . sprintf("%02d", $minutes);
    }
}

// Display the information on the stats page
yourls_add_action('post_yourls_info_stats', 'dd_ip_detail_page');
function dd_ip_detail_page($shorturl) {
    $table_log = YOURLS_DB_TABLE_LOG;
    $outdata   = '';

    $query = yourls_get_db()->fetchObjects(
        "SELECT * FROM `$table_log` WHERE shorturl = :shorturl ORDER BY click_id DESC LIMIT 1000",
        ['shorturl' => $shorturl[0]]
    );

    if ($query) {
        $ip_cache = []; 

        $device_dataseries = [];
        $browser_dataseries = [];
        $platforms_dataseries = [];

        // Loop through the query results and build the HTML table rows
        foreach ($query as $query_result) {
            $row_style = "";
            $current_ip_label = "";
            
            if ($query_result->ip_address == yourls_get_IP()) {
                $row_style = " bgcolor='#d4eeff'";
                $current_ip_label = "<br><i>This is Your IP</i>";
            }

            $ua = $query_result->user_agent;

            $ip = $query_result->ip_address;
            if (!isset($ip_cache[$ip])) {
                $ip_cache[$ip] = get_ip_info($ip);
            }
            $ip_info = $ip_cache[$ip];

            $click_time_utc = new DateTime($query_result->click_time, new DateTimeZone('UTC'));
            
            $timestamp_display = $click_time_utc->format('l') . '<br>' . 
                                 $click_time_utc->format('Y-m-d') . '<br>' . 
                                 $click_time_utc->format('H:i:s');

            $timezone_offset = isset($ip_info['timezone']) ? get_timezone_offset($ip_info['timezone']) : 0;
            $click_time_utc->modify($timezone_offset . ' minutes');
            $local_time = $click_time_utc->format('h:i:s') . '&nbsp;' . $click_time_utc->format('A');

            $gmt_offset = timezone_offset_to_gmt_offset($timezone_offset);
            
            $full_ref = $query_result->referrer;
            $split_pos = strpos($full_ref, ' - {');
            
            if ($split_pos !== false) {
                $real_ref = substr($full_ref, 0, $split_pos);
                $json_str = substr($full_ref, $split_pos + 3);
                $dev = json_decode($json_str, true);
            } else {
                $real_ref = $full_ref;
                $dev = [];
            }
            if (!is_array($dev)) $dev = [];

            $dv = !empty($dev['dv']) ? trim($dev['dv']) : '';
            $dm = !empty($dev['dm']) ? trim($dev['dm']) : '';
            $dt = !empty($dev['dt']) ? trim($dev['dt']) : '';

            // Custom logic to infer device type if not provided
            if (empty($dt) && !empty($dev['os'])) {
                $os_lower = strtolower($dev['os']);
                $desktop_os_keywords = ['windows', 'macos', 'linux', 'ubuntu', 'chrome os', 'fedora'];
                
                foreach ($desktop_os_keywords as $keyword) {
                    if (strpos($os_lower, $keyword) !== false) {
                        $dt = 'desktop';
                        break;
                    }
                }
            }

            // Populate pie chart metrics directly
            $device_type = !empty($dt) ? $dt : 'Unknown';
            $browser_name = !empty($dev['br']) ? trim(preg_replace('/ [0-9][0-9\.]*.*$/', '', $dev['br'])) : 'Unknown';
            $os_name = !empty($dev['os']) ? trim(preg_replace('/ [0-9][0-9\.]*.*$/', '', $dev['os'])) : 'Unknown';

            $device_dataseries = count_distinct_categories($device_type, $device_dataseries);
            $browser_dataseries = count_distinct_categories($browser_name, $browser_dataseries);
            $platforms_dataseries = count_distinct_categories($os_name, $platforms_dataseries);
            
            $local_time_info = $gmt_offset . '<br>' . $local_time;
            
            $city = !empty($ip_info['city']) ? $ip_info['city'] : '';
            $region = !empty($ip_info['region']) ? $ip_info['region'] : '';
            $country = !empty($query_result->country_code) ? $query_result->country_code : '';
            $location_info = implode(',<br>', array_filter([$city, $region, $country]));
            
            $isp = '';
            if (!empty($ip_info['org'])) {
                $isp = preg_replace('/^(\S+)\s+(.*)$/', '$1<br>$2', trim($ip_info['org']));
            }
            
            $browser_os_info = !empty($dev['br']) ? $dev['br'] : '';
            $browser_os_info .= !empty($dev['os']) ? '<br>' . $dev['os'] : '';
            
            // Format for the main stats table
            if (empty($dv) && empty($dm) && empty($dt)) {
                $device_info = 'Unknown';
            } else {
                $device_info = implode('<br>', array_filter([$dv, $dm, $dt]));
            }
            
            $engine_info = !empty($dev['en']) ? $dev['en'] : '';

            $interaction_info = '';
            $orientation = (isset($dev['or']) && is_numeric($dev['or'])) ? $dev['or'] : '';
            $interaction_info .= $orientation !== '' ? 'Rot:&nbsp;' . $orientation : '';
            
            $touch = !empty($dev['to']) ? $dev['to'] : '';
            $interaction_info .= $touch !== '' ? ($interaction_info !== '' ? '<br>' : '') . 'Touch:&nbsp;' . $touch : '';
            
            $size = !empty($dev['si']) ? $dev['si'] : '';
            $interaction_info .= $size !== '' ? ($interaction_info !== '' ? '<br>' : '') . 'Size:&nbsp;' . $size : '';

            $lang = !empty($dev['la']) ? $dev['la'] : '';
            $batt = !empty($dev['ba']) ? $dev['ba'] : 'undef';
            
            $other_metrics = [];
            if (!empty($dev['in'])) {
                $other_metrics[] = 'Incognito:&nbsp;' . $dev['in'];
            }
            if (!empty($dev['ad'])) {
                $other_metrics[] = 'AdBlock:&nbsp;' . $dev['ad'];
            }
            $other_info = implode('<br>', $other_metrics);

            // Build the HTML table row for this click
            $outdata .= '<tr'.$row_style.'>
                        <td>'.$timestamp_display.'</td>
                        <td>'.$local_time_info.'</td>
                        <td>'.$location_info.'</td>
                        <td>'.$isp.'</td>
                        <td><a href="https://ipinfo.io/'.$query_result->ip_address.'" target="blank">'.$query_result->ip_address.'</a>'.$current_ip_label.'</td>
                        <td style="max-width:300px; word-wrap:break-word; word-break:break-word; font-size:0.85em;">'.$ua.'</td>
                        <td>'.$browser_os_info.'</td>
                        <td>'.$device_info.'</td>
                        <td>'.$engine_info.'</td>
                        <td>'.$real_ref.'</td>
                        <td>'.$interaction_info.'</td>
                        <td>'.$lang.'</td>
                        <td>'.$batt.'</td>
                        <td>'.$other_info.'</td>
                        </tr>';
        }

        // Sort data descending for the charts
        arsort($device_dataseries);
        arsort($browser_dataseries);
        arsort($platforms_dataseries);

        // Output the complete HTML table
        echo '<table border="1" cellpadding="5" style="margin-top:25px; border-collapse:collapse; text-align:left;">
                <tr style="background:#f1f1f1; font-weight:bold;">
                    <td>Timestamp</td><td>Local Time</td><td>Location</td><td>ISP</td>
                    <td>IP Address</td><td>User Agent</td><td>Browser/OS</td><td>Device</td><td>Engine</td><td>Referrer</td>
                    <td>Screen</td><td>Language</td><td>Battery</td><td>Other</td>
                </tr>' . $outdata . '</table><br>' . "\n\r";

        // Output the pie charts
        echo "<br><br>";
        echo generate_open_table_html();
            echo generate_open_chartContainer_html(yourls_translate("Devices"));
            yourls_stats_pie( $device_dataseries, 4, '340x220', 'devices_pie' );
            echo generate_close_chartContainer_html($device_dataseries);            

            echo generate_open_chartContainer_html(yourls_translate("Browsers"));
            yourls_stats_pie( $browser_dataseries, 4, '340x220', 'browsers_pie' );
            echo generate_close_chartContainer_html($browser_dataseries);            

            echo generate_open_chartContainer_html(yourls_translate("Platforms"));
            yourls_stats_pie( $platforms_dataseries, 4, '340x220', 'platforms_pie' );
            echo generate_close_chartContainer_html($platforms_dataseries);
        echo generate_close_table_html();
    }
}

// Helper functions for pie chart generation and category counting
function count_distinct_categories(?string $category_name, array $counter) {
    $category_name ??= '';
    $category_name = $category_name === '' ? 'Unknown' : ucfirst($category_name);
    if (!array_key_exists($category_name, $counter)) {
        $counter[$category_name] = 0;
    }
    $counter[$category_name]++;
    return $counter;
}

function generate_open_table_html() : string {
    $tableHtml = <<<HTML
    <table border="0" cellspacing="2">
        <tbody>
            <tr>
    HTML;
    return $tableHtml;
}

function generate_close_table_html() : string {
    $tableHtml = <<<HTML
                </tr>
        </tbody>
    </table> 
    HTML;
    return $tableHtml;
}

function generate_open_chartContainer_html(string $chart_name) : string {
    $cardHtml = <<<HTML
    <td valign="top">
        <dashboard-pie caption="$chart_name">
            <div class="metrics-headline">
                <h3 class="ml16">$chart_name</h3>
            </div>
    HTML;
    return $cardHtml;
}

function generate_close_chartContainer_html(array $dataseries): string {
    $cardFooterHtml = <<<HTML
            <ul class="no_bullet">
    HTML;
    foreach ($dataseries as $group_name => $count) {
        $cardFooterHtml .= <<<HTML
                <li class='sites_list'>$group_name: <strong>$count</strong></li>
        HTML;
        unset($dataseries[$group_name]);
    }
    $cardFooterHtml .= <<<HTML
            </ul>
        </dashboard-pie>
    </td>
    HTML;
    return $cardFooterHtml;
}
?>
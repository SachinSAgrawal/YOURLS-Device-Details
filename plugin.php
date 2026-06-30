<?php
/*
Plugin Name: Device Details
Plugin URI: https://github.com/SachinSAgrawal/YOURLS-Device-Details
Description: Displays click details, including IP/location, device information, a parsed user-agent, and more
Version: 3.3
Author: Sachin Agrawal
Author URI: https://sachinsagrawal.github.io/
*/

if ( !defined( 'YOURLS_ABSPATH' ) ) die();

// Force a temporary redirect to prevent browser caching
yourls_add_filter( 'redirect_code', 'dd_force_302_redirect' );
function dd_force_302_redirect( $code, $location ) {
    return 302;
}

// Ensure the 'information' column exists in the database
yourls_add_action( 'plugins_loaded', 'dd_ensure_db_column' );
function dd_ensure_db_column() {
    // Prevent running a slow schema check on every single click
    if ( yourls_get_option( 'dd_info_column_created' ) ) {
        return; 
    }

    $table = YOURLS_DB_TABLE_LOG;
    $db = yourls_get_db();
    
    $sql = "SHOW COLUMNS FROM `$table` LIKE 'information'";
    $result = $db->fetchObjects($sql);
    
    if ( empty($result) ) {
        $db->query("ALTER TABLE `$table` ADD `information` TEXT NULL;");
    }
    
    // Set flag so this check never has to run again
    yourls_add_option( 'dd_info_column_created', true );
}

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

// Inject the device info collector script into the redirect page
yourls_add_action( 'redirect_shorturl', 'dd_inject_collector_script' );
function dd_inject_collector_script( $args ) {
    $target_url = isset($args[0]) ? $args[0] : '';
    $keyword = isset($args[1]) ? yourls_sanitize_keyword( $args[1] ) : '';
    
    if ( empty($keyword) ) {
        $path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
        $keyword = yourls_sanitize_keyword( basename($path) );
    }
    
    $plugin_url = yourls_plugin_url( dirname( __FILE__ ) );

    // Manually trigger the native logg before PHP execution is halted
    yourls_log_redirect( $keyword );
    
    // Generate a time-limited signature for the request
    $secret_token = yourls_get_option( 'dd_api_signature', '' );
    $timestamp    = time();
    $time_sig     = md5( $timestamp . $secret_token );
    
    // Construct the endpoint with timestamp and temporary signature
    $endpoint = YOURLS_SITE . '/yourls-api.php?timestamp=' . $timestamp . '&signature=' . $time_sig . '&action=log_device_info&format=json';
    ?>
    
    <noscript><meta http-equiv="refresh" content="0;url=<?php echo htmlspecialchars($target_url); ?>" /></noscript>

    <script src="<?php echo $plugin_url; ?>/uaparser.js"></script>
    <script src="<?php echo $plugin_url; ?>/incognito.js"></script>
    
    <script>
    // Gather device information and parse user-agent
    (function() {
        var keyword   = <?php echo json_encode( $keyword ); ?>;
        var endpoint  = <?php echo json_encode( $endpoint ); ?>;
        var targetUrl = <?php echo json_encode( $target_url ); ?>;
        var trueReferrer = document.referrer || 'direct';

        var payload = {
            la: navigator.language || '',
            or: typeof window.orientation !== 'undefined' ? window.orientation : '',
            to: ('ontouchstart' in window) || navigator.maxTouchPoints > 0 ? 'Yes' : 'No',
            si: `${screen.width}x${screen.height}`,
            ba: '', in: 'No', ad: 'No', os: '', br: '', dm: '', dv: '', dt: '', en: ''
        };

        // Parse the user-agent string if possible
        if (typeof UAParser !== 'undefined') {
            var result = new UAParser().getResult();
            
            payload.os = result.os.name ? `${result.os.name} ${result.os.version || ''}`.trim() : '';
            payload.br = result.browser.name ? `${result.browser.name} ${result.browser.version || ''}`.trim() : '';
            payload.en = result.engine.name ? `${result.engine.name} ${result.engine.version || ''}`.trim() : '';
            
            payload.dm = result.device.model || '';
            payload.dv = result.device.vendor || '';
            payload.dt = result.device.type || '';
        }

        // Safely redirect the user to the final destination
        function redirect() {
            if (targetUrl) window.location.replace(targetUrl);
        }

        // Send the collected information to the server, removing empty fields
        function sendDeviceInfo() {
            for (var key in payload) {
                if (payload[key] === '' || payload[key] == null) {
                    delete payload[key];
                }
            }

            fetch(endpoint, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: new URLSearchParams({ 
                    keyword: keyword, 
                    ref: trueReferrer, 
                    deviceInfo: JSON.stringify(payload) 
                })
            }).then(redirect).catch(redirect); 
        }

        // Ensure the tracking POST only fires once
        var isRedirecting = false;
        function finalAction() {
            if (isRedirecting) return;
            isRedirecting = true;
            sendDeviceInfo();
        }

        // Gather the asynchronous data
        Promise.all([
            fetch("https://pagead2.googlesyndication.com/pagead/js/adsbygoogle.js", { method: 'HEAD', mode: 'no-cors' })
                .then(function() { payload.ad = 'No'; }).catch(function() { payload.ad = 'Yes'; }),
                
            typeof detectIncognito !== 'undefined' ? detectIncognito().then(function(res) { 
                payload.in = res.isPrivate ? 'Yes' : 'No'; 
            }).catch(function(){}) : Promise.resolve(),
            
            'getBattery' in navigator ? navigator.getBattery().then(function(b) { 
                payload.ba = Math.round(b.level * 100) + '%'; 
            }).catch(function(){}) : Promise.resolve()
        ]).then(finalAction).catch(finalAction);

        // Failsafe to redirect after 1.5 seconds in case the AJAX request hangs
        setTimeout(finalAction, 1500);
    })();
    </script>
    <?php

    // Terminate PHP to prevent YOURLS from running its native instant redirect
    die();
}

// Register the AJAX endpoint to receive the device information
yourls_add_filter( 'api_action_log_device_info', 'dd_receive_device_info' );

function dd_receive_device_info( $return ) {
    $keyword      = isset( $_POST['keyword'] )    ? yourls_sanitize_keyword( $_POST['keyword'] ) : '';
    $deviceinfo   = isset( $_POST['deviceInfo'] ) ? $_POST['deviceInfo'] : '{}';
    $original_ref = isset( $_POST['ref'] )        ? $_POST['ref'] : 'direct';

    if ( !$keyword ) {
        return [ 'statusCode' => 400, 'message' => 'Missing keyword' ];
    }

    $table = YOURLS_DB_TABLE_LOG;
    $ip    = yourls_get_IP();
    
    $actual_referrer = substr( $original_ref, 0, 200 );

    $binds = [
        'now'         => date( 'Y-m-d H:i:s' ),
        'keyword'     => $keyword,
        'referrer'    => $actual_referrer,
        'ua'          => substr( yourls_get_user_agent(), 0, 255 ),
        'ip'          => $ip,
        'location'    => yourls_geo_ip_to_countrycode( $ip ),
        'information' => $deviceinfo
    ];

    // Update the existing log entry in the database
    try {
        $result = yourls_get_db()->fetchAffected(
            "UPDATE `$table`
             SET `referrer` = :referrer, `user_agent` = :ua, `information` = :information
             WHERE `shorturl` = :keyword AND `ip_address` = :ip
             ORDER BY `click_time` DESC LIMIT 1",
            $binds
        );
        return [ 'statusCode' => 200, 'message' => 'Logged successfully', 'rows' => $result ];
    } catch ( Exception $e ) {
        return [ 'statusCode' => 500, 'message' => 'Database update error' ];
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
        $tz = new DateTimeZone($timezone);
        return $tz->getOffset(new DateTime("now", $tz)) / 60;
    } catch (Exception $e) { return 0; }
}

function timezone_offset_to_gmt_offset($offset) {
    return sprintf("GMT%+d%s", intdiv($offset, 60), ($offset % 60) ? sprintf(":%02d", abs($offset % 60)) : "");
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
            $json_str = isset($query_result->information) ? $query_result->information : '';

            // Fallback for older logs that still have data appended to the referrer
            if (empty($json_str)) {
                $split_pos = strpos($full_ref, ' - {');
                if ($split_pos !== false) {
                    $real_ref = substr($full_ref, 0, $split_pos);
                    $json_str = substr($full_ref, $split_pos + 3);
                } else {
                    $real_ref = $full_ref;
                }
            } else {
                $real_ref = $full_ref;
            }
            
            $dev = json_decode($json_str, true);
            if (!is_array($dev)) $dev = [];

            $dv = !empty($dev['dv']) ? trim($dev['dv']) : '';
            $dm = !empty($dev['dm']) ? trim($dev['dm']) : '';
            $dt = !empty($dev['dt']) ? trim($dev['dt']) : '';

            // Custom logic to infer device type if not provided
            if (empty($dt) && !empty($dev['os']) && preg_match('/windows|mac|linux|ubuntu|chrome os|fedora/i', $dev['os'])) {
                $dt = 'desktop';
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
            $device_info = implode('<br>', array_filter([$dv, $dm, $dt])) ?: 'Unknown';
            
            $engine_info = !empty($dev['en']) ? $dev['en'] : '';

            $interactions = [];
            if (isset($dev['or']) && is_numeric($dev['or'])) $interactions[] = 'Rot:&nbsp;' . $dev['or'];
            if (!empty($dev['to'])) $interactions[] = 'Touch:&nbsp;' . $dev['to'];
            if (!empty($dev['si'])) $interactions[] = 'Size:&nbsp;' . $dev['si'];
            $interaction_info = implode('<br>', $interactions);

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

            $is_ghost = empty($json_str);
            $ghost_attr = $is_ghost ? ' data-ghost="true" data-ua="' . htmlspecialchars($ua, ENT_QUOTES) . '"' : '';

            // Build the HTML table row for this click, combining the last 4 columns if JavaScript didn't run
            $outdata .= '<tr'.$row_style.$ghost_attr.'>
                <td>'.$timestamp_display.'</td>
                <td>'.$local_time_info.'</td>
                <td>'.$location_info.'</td>
                <td>'.$isp.'</td>
                <td><a href="https://ipinfo.io/'.$query_result->ip_address.'" target="blank">'.$query_result->ip_address.'</a>'.$current_ip_label.'</td>
                <td style="max-width:300px; word-wrap:break-word; font-size:0.85em;">'.$ua.'</td>
                <td>'.$browser_os_info.'</td>
                <td>'.$device_info.'</td>
                <td>'.$engine_info.'</td>
                <td>'.$real_ref.'</td>
                ' . ($is_ghost 
                    ? '<td colspan="4" style="text-align:center; font-style:italic; color:#777;">Extended information unavailable: JavaScript may <br>have been disabled or this plugin was not running</td>' 
                    : "<td>$interaction_info</td><td>$lang</td><td>$batt</td><td>$other_info</td>") . '
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

        // Inject UAParser.js to retroactively parse ghost clicks on the client side
        $plugin_url = yourls_plugin_url( dirname( __FILE__ ) );
        echo '<script src="' . $plugin_url . '/uaparser.js"></script>
        <script>
        document.addEventListener("DOMContentLoaded", () => {
            if (typeof UAParser === "undefined") return;
            
            document.querySelectorAll("tr[data-ghost=\'true\']").forEach(row => {
                const r = new UAParser(row.dataset.ua).getResult();
                const format = (n, v) => n ? `${n} ${v || \'\'}`.trim() : \'\';
                const dt = r.device.type || (/windows|mac|linux|ubuntu|chrome os|fedora/i.test(r.os.name||\'\') ? "desktop" : "");
                
                const cells = row.querySelectorAll("td");
                if (cells.length > 8) {
                    cells[6].innerHTML = `${format(r.browser.name, r.browser.version)}<br>${format(r.os.name, r.os.version)}`;
                    cells[7].innerHTML = [r.device.vendor, r.device.model, dt].filter(Boolean).join("<br>") || "Unknown";
                    cells[8].innerHTML = format(r.engine.name, r.engine.version);
                }
            });
        });
        </script>';

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
<?php
/**
 * Plugin Name: Vehicle XML Feed Generator
 * Plugin URI: https://digitalcentury.me/
 * Description: Generates XML feed from vehicle posts and uploads to FTP server
 * Version: 1.0.0
 * Author: Jason
 * License: GPL2
 */

if ( ! defined( 'ABSPATH' ) ) exit;

// ---------- Activation / Deactivation: schedule cron ----------
register_activation_hook( __FILE__, 'cgfe_activate' );
register_deactivation_hook( __FILE__, 'cgfe_deactivate' );

function cgfe_activate() {
    if ( ! wp_next_scheduled( 'cgfe_daily_event' ) ) {
        wp_schedule_event( time(), 'daily', 'cgfe_daily_event' );
    }
}

function cgfe_deactivate() {
    wp_clear_scheduled_hook( 'cgfe_daily_event' );
}

// Hook daily event
add_action( 'cgfe_daily_event', 'cgfe_generate_and_upload_feed' );

// ---------- Admin settings ----------
add_action( 'admin_menu', 'cgfe_admin_menu' );
add_action( 'admin_init', 'cgfe_register_settings' );

function cgfe_admin_menu() {
    add_menu_page( 'Car Feed Export', 'Car Feed Export', 'manage_options', 'cgfe-settings', 'cgfe_settings_page' );
}

function cgfe_register_settings() {
    $group = 'cgfe_options_group';
    register_setting( $group, 'cgfe_ftp_host' );
    register_setting( $group, 'cgfe_ftp_user' );
    register_setting( $group, 'cgfe_ftp_pass' );
    register_setting( $group, 'cgfe_ftp_path' );
    register_setting( $group, 'cgfe_dealer_id' );
    register_setting( $group, 'cgfe_dealer_name' );
    register_setting( $group, 'cgfe_dealer_street' );
    register_setting( $group, 'cgfe_dealer_city' );
    register_setting( $group, 'cgfe_dealer_state' );
    register_setting( $group, 'cgfe_dealer_zip' );
    register_setting( $group, 'cgfe_dealer_crm_email' );
}

function cgfe_settings_page() {
    ?>
    <div class="wrap">
        <h1>CarGurus Feed Exporter</h1>
        <form method="post" action="options.php">
            <?php settings_fields( 'cgfe_options_group' ); do_settings_sections( 'cgfe_options_group' ); ?>
            <table class="form-table">
                <tr><th>FTP Host</th><td><input name="cgfe_ftp_host" value="<?php echo esc_attr( get_option('cgfe_ftp_host') ); ?>" /></td></tr>
                <tr><th>FTP User</th><td><input name="cgfe_ftp_user" value="<?php echo esc_attr( get_option('cgfe_ftp_user') ); ?>" /></td></tr>
                <tr><th>FTP Pass</th><td><input name="cgfe_ftp_pass" type="password" value="<?php echo esc_attr( get_option('cgfe_ftp_pass') ); ?>" /></td></tr>
                <tr><th>FTP Path (remote)</th><td><input name="cgfe_ftp_path" value="<?php echo esc_attr( get_option('cgfe_ftp_path','/') ); ?>" /></td></tr>
                <tr><th>Dealer ID</th><td><input name="cgfe_dealer_id" value="<?php echo esc_attr( get_option('cgfe_dealer_id') ); ?>" /></td></tr>
                <tr><th>Dealer Name</th><td><input name="cgfe_dealer_name" value="<?php echo esc_attr( get_option('cgfe_dealer_name') ); ?>" /></td></tr>
                <tr><th>Dealer Street</th><td><input name="cgfe_dealer_street" value="<?php echo esc_attr( get_option('cgfe_dealer_street') ); ?>" /></td></tr>
                <tr><th>Dealer City</th><td><input name="cgfe_dealer_city" value="<?php echo esc_attr( get_option('cgfe_dealer_city') ); ?>" /></td></tr>
                <tr><th>Dealer State</th><td><input name="cgfe_dealer_state" value="<?php echo esc_attr( get_option('cgfe_dealer_state') ); ?>" /></td></tr>
                <tr><th>Dealer ZIP</th><td><input name="cgfe_dealer_zip" value="<?php echo esc_attr( get_option('cgfe_dealer_zip') ); ?>" /></td></tr>
                <tr><th>Dealer CRM Email</th><td><input name="cgfe_dealer_crm_email" value="<?php echo esc_attr( get_option('cgfe_dealer_crm_email') ); ?>" /></td></tr>
            </table>
            <?php submit_button(); ?>
        </form>

        <h2>Manual actions</h2>
        <p>
            <form method="post">
                <?php submit_button( 'Generate & Upload Now', 'primary', 'cgfe_run_now' ); ?>
            </form>
        </p>
        <?php
        if ( isset( $_POST['cgfe_run_now'] ) && current_user_can('manage_options') ) {
            $res = cgfe_generate_and_upload_feed();
            echo '<h3>Result</h3><pre>' . esc_html( $res ) . '</pre>';
        }
        ?>
    </div>
    <?php
}

// ---------- Core: generate feed, upload ----------
function cgfe_generate_and_upload_feed() {
    global $wpdb;

    // Query posts that look like vehicle listings.
    // Adjust post_type filter based on your site (here we include 'listing' and 'post').
    $posts = get_posts( array(
        'post_type'   => array('listing','post','page'), // include page if your listings are pages
        'post_status' => 'publish',
        'numberposts' => -1,
    ) );

    $upload_dir = wp_upload_dir();
    $tmpfile = trailingslashit( $upload_dir['basedir'] ) . 'cgfe_feed_' . date('Ymd_His') . '.xml';

    // Build XML root
    $xml = new SimpleXMLElement('<?xml version="1.0" encoding="UTF-8"?><inventory></inventory>');

    foreach ( $posts as $post ) {
        // Heuristics to skip non-vehicle posts: check presence of key words
        $content = wp_strip_all_tags( $post->post_content );
        $title = trim( $post->post_title );

        // Quick filter: require either common vehicle words or images or a stock number
        if ( ! preg_match('/(Mileage|Exterior Color|Transmission|VIN|Stock Number|Mileage|Year|Make|Model|Mileage|\$)/i', $content . ' ' . $title ) ) {
            // skip likely non-listing
            continue;
        }

        // Start vehicle node
        $vehicle = $xml->addChild('vehicle');

        // 1) VIN - search post_content
        $vin = cgfe_extract_vin( $content );
        $vehicle->addChild('VIN', cgfe_xml_safe($vin));

        // 2) Price
        $price = cgfe_extract_price( $content );
        $vehicle->addChild('Price', cgfe_xml_safe($price));

        // 3) Year
        $year = cgfe_extract_year( $title . ' ' . $content );
        $vehicle->addChild('Year', cgfe_xml_safe($year));

        // 4) Make/Model/Trim - try split from title: "2017 Chevrolet Equinox LT 97333" etc.
        $make = $model = $trim = '';
        list( $make, $model, $trim ) = cgfe_parse_title_for_make_model_trim( $title );
        $vehicle->addChild('Make', cgfe_xml_safe($make));
        $vehicle->addChild('Model', cgfe_xml_safe($model));
        $vehicle->addChild('Trim', cgfe_xml_safe($trim));

        // 5) Mileage
        $mileage = cgfe_extract_mileage( $content );
        $vehicle->addChild('Mileage', cgfe_xml_safe($mileage));

        // 6) Image URLs: collect <img src="..."> and attached images
        $images = cgfe_extract_image_urls_from_content( $post->post_content );
        // also attachments
        $att = get_attached_media( 'image', $post->ID );
        foreach( $att as $a ) {
            $images[] = wp_get_attachment_url( $a->ID );
        }
        // dedupe and filter allowed formats
        $images = array_values( array_unique( array_filter( $images, 'cgfe_filter_image_format' ) ) );
        $imgsNode = $vehicle->addChild('ImageURLs');
        foreach ( $images as $img ) {
            $imgsNode->addChild('Image', cgfe_xml_safe($img));
        }

        // 7) Exterior Color
        $exterior = cgfe_extract_label_value( $content, 'Exterior Color' );
        $vehicle->addChild('ExteriorColor', cgfe_xml_safe($exterior));

        // 8) Dealer comments on vehicle (use excerpt or content block)
        $dealer_comments = cgfe_extract_label_value( $content, "Seller's Notes" );
        if ( empty($dealer_comments) ) $dealer_comments = cgfe_extract_label_value( $content, "Seller's Notes" , false);
        if ( empty($dealer_comments) ) $dealer_comments = cgfe_extract_label_value( $content, 'SELLER\'S NOTES' );
        if ( empty($dealer_comments) ) $dealer_comments = cgfe_extract_comments_fallback( $content );
        $vehicle->addChild('DealerComments', cgfe_xml_safe($dealer_comments));

        // 9) Stock Number
        $stock = cgfe_extract_label_value( $content, 'Stock Number' );
        $vehicle->addChild('StockNumber', cgfe_xml_safe($stock));

        // 10) Transmission Type
        $trans = cgfe_extract_label_value( $content, 'Transmission' );
        $vehicle->addChild('TransmissionType', cgfe_xml_safe($trans));

        // 11) Installed Options - try to pull a "Features" or "Installed Options" list
        $options = cgfe_extract_options( $post->post_content );
        $vehicle->addChild('InstalledOptions', cgfe_xml_safe( implode( '; ', $options ) ));

        // 12+) Dealer info from plugin options
        $vehicle->addChild('DealerID', cgfe_xml_safe(get_option('cgfe_dealer_id')));
        $vehicle->addChild('DealerName', cgfe_xml_safe(get_option('cgfe_dealer_name')));
        $vehicle->addChild('DealerStreetAddress', cgfe_xml_safe(get_option('cgfe_dealer_street')));
        $vehicle->addChild('DealerCity', cgfe_xml_safe(get_option('cgfe_dealer_city')));
        $vehicle->addChild('DealerState', cgfe_xml_safe(get_option('cgfe_dealer_state')));
        $vehicle->addChild('DealerZIP', cgfe_xml_safe(get_option('cgfe_dealer_zip')));
        $vehicle->addChild('DealerCRMEmail', cgfe_xml_safe(get_option('cgfe_dealer_crm_email')));

        // StockNumber fallback: if missing use post ID
        if ( empty( $stock ) ) {
            $vehicle->StockNumber = $post->ID;
        }
    }

    // Save XML to file
    $xml->asXML( $tmpfile );

    // Upload via FTP
    $ftp_host = get_option('cgfe_ftp_host');
    $ftp_user = get_option('cgfe_ftp_user');
    $ftp_pass = get_option('cgfe_ftp_pass');
    $ftp_path = get_option('cgfe_ftp_path','/');

    if ( empty($ftp_host) || empty($ftp_user) ) {
        return 'XML generated to ' . $tmpfile . ' — FTP credentials not configured.';
    }

    $upload_result = cgfe_ftp_upload( $ftp_host, $ftp_user, $ftp_pass, $tmpfile, $ftp_path );

    if ( is_wp_error( $upload_result ) ) {
        error_log( 'cgfe ftp error: ' . $upload_result->get_error_message() );
        return 'Upload failed: ' . $upload_result->get_error_message();
    }

    return 'XML generated and uploaded successfully to ' . esc_html( $ftp_host . $ftp_path );
}

// ---------- Helper functions: extraction, xml safe, ftp ----------

function cgfe_xml_safe( $s ) {
    if ( $s === null ) $s = '';
    return htmlspecialchars( trim( (string) $s ), ENT_XML1 | ENT_COMPAT, 'UTF-8' );
}

function cgfe_filter_image_format( $url ) {
    return (bool) preg_match( '/\.(jpe?g|png|gif|webp)$/i', $url );
}

function cgfe_extract_image_urls_from_content( $html ) {
    $urls = array();
    if ( preg_match_all( '/<img[^>]+src=[\'"]([^\'"]+)[\'"]/i', $html, $m ) ) {
        foreach( $m[1] as $u ) $urls[] = $u;
    }
    // sometimes links to images are anchors
    if ( preg_match_all( '/<a[^>]+href=[\'"]([^\'"]+\.(?:jpe?g|png|gif|webp))[\'"]/i', $html, $m2 ) ) {
        foreach( $m2[1] as $u ) $urls[] = $u;
    }
    return $urls;
}

function cgfe_extract_label_value( $hay, $label, $case_insensitive = true ) {
    $pattern = $case_insensitive ? '/'.preg_quote($label,'/').'\s*[:\-]?\s*([^\r\n\<]+)/i' : '/'.preg_quote($label,'/').'\s*[:\-]?\s*([^\r\n\<]+)/';
    if ( preg_match( $pattern, $hay, $m ) ) {
        return trim( strip_tags( $m[1] ) );
    }
    return '';
}

function cgfe_extract_vin( $text ) {
    // VINs are alphanumeric 11-17 except I,O,Q — simple heuristic:
    if ( preg_match( '/\b([A-HJ-NPR-Z0-9]{11,17})\b/i', $text, $m ) ) {
        return strtoupper( $m[1] );
    }
    return '';
}

function cgfe_extract_price( $text ) {
    if ( preg_match('/\$\s*([0-9\.,]+)/', $text, $m) ) {
        return str_replace(',', '', $m[1]);
    }
    // fallback: plain number followed by currency
    if ( preg_match('/([0-9\.,]+)\s*(USD|\$)/i', $text, $m2) ) {
        return str_replace(',', '', $m2[1]);
    }
    return '';
}

function cgfe_extract_year( $text ) {
    if ( preg_match('/\b(19|20)\d{2}\b/', $text, $m ) ) {
        return $m[0];
    }
    return '';
}

function cgfe_extract_mileage( $text ) {
    if ( preg_match('/([0-9]{1,3}(?:,[0-9]{3})+|[0-9]+)\s*(mi|miles|km|kms)?/i', $text, $m) ) {
        return str_replace(',', '', $m[1]) . ( isset($m[2]) ? ' '.$m[2] : '' );
    }
    return '';
}

function cgfe_parse_title_for_make_model_trim( $title ) {
    // Common patterns: "2017 Chevrolet Equinox LT 97333" or "BMW M5 F90 2024"
    // Remove year
    $t = preg_replace( '/\b(19|20)\d{2}\b/', '', $title );
    // remove price if present
    $t = preg_replace( '/\$\s*[0-9\.,]+/', '', $t );
    $parts = preg_split('/\s+/', trim($t));
    // naive approach: first token = make, second = model (if exists), rest = trim
    $make = isset($parts[0]) ? $parts[0] : '';
    $model = isset($parts[1]) ? $parts[1] : '';
    $trim = implode(' ', array_slice($parts, 2));
    return array( $make, $model, $trim );
}

function cgfe_extract_options( $html ) {
    // Try to parse <ul> lists under "Features" or "Options"
    $options = array();
    if ( preg_match_all( '/<h[23][^>]*>\s*(Features|Options|Installed Options|Equipment)\s*<\/h[23][^>]*>\s*(<ul[^>]*>.*?<\/ul>)/is', $html, $m ) ) {
        foreach( $m[2] as $ul ) {
            if ( preg_match_all('/<li[^>]*>(.*?)<\/li>/is', $ul, $li) ) {
                foreach( $li[1] as $val ) {
                    $txt = trim( strip_tags( $val ) );
                    if ( $txt ) $options[] = $txt;
                }
            }
        }
    }
    // fallback: any <li> items in content that look like features
    if ( empty($options) && preg_match_all('/<li[^>]*>(.*?)<\/li>/is', $html, $allli) ) {
        foreach( $allli[1] as $val ) {
            $txt = trim( strip_tags( $val ) );
            if ( strlen($txt) > 3 && preg_match('/(Engine|Transmission|Exterior|Interior|Bluetooth|Navigation|AWD|4WD|Sunroof|Leather)/i', $txt) ) {
                $options[] = $txt;
            }
        }
    }
    return array_slice( array_unique($options), 0, 50 );
}

function cgfe_extract_comments_fallback( $content ) {
    // look for paragraphs under SELLER'S NOTES or "Dealer comments" heading
    if ( preg_match('/(SELLER\'S NOTES|Seller\'s Notes|Dealer comments|Dealer Comments)(.*?)(<h|$)/is', $content, $m) ) {
        return trim( strip_tags($m[2]) );
    }
    // fallback: first 300 chars of content
    return wp_trim_words( $content, 60, '...' );
}

function cgfe_ftp_upload( $host, $user, $pass, $localfile, $remote_path = '/' ) {
    if ( ! file_exists( $localfile ) ) {
        return new WP_Error( 'no_local_file', 'Local file not found: ' . $localfile );
    }

    $conn = @ftp_connect( $host, 21, 10 );
    if ( ! $conn ) {
        return new WP_Error( 'ftp_connect_failed', 'Could not connect to FTP host: ' . $host );
    }

    $login = @ftp_login( $conn, $user, $pass );
    if ( ! $login ) {
        ftp_close( $conn );
        return new WP_Error( 'ftp_login_failed', 'FTP login failed for ' . $user );
    }

    ftp_pasv( $conn, true );

    // Ensure remote path exists (try to create recursively)
    $cur = ftp_pwd( $conn );
    if ( $remote_path && $remote_path !== '/' ) {
        $parts = explode('/', trim($remote_path, '/') );
        foreach ( $parts as $p ) {
            if ( $p === '' ) continue;
            if ( @ftp_chdir( $conn, $p ) === false ) {
                if ( ! @ftp_mkdir( $conn, $p ) ) {
                    // ignore if cannot create
                } else {
                    @ftp_chdir( $conn, $p );
                }
            }
        }
        // back to root of connection
    }

    // Put file - name it with fixed filename or timestamped
    $remote_filename = rtrim($remote_path,'/') . '/' . basename( $localfile );
    // if path was just '/', remote_filename may start with '/'
    $upload_ok = @ftp_put( $conn, basename($localfile), $localfile, FTP_BINARY );
    // Note: ftp_put uses current directory on server; above we changed cwd to remote_path earlier.
    ftp_close( $conn );

    if ( ! $upload_ok ) {
        return new WP_Error( 'ftp_put_failed', 'FTP upload failed.' );
    }
    return true;
}

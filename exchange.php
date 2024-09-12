<?php
/*
Plugin Name: exchange price
Description: Real time exchange rate WordPress plugin
Version: 1.2
Author: Elementor Jet (RasoolTA)
*/

// Hook to schedule the task upon plugin activation
register_activation_hook(__FILE__, 'afp_activate_plugin');

// Hook to unschedule the task upon plugin deactivation
register_deactivation_hook(__FILE__, 'afp_deactivate_plugin');

// Create custom database table when plugin is activated
function afp_activate_plugin() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'api_data'; // Table name with prefix
    $charset_collate = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE IF NOT EXISTS $table_name (
        id BIGINT(20) NOT NULL AUTO_INCREMENT,
        item_id BIGINT(20) NOT NULL,
        name VARCHAR(255) NOT NULL,
        title VARCHAR(255) NOT NULL,
        price VARCHAR(255) NOT NULL,
        high_price VARCHAR(255),
        low_price VARCHAR(255),
        open_price VARCHAR(255),
        change_value VARCHAR(255),
        change_percent DECIMAL(5,2),
        updated_at DATETIME NOT NULL,
        dt VARCHAR(255),
        t VARCHAR(255),
        prices TEXT,
        PRIMARY KEY (id),
        UNIQUE (item_id)
    ) $charset_collate;";

    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    dbDelta($sql);

    // Schedule the hourly event if not already scheduled
    if (!wp_next_scheduled('afp_hourly_event')) {
        wp_schedule_event(time(), 'hourly', 'afp_hourly_event');
    }
}

// Unschedule the event and remove the table when plugin is deactivated
function afp_deactivate_plugin() {
    $timestamp = wp_next_scheduled('afp_hourly_event');
    wp_unschedule_event($timestamp, 'afp_hourly_event');

    // Optionally, drop the custom table
    // global $wpdb;
    // $table_name = $wpdb->prefix . 'api_data';
    // $sql = "DROP TABLE IF EXISTS $table_name";
    // $wpdb->query($sql);
}

// Hook to the scheduled event
add_action('afp_hourly_event', 'afp_fetch_api_data');

// Function to fetch data from the API and store it in the database
function afp_fetch_api_data() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'api_data'; // Custom table name

    $url = 'https://api.tgju.org/v1/widget/tmp?keys=523875,523874,523877,523876,137121,137138,137137,137139,137140,137141';
    
    // Fetch the data from the API
    $response = wp_remote_get($url);
    
    if (is_wp_error($response)) {
        return; // Handle error if needed
    }
    
    $body = wp_remote_retrieve_body($response);
    $data = json_decode($body, true);

    if (isset($data['response']['indicators'])) {
        foreach ($data['response']['indicators'] as $indicator) {
            $item_id = intval($indicator['item_id']);
            $name = sanitize_text_field($indicator['name']);
            $title = sanitize_text_field($indicator['title']);
            $price = sanitize_text_field($indicator['p']);
            $high_price = sanitize_text_field($indicator['h']);
            $low_price = sanitize_text_field($indicator['l']);
            $open_price = sanitize_text_field($indicator['o']);
            $change_value = sanitize_text_field($indicator['d']);
            $change_percent = floatval($indicator['dp']);
            $updated_at = sanitize_text_field($indicator['updated_at']);
            $dt = isset($indicator['dt']) ? sanitize_text_field($indicator['dt']) : ''; // Check if dt exists
            $t = isset($indicator['t']) ? sanitize_text_field($indicator['t']) : '';   // Check if t exists
            $prices = isset($indicator['prices']) ? sanitize_textarea_field($indicator['prices']) : ''; // Check if prices exists

            // Check if the item_id already exists
            $existing_item = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM $table_name WHERE item_id = %d",
                $item_id
            ));

            if ($existing_item) {
                // Update the existing record
                $wpdb->update(
                    $table_name,
                    [
                        'name' => $name,
                        'title' => $title,
                        'price' => $price,
                        'high_price' => $high_price,
                        'low_price' => $low_price,
                        'open_price' => $open_price,
                        'change_value' => $change_value,
                        'change_percent' => $change_percent,
                        'updated_at' => $updated_at,
                        'dt' => $dt,
                        't' => $t,
                        'prices' => $prices,
                    ],
                    ['item_id' => $item_id]
                );
            } else {
                // Insert a new record
                $wpdb->insert(
                    $table_name,
                    [
                        'item_id' => $item_id,
                        'name' => $name,
                        'title' => $title,
                        'price' => $price,
                        'high_price' => $high_price,
                        'low_price' => $low_price,
                        'open_price' => $open_price,
                        'change_value' => $change_value,
                        'change_percent' => $change_percent,
                        'updated_at' => $updated_at,
                        'dt' => $dt,
                        't' => $t,
                        'prices' => $prices,
                    ]
                );
            }
        }
    }
}

// Add a menu item in the WordPress admin
add_action('admin_menu', 'afp_create_admin_page');

// Function to create admin page
function afp_create_admin_page() {
    add_menu_page(
        'API Data', // Page title
        'API Data', // Menu title
        'manage_options', // Capability
        'afp-api-data', // Menu slug
        'afp_admin_page_content', // Callback function
        'dashicons-chart-line', // Icon
        6 // Position
    );
}

// Callback function to display the content of the admin page, including the update button
function afp_admin_page_content() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'api_data';
    $results = $wpdb->get_results("SELECT * FROM $table_name");

    if (!$results) {
        echo '<p>No data found in the database.</p>';
    }

    // Check if an update button was clicked
    if (isset($_POST['afp_update_data'])) {
        afp_fetch_api_data(); // Fetch and update data
        echo '<div class="updated"><p>Data has been updated successfully.</p></div>';
        $results = $wpdb->get_results("SELECT * FROM $table_name"); // Refresh the results
    }

    echo '<div class="wrap">';
    echo '<h1>API Data</h1>';
    
    // Update Button Form
    echo '<form method="post">';
    echo '<input type="submit" name="afp_update_data" class="button button-primary" value="Update Data" />';
    echo '</form>';
    
    // Display Data Table
    if ($results) {
        echo '<table class="widefat fixed" cellspacing="0">';
        echo '<thead>';
        echo '<tr>';
        echo '<th class="manage-column column-columnname" scope="col">Item Name</th>';
        echo '<th class="manage-column column-columnname" scope="col">Title</th>';
        echo '<th class="manage-column column-columnname" scope="col">Price</th>';
        echo '<th class="manage-column column-columnname" scope="col">High Price</th>';
        echo '<th class="manage-column column-columnname" scope="col">Low Price</th>';
        echo '<th class="manage-column column-columnname" scope="col">Open Price</th>';
        echo '<th class="manage-column column-columnname" scope="col">Change Value</th>';
        echo '<th class="manage-column column-columnname" scope="col">Change Percent</th>';
        echo '<th class="manage-column column-columnname" scope="col">Updated At</th>';
        echo '<th class="manage-column column-columnname" scope="col">DT</th>';
        echo '<th class="manage-column column-columnname" scope="col">T</th>';
        echo '<th class="manage-column column-columnname" scope="col">Prices</th>';
        echo '</tr>';
        echo '</thead>';
        echo '<tbody>';

        foreach ($results as $row) {
            echo '<tr>';
            echo '<td>' . esc_html($row->name) . '</td>';
            echo '<td>' . esc_html($row->title) . '</td>';
            echo '<td>' . esc_html($row->price) . '</td>';
            echo '<td>' . esc_html($row->high_price) . '</td>';
            echo '<td>' . esc_html($row->low_price) . '</td>';
            echo '<td>' . esc_html($row->open_price) . '</td>';
            echo '<td>' . esc_html($row->change_value) . '</td>';
            echo '<td>' . esc_html($row->change_percent) . '</td>';
            echo '<td>' . esc_html($row->updated_at) . '</td>';
            echo '<td>' . esc_html($row->dt) . '</td>';
            echo '<td>' . esc_html($row->t) . '</td>';
            echo '<td>' . esc_html($row->prices) . '</td>';
            echo '</tr>';
        }

        echo '</tbody>';
        echo '</table>';
    }

    echo '</div>';
}
?>

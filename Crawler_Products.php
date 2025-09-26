<?php

final class Crawler_Products
{

    private static array $source_urls = [
        'https://test.com/products.json',
        'https://test_2.com/products.json',
    ];
    private static string $date_format = 'Y-m-d H:i:s';
    private static string $timezone = 'Europe/Malta';
    private string $table_name = '';
    private string $emails = '';
    private array $products_to_update = [];
    private array $products_new = [];
    private string $cron_hook = 'cron_hook_crawler_products';
    private string $cron_id = 'cron_id_crawler_products';

    public function __construct()
    {
        global $wpdb;
        $this->table_name = $wpdb->prefix.'crawler_products';
        $this->emails     = function_exists('get_field') ? trim(get_field('emails', 'options')) : '';

        add_action('init', [$this, 'db_add_table_crawler_products']);

        // AJAX crawler products on button click in admin
        add_action('wp_ajax_scan_crawler_products', [$this, 'scan_on_ajax']);

        if ( ! wp_next_scheduled($this->cron_hook, [$this->cron_id])) {
            $timestamp = strtotime(
                'tomorrow 3 am',
            ); // by UTC it will be at morning 1 am. Examples: tomorrow 2 am / today 5:15 am
            wp_schedule_event($timestamp, 'daily', $this->cron_hook, [$this->cron_id]);
        }

        add_action($this->cron_hook, [$this, 'cron_handle_crawler_products']);
    }

    public function db_add_table_crawler_products()
    {
        if (wp_doing_cron() || wp_doing_ajax()) {
            return;
        }

        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE `$this->table_name` (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            sku TEXT NOT NULL,
            price TEXT NOT NULL,
            title TEXT NOT NULL,
            updated TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id)
        ) $charset_collate;";

        require_once(ABSPATH.'wp-admin/includes/upgrade.php');
        maybe_create_table($this->table_name, $sql);

        $newColumn = 'source_url';
        $sql       = "ALTER TABLE `$this->table_name` ADD `$newColumn` TEXT NOT NULL DEFAULT '' AFTER `id`;";
        maybe_add_column($this->table_name, $newColumn, $sql);
    }

    public function cron_handle_crawler_products($arg)
    {
        if (wp_doing_cron() && isset($arg) && $arg === $this->cron_id) {
            $result = $this->crawler_job(self::$source_urls);

            if ( ! empty($result['is_db_updated']) && empty($result['is_email_sent'])) {
                error_log('Crawler_Products_CRON: Products were changed in data base success, but email was not sent');
            }
        }
    }

    public function scan_on_ajax()
    {
        if ( ! trim($this->emails)) {
            wp_send_json(['message' => 'Please, add email above to get report.', 'status' => false]);
        }

        $result = $this->crawler_job(self::$source_urls);

        if ( ! empty($result['is_email_sent'])) {
            wp_send_json(['message' => 'Success! Please check your email', 'status' => true]);
        } elseif ( ! empty($result['is_db_updated'])) {
            wp_send_json(
                [
                    'message' => 'Products were changed in data base. Unfortunately email is not sent. Ask admin help!',
                    'status'  => false,
                ],
            );
        } else {
            wp_send_json(['message' => 'Nothing is changed', 'status' => true]);
        }
    }

    /**
     * @param  array  $urls  Array of string Urls to fetch products
     *
     * @return array Returns associative array [ is_db_updated => true|false, is_email_sent => true|false ]
     */
    public function crawler_job(array $urls): array
    {
        $result = [
            'is_db_updated' => false,
            'is_email_sent' => false,
        ];

        $message = '';
        foreach ($urls as $url) {
            $is_table_db_changed = $this->scan($url);
            if ($is_table_db_changed) {
                $result['is_db_updated'] = true;
                $message                 .= $this->prepare_message_for_email($url);
            }
        }

        if ($result['is_db_updated']) {
            $result['is_email_sent'] = $this->send_email($this->emails, 'Changes from competitors site', $message);
        }

        return $result;
    }

    /**
     * Main function of crawling products
     *
     * @param  string  $target_url  Url to request array of products
     *
     * @return bool TRUE if new products were added or current products were updated. Otherwise FALSE
     */
    public function scan(string $target_url): bool
    {
        ini_set('max_execution_time', 60);

        if (filter_var($target_url, FILTER_VALIDATE_URL) === false) {
            return false;
        }

        $is_added   = false;
        $is_updated = false;

        $products = $this->fetch_all_products($target_url);

        if ($products) {
            $this->sort_products_to_new_or_update($products, $target_url);

            if ( ! empty($this->products_new[$target_url])) {
                $is_added = $this->db_add_new_crawler_products($this->products_new[$target_url], $target_url);
            }

            if ( ! empty($this->products_to_update[$target_url])) {
                $is_updated = $this->db_update_crawler_products($this->products_to_update[$target_url]);
            }
        }

        if ($is_added || $is_updated) {
            return true;
        } else {
            return false;
        }
    }

    /**
     * @param  string  $target_url  Url to request data
     *
     * @return array Array of all products (objects)
     */
    private function fetch_all_products(string $target_url): array
    {
        $all_products = [];
        $limit        = 500;
        $page         = 1;

        while (true) {
            $products = $this->send_request($target_url, $limit, $page);

            if (empty($products)) {
                break;
            }

            $all_products = array_merge($all_products, $products);
            $page++;
        }

        return $all_products;
    }

    /**
     * @param  string  $target_url  Url to request data
     * @param  int  $limit  Amount of product per request
     * @param  int  $page  Pagination page
     *
     * @return array Array of products (objects)
     */
    private function send_request(string $target_url, int $limit, int $page = 1): array
    {
        $url      = add_query_arg(['limit' => $limit, 'page' => $page], $target_url);
        $response = wp_safe_remote_get($url);
        $data     = json_decode(wp_remote_retrieve_body($response));

        return ! empty($data->products) ? $data->products : [];
    }

    private function sort_products_to_new_or_update(array $products, string $source_url)
    {
        foreach ($products as $product) {
            if ( ! empty($product->variants) && is_array($product->variants)) {
                // go through of all variants
                foreach ($product->variants as $variant) {
                    if (isset($variant->sku) && ! empty(trim($variant->sku))) {
                        $sku            = $variant->sku;
                        $price          = $variant->price ?? null;
                        $variant->title = $product->title.' '.$variant->title; // concatenation title product with variant title

                        $saved_product = $this->db_get_data_product_by_sku($sku, $source_url);
                        $saved_price   = $saved_product->price ?? null;

                        if ($saved_price === null) {
                            $this->products_new[$source_url][] = $variant;
                        } elseif ($saved_price !== $price && ! empty($saved_product->id)) {
                            $this->products_to_update[$source_url][$saved_product->id] = $variant;
                        }
                    }
                }
            }
        }
    }

    /**
     * @param  string  $sku  SKU of fetched remote product
     * @param  string  $source_url  Requested source URL as identifier
     *
     * @return \stdClass|null
     */
    private function db_get_data_product_by_sku(string $sku, string $source_url)
    {
        global $wpdb;

        $query = $wpdb->prepare(
            "SELECT * FROM `$this->table_name` WHERE `sku` = '%s' AND `source_url` = '%s'",
            $sku,
            $source_url,
        );

        return $wpdb->get_row($query);
    }

    /**
     * @param  array  $products_new
     * @param  string  $source_url
     *
     * @return bool|int|\mysqli_result
     */
    private function db_add_new_crawler_products(array $products_new, string $source_url)
    {
        global $wpdb;

        $source_url = esc_sql($source_url);
        $updated    = date(self::$date_format, $this->get_timestamp_in_malta_timezone());

        // Initialize an array to hold the query parts (prepare each row for the bulk insert)
        $sql_query_part = [];
        foreach ($products_new as $product) {
            $sku   = esc_sql($product->sku);
            $price = esc_sql($product->price);
            $title = esc_sql($product->title);

            $sql_query_part[] = "('$source_url', '$sku', '$price', '$title', '$updated')";
        }

        // Construct the full SQL query
        $values = implode(', ', $sql_query_part);
        $sql    = "INSERT INTO `$this->table_name` (`source_url`, `sku`, `price`, `title`, `updated`) VALUES {$values}";

        return $wpdb->query($sql);
    }

    /**
     * @return int Timestamp in seconds in Malta timezone
     */
    private function get_timestamp_in_malta_timezone()
    {
        $date        = new DateTime();
        $timezoneObj = new DateTimeZone(self::$timezone);
        $date->setTimezone($timezoneObj);

        return $date->getTimestamp() + $date->getOffset();
    }

    /**
     * @param  array  $products_to_update
     *
     * @return bool|int|\mysqli_result
     */
    private function db_update_crawler_products(array $products_to_update)
    {
        global $wpdb;
        $updated = date(self::$date_format, $this->get_timestamp_in_malta_timezone());

        // Initialize arrays to hold the different parts of the SQL query (prepare the SQL parts)
        $case_price   = [];
        $case_title   = [];
        $case_updated = [];
        $ids          = [];

        foreach ($products_to_update as $id => $product) {
            $price = esc_sql($product->price);
            $title = esc_sql($product->title);

            // Prepare the CASE WHEN parts for each field
            $case_price[]   = "WHEN $id THEN '$price'";
            $case_title[]   = "WHEN $id THEN '$title'";
            $case_updated[] = "WHEN $id THEN '$updated'";

            // Collect the IDs
            $ids[] = $id;
        }

        // Construct the full SQL query
        $prices  = implode(' ', $case_price);
        $titles  = implode(' ', $case_title);
        $updates = implode(' ', $case_updated);
        $ids     = implode(', ', $ids);

        $sql = "UPDATE `$this->table_name` SET 
            `price` = CASE `id` {$prices} END, 
            `title` = CASE `id` {$titles} END, 
            `updated` = CASE `id` {$updates} END 
            WHERE `id` IN ({$ids});
            ";

        return $wpdb->query($sql);
    }

    private function prepare_message_for_email(string $source_url): string
    {
        $products_to_update = $this->products_to_update[$source_url] ?? [];
        $products_new       = $this->products_new[$source_url] ?? [];
        $none               = 'No changes';
        $message            = '';

        $host = strtoupper(parse_url($source_url, PHP_URL_HOST));

        // Start creating message
        $message .= "<strong>Changes from {$host}: </strong>".$source_url;
        $message .= '<br><br>';

        // Updated prices
        $message .= "<strong>{$host}</strong> Products with updated prices:";
        $message .= '<br>';
        $message .= $this->convert_products_array_to_string_email($products_to_update) ?: $none;

        // Delimiter
        $message .= '<br><br>-------------------------------------------------------------------------------------<br><br>';

        // New products
        $message .= "<strong>{$host}</strong> New products were added:";
        $message .= '<br>';
        $message .= $this->convert_products_array_to_string_email($products_new) ?: $none;

        // END Delimiter
        $message .= '<br><br>============== END OF '.$host.' =================================================<br>';
        $message .= '<br>=========================================================================================<br><br>';

        // End creating message

        return $message;
    }

    /**
     * @param  array  $products
     *
     * @return string List of product as a text
     */
    private function convert_products_array_to_string_email(array $products): string
    {
        $text = '';
        $ind  = 1;
        foreach ($products as $product) {
            $sku   = $product->sku;
            $title = $product->title;
            $price = $product->price;

            $text .= '<br>';
            $text .= "<strong>$ind) SKU:</strong> $sku <strong>Title:</strong> $title <strong>Price:</strong> $price";

            $ind++;
        }

        return $text;
    }

    /**
     * @param  string  $emails  Comma-separated list of email addresses to send message.
     * @param  string  $subject  Email subject.
     * @param  string  $message  Message contents.
     *
     * @return bool True whether the email was sent successfully.
     */
    private function send_email(string $emails, string $subject, string $message): bool
    {
        if ( ! trim($emails) || ! trim($subject) || ! trim($message)) {
            return false;
        }

        $headers = [
            'Content-Type: text/html; charset=UTF-8',
            'From: '.get_bloginfo('name').' <'.get_option('admin_email').'>',
        ];

        return wp_mail($emails, $subject, $message, $headers);
    }

}

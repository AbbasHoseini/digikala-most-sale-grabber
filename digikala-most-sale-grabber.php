<?php
/*
Plugin Name: Digikala Most Sale Grabber
Plugin URI: https://example.com/1
Description: A custom plugin to add functionality to my WordPress site.
Version: 1.0
Author: Afshin
Author URI: https://example.com
License: GPL2
*/

function fetch_data_from_digikala_intervals($schedules)
{
    $schedules["every_minute"] = array(
        "interval" => 60,
        "display" => _("Every Minute")
    );
    return $schedules;
}

add_action('plugins_loaded', function () {
    add_filter("cron_schedules", "fetch_data_from_digikala_intervals");
});


//url : https://api.digikala.com/v1/categories/personal-appliance/search/?page=2&sort=7

function fetch_data_from_digikala()
{

    if (get_transient('digikala_import_running')) {
        error_log('Importer already running');
        return;
    }

    set_transient('digikala_import_running', 1, 3600);


    set_time_limit(400 * 60);
    error_log("fetch_data_from_digikala called");
    try {
        for ($i = 100; $i >= 1; $i--) {
            try {

                $response = try_fetch_page_records($i);
                if (!empty($response) && is_array($response) && $response["status"] == 200) {
                    $products = $response["data"]["products"];
                    foreach ($products as $product) {
                        error_log("work on product with id {$product['id']} started");
                        if (!check_product_exists($product['id'])) {
                            sleep(2);
                            $moreProductInfo = try_fetch_product_info($product['id']);
                            if (!empty($moreProductInfo) && is_array($moreProductInfo) && $moreProductInfo["status"] == 200) {
                                $productInfo = $moreProductInfo["data"]["product"];
                                create_woocommerce_product($product, $productInfo);
                            } else {
                                error_log("for product with id {$product['id']} moreProductInfo is" . json_encode($moreProductInfo));
                                continue;
                            }
                        }
                    }
                }
            } catch (\Exception) {
            }
            sleep(60);
        }
    } catch (\Exception $ex) {
        error_log("fetch_data_from_digikala error", $ex->getMessage());
    } finally {
        delete_transient('digikala_import_running');
    }
}

// function check_product_exists($product)
// {
//     $existProducts = get_posts(array(
//         'post_type' => 'product',
//         'meta_key' => 'external_product_id',
//         'meta_value' => $product['id'],
//         'posts_per_page' => 1, // Limit to 1 result
//     ));

//     if (!empty($existProducts)) {
//         error_log("product {$product['id']} exist in database");
//     }

//     return !empty($existProducts);
// }
function check_product_exists($product_id)
{
    error_log("Checking product id: " . $product_id);

    global $wpdb;

    $exists = $wpdb->get_var($wpdb->prepare("
        SELECT post_id 
        FROM {$wpdb->postmeta}
        WHERE meta_key = 'external_product_id'
        AND meta_value = %s
        LIMIT 1
    ", $product_id));

    error_log("Exists result: " . ($exists ? "YES" : "NO"));
    return !empty($exists);
}

function create_woocommerce_product($data, $moreProductInfo)
{

    // جلوگیری از ساخت محصول تکراری
    $existing_product_id = wc_get_product_id_by_sku('digikala-' . $data['id']);

    if ($existing_product_id) {
        error_log("Product {$data['id']} already exists by SKU. Skipping.");
        return false;
    }



    error_log("========== PRODUCT DEBUG START ==========");
    error_log("DATA (search API): " . print_r($data, true));
    error_log("MORE PRODUCT INFO (v2 API): " . print_r($moreProductInfo, true));
    error_log("========== PRODUCT DEBUG END ==========");


    sleep(2); //microsecond
    error_log("create_woocommerce_product called ");
    $imageUrl = $moreProductInfo["images"]["main"]["url"][0];
    $attachment_id = upload_image($imageUrl);
    if (empty($attachment_id))
        return false;


    $categories = get_terms(array(
        'taxonomy'   => 'product_cat', // Taxonomy for product categories
        'hide_empty' => false, // Set to true to only get categories that have products
    ));

    // error_log('categories are ' . json_encode($categories));
    // error_log("attachment_id is $attachment_id ");

    $product = new WC_Product_Simple();
    $product->set_name($data['title_fa']);
    $product->set_price($data["default_variant"]["price"]["selling_price"]);
    $product->set_regular_price($data["default_variant"]["price"]["rrp_price"]);
    $product->set_short_description($moreProductInfo['test_title_fa']);
    $product->set_description($moreProductInfo['review']["description"]);
    $product->set_status('publish');
    $product->set_catalog_visibility('visible');
    $product->set_rating_counts($moreProductInfo["rating"]["count"]);
    $product->set_average_rating($moreProductInfo["rating"]["rate"] / 10);
    $product->set_sku('digikala-' . $data['id']);
    $product->set_manage_stock(false); // Set to true if you want to manage stock
    $product->set_stock_status('instock'); // 'instock' or 'outofstock'
    $product->set_category_ids([$categories[count($categories) - 1]->term_id]); // Set category IDs if required
    $product->set_image_id($attachment_id); // Set image if you have an attachment ID




    // Update the post in the database

    try {

        error_log("Before save SKU: " . $product->get_sku());

        $existing_product_id = wc_get_product_id_by_sku(
            $product->get_sku()
        );

        error_log("Before save existing_product_id: " . $existing_product_id);

        $product_id = $product->save();


        error_log("Saved product id: " . $product_id);

        if (!empty($product_id)) {
            update_post_meta($product_id, 'external_product_id', $data['id']); // Store your external ID

            $post_data = array(
                'ID'           => $product_id,
                'post_content' => $moreProductInfo['review']["description"], // New content for the post
            );
            wp_update_post($post_data);
        }
        error_log("Product saved successfully!");
    } catch (Exception $e) {
        error_log("Error saving product: " . $e->getMessage());
    }

    return true;
}

function upload_image($image_url)
{
    error_log("upload_image called");
    require_once(ABSPATH . 'wp-admin/includes/image.php');
    require_once(ABSPATH . 'wp-admin/includes/file.php');
    require_once(ABSPATH . 'wp-admin/includes/media.php');
    // Download image
    $temp_file = download_url($image_url);

    if (is_wp_error($temp_file)) {
        return false;
    }

    $path = parse_url($image_url, PHP_URL_PATH);
    $image_name = basename($path);


    // Define file array
    $file = [
        'name'     => $image_name,
        'type'     => mime_content_type($temp_file),
        'tmp_name' => $temp_file,
        'error'    => 0,
        'size'     => filesize($temp_file),
    ];
    // error_log("file for upload is " . json_encode($file));

    // Upload image to the media library
    $attachment_id = media_handle_sideload($file, 0);

    // Check for errors
    if (is_wp_error($attachment_id)) {
        @unlink($temp_file);
        error_log("attachment error is " . $attachment_id->get_error_message());
        return false;
    }

    return $attachment_id;
}


function try_fetch_page_records($page, $tryCount = 3)
{
    $response = null;
    $counter = 0;
    while ($counter < $tryCount) {
        $response = fetch_page_records($page);
        if (is_array($response) && $response["status"] == 200)
            break;
        else if (is_array($response) && $response["status"] == 302) {
            error_log("product redirected");
            break;
        } else {
            $counter++;
            sleep(2);
            continue;
        }
    }
    return $response;
}


function fetch_page_records($page)
{
    $response = fetch_data_from_api("https://api.digikala.com/v1/categories/personal-appliance/search/?page=$page&sort=7");
    return $response;
}

function try_fetch_product_info($productId, $tryCount = 3)
{
    $response = null;
    $counter = 0;
    while ($counter < $tryCount) {
        $response = fetch_product_info($productId);
        if (is_array($response) && $response["status"] == 200) {
            break;
        } else if (is_array($response) && $response["status"] == 302) {
            error_log("product redirected");
            break;
        } else {
            $counter++;
            sleep(2);
            continue;
        }
    }
    return $response;
}

function fetch_product_info($productId)
{
    $url = "https://api.digikala.com/v2/product/$productId/";
    error_log("fetch product info url is $url");
    $response = fetch_data_from_api($url);
    return $response;
}

function fetch_data_from_api($url)
{
    $response = wp_remote_get($url);

    // Check if the request was successful
    if (is_wp_error($response)) {
        return 'Error: ' . $response->get_error_message();
    }

    // Get the response body
    $body = wp_remote_retrieve_body($response);

    // Decode JSON if needed
    $data = json_decode($body, true);

    return $data; // or process $data as needed
}


function digikala_most_sale_grabber_activation()
{
    if (!wp_next_scheduled("fetch_data_from_digikala_event")) {
        wp_schedule_event(time(), "every_minute", "fetch_data_from_digikala_event");
    }
}

register_activation_hook(__FILE__, 'digikala_most_sale_grabber_activation');

add_action("fetch_data_from_digikala_event", "fetch_data_from_digikala");


function fetch_data_from_digikala_deactivation()
{
    wp_clear_scheduled_hook("fetch_data_from_digikala_event");
}

register_deactivation_hook(__FILE__, "fetch_data_from_digikala_deactivation");

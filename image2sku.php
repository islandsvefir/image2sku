<?php
/**
 * Plugin Name: Image2SKU
 */

// Enqueue scripts and styles
function image2sku_enqueue_dependencies($hook)
{
    if ('toplevel_page_image2sku' != $hook) {
        return;
    }

    // Enqueue Bootstrap CSS and JS
    wp_enqueue_style('bootstrap-css', 'https://maxcdn.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css');
    wp_enqueue_script('bootstrap-js', 'https://maxcdn.bootstrapcdn.com/bootstrap/4.5.2/js/bootstrap.min.js', array('jquery'), '4.5.2', true);

    // Enqueue custom JS and CSS
    wp_enqueue_script('image2sku-js', plugin_dir_url(__FILE__) . 'js/image2sku.js', array('jquery'), '1.0.0', true);
    wp_enqueue_style('image2sku-css', plugin_dir_url(__FILE__) . 'css/image2sku.css');

    // Localize script
    wp_localize_script('image2sku-js', 'image2sku_vars', array(
        'ajax_url' => admin_url('admin-ajax.php'),
        'nonce' => wp_create_nonce('image2sku_upload_images_nonce'),
    ));
}

add_action('admin_enqueue_scripts', 'image2sku_enqueue_dependencies');

// Create admin page
function image2sku_create_admin_menu()
{
    add_menu_page('Image2SKU', 'Image2SKU', 'manage_options', 'image2sku', 'image2sku_admin_page');
}

add_action('admin_menu', 'image2sku_create_admin_menu');

// Admin page content
function image2sku_admin_page()
{
    ?>
    <div class="wrap">
        <h1>Image2SKU</h1>
        <form id="image2sku-form" enctype="multipart/form-data" class="mb-4">
            <input type="file" name="images[]" id="image2sku-file-input" multiple accept="image/*"
                   style="display:none;">
            <div id="image2sku-drag-drop" class="image2sku-drag-drop border border-primary p-3 rounded text-center">
                <p class="mb-0">Drag and drop images here, or click to select files.</p>
            </div>
            <div id="image2sku-previews" class="image2sku-previews my-4"></div>
            <progress id="image2sku-progress" value="0" max="100" class="w-100"></progress>
            <button type="submit" class="btn btn-primary mt-3">Upload Images</button>
        </form>
        <div id="image2sku-results" class="image2sku-results"></div>
        <button id="image2sku-download-report" class="btn btn-secondary" style="display:none;">Download CSV Report
        </button>
    </div>
    <?php
}

// Process image upload function (implement according to your requirements)
function process_image_upload($uploaded_images, $index, $product_id, $additional_image = false)
{
    $filename = $uploaded_images['name'][$index];
    $filetmp = $uploaded_images['tmp_name'][$index];
    $filetype = wp_check_filetype(basename($filename), null);

    // Upload the image to the WordPress media library
    $upload = wp_upload_bits($filename, null, file_get_contents($filetmp));
    if ($upload['error']) {
        return false;
    }

    // Create an attachment for the uploaded image
    $attachment = array(
        'post_mime_type' => $filetype['type'],
        'post_title' => preg_replace('/\.[^.]+$/', '', $filename),
        'post_content' => '',
        'post_status' => 'inherit',
    );

    $attach_id = wp_insert_attachment($attachment, $upload['file'], $product_id);
    if (!$attach_id) {
        return false;
    }

    // Include the image.php file for the wp_generate_attachment_metadata() function
    require_once(ABSPATH . 'wp-admin/includes/image.php');

    // Generate metadata and update the attachment
    $attach_data = wp_generate_attachment_metadata($attach_id, $upload['file']);
    wp_update_attachment_metadata($attach_id, $attach_data);

    // Get the current product's featured image
    $featured_image_id = get_post_thumbnail_id($product_id);

    if (!$featured_image_id) {
        // Set the uploaded image as the featured image if the product doesn't have one
        set_post_thumbnail($product_id, $attach_id);
    }
    if ($additional_image) {
        // Add the uploaded image as an additional image if the product already has a featured image
        $product_gallery = get_post_meta($product_id, '_product_image_gallery', true);
        $product_gallery = !empty($product_gallery) ? $product_gallery . ',' . $attach_id : $attach_id;
        update_post_meta($product_id, '_product_image_gallery', $product_gallery);
    }

    return $attach_id;
}


// AJAX
function image2sku_upload_images_callback()
{
    check_ajax_referer('image2sku_upload_images_nonce', 'security');

    $uploaded_images = isset($_FILES['images']) ? $_FILES['images'] : null;

    // Placeholder results array
    $results = array();

    if ($uploaded_images) {
        // Loop through each uploaded image
        for ($i = 0; $i < count($uploaded_images['name']); $i++) {
            // Perform server-side error checking and processing here
            $filename = $uploaded_images['name'][$i];
            $image_sku = pathinfo($filename, PATHINFO_FILENAME); // Get SKU from image filename (without extension)
            $additional_image = false;
            $product = wc_get_product_id_by_sku($image_sku);
            $pattern = '/(-)?\d+$/';
            // Replace the matched part (if any) with an empty string
            $nameWithoutIncrement = preg_replace($pattern, '', $image_sku);// Find a product with the same SKU as the image

            if (!$product) {
                $product = wc_get_product_id_by_sku($nameWithoutIncrement);
                $additional_image = true;
            }
            if ($product) {

                // Process image, upload to the media library, and set as featured or additional image
                $image_id = process_image_upload($uploaded_images, $i, $product, $additional_image);
                $product = wc_get_product($product);
                if ($image_id) {
                    $results[] = array(
                        'name' => $product->get_name(),
                        'image' => $product->get_image(),
                        'filename' => $filename,
                        'status' => 'success',
                        'message' =>   $additional_image ? 'Image set as ' .' additional ' : 'Image set as ' .'featured',
                        'link' => $product->get_permalink(),

                    );

                } else {
                    $results[] = array(
                        'filename' => $filename,
                        'status' => 'error',
                        'message' => 'An error occurred while processing the ' . 'image as ' .  $additional_image ? 'additional ' : 'featured',
                    );
                }
            } else {
                $results[] = array(
                    'filename' => $filename,
                    'status' => 'invalid',
                    'message' => 'No product found with the provided SKU: ',
                );
            }

        }
    } else {
        wp_send_json_error('No images were uploaded.');
        return;
    }
    wp_send_json_success($results);

}

add_action('wp_ajax_image2sku_upload_images', 'image2sku_upload_images_callback');

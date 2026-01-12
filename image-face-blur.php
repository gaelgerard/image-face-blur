<?php
/**
 * Plugin Name: Image Face Blur
 * Description: Floutage définitif de zones circulaires sur les images depuis la médiathèque.
 * Version: 1.0.0
 * Author: Gaël Gérard
 */

if (!defined('ABSPATH')) exit;

class Image_Face_Blur {

    public function __construct() {
        add_action('admin_enqueue_scripts', [$this, 'enqueue']);
        add_filter('media_row_actions', [$this, 'add_button'], 10, 2);
        add_action('wp_ajax_ifb_blur_image', [$this, 'blur_image']);
    }

    public function enqueue() {
        wp_enqueue_script(
            'ifb-admin',
            plugin_dir_url(__FILE__) . 'admin.js',
            ['jquery', 'jquery-ui-draggable', 'jquery-ui-resizable'],
            '1.0',
            true
        );

        wp_enqueue_style(
            'ifb-admin',
            plugin_dir_url(__FILE__) . 'admin.css'
        );
        
        // Add jQuery UI CSS for resizable handles if needed, or custom style them
        wp_enqueue_style('jquery-ui-style', 'https://code.jquery.com/ui/1.13.2/themes/base/jquery-ui.css');

        wp_localize_script('ifb-admin', 'IFB', [
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('ifb_nonce'),
        ]);
    }

    public function add_button($actions, $post) {
        if ($post->post_type === 'attachment' && wp_attachment_is_image($post->ID)) {
            $url = wp_get_attachment_url($post->ID);
            $actions['ifb_blur'] = '<a href="#" class="ifb-blur-btn" data-id="' . $post->ID . '" data-url="' . esc_attr($url) . '">Flouter l’image</a>';
        }
        return $actions;
    }

    public function blur_image() {
        check_ajax_referer('ifb_nonce');

        $id = intval($_POST['id']);
        $circles = $_POST['circles']; // Array of {x, y, r}

        $path = get_attached_file($id);
        if (!file_exists($path)) wp_send_json_error('Image introuvable');

        // Increase memory limit for image processing
        @ini_set('memory_limit', '512M');

        $img = imagecreatefromstring(file_get_contents($path));
        if (!$img) wp_send_json_error('Impossible de charger l\'image');

        foreach ($circles as $c) {
            $this->blur_circle($img, floatval($c['x']), floatval($c['y']), floatval($c['r']));
        }

        // Save back to original path
        $info = getimagesize($path);
        switch ($info['mime']) {
            case 'image/jpeg':
                imagejpeg($img, $path, 90);
                break;
            case 'image/png':
                imagepng($img, $path, 9);
                break;
            case 'image/gif':
                imagegif($img, $path);
                break;
            case 'image/webp':
                imagewebp($img, $path, 90);
                break;
        }
        
        imagedestroy($img);

        // Regenerate thumbnails
        require_once ABSPATH . 'wp-admin/includes/image.php';
        $metadata = wp_generate_attachment_metadata($id, $path);
        wp_update_attachment_metadata($id, $metadata);

        wp_send_json_success();
    }

    private function blur_circle(&$img, $cx, $cy, $radius) {
        $w = imagesx($img);
        $h = imagesy($img);

        // Create a separate image for the blur effect
        // We crop the area to blur + margin to avoid black borders when blurring simple crop
        // But simplified approach: Copy the circle area, blur it, mask it, copy back.
        // Actually, PHP GD is slow. The previous approach of blurring the WHOLE image 8 times repeatedly 
        // and then masking was extremely inefficient for large images.
        // Optimization: Crop the bounding box of the circle, blur THAT, then apply with circular mask.
        
        $box_x = max(0, $cx - $radius);
        $box_y = max(0, $cy - $radius);
        $box_w = min($w - $box_x, $radius * 2);
        $box_h = min($h - $box_y, $radius * 2);

        // If circle is completely out
        if ($box_w <= 0 || $box_h <= 0) return;

        $param_w = $box_w; 
        $param_h = $box_h;
        
        // Extract the patch
        $patch = imagecreatetruecolor($param_w, $param_h);
        imagecopy($patch, $img, 0, 0, $box_x, $box_y, $param_w, $param_h);

        // Heavy blur on patch
        for ($i = 0; $i < 30; $i++) {
            imagefilter($patch, IMG_FILTER_GAUSSIAN_BLUR);
        }

        // Create circular mask for the patch
        $mask = imagecreatetruecolor($param_w, $param_h);
        imagesavealpha($mask, true);
        imagefill($mask, 0, 0, imagecolorallocatealpha($mask, 0, 0, 0, 127)); // Transparent
        
        $black = imagecolorallocate($mask, 0, 0, 0);
        
        // Coordinates in the patch (relative to 0,0)
        // Center of circle relative to patch
        $patch_cx = $cx - $box_x;
        $patch_cy = $cy - $box_y;
        
        imagefilledellipse($mask, $patch_cx, $patch_cy, $radius * 2, $radius * 2, $black);

        // Merge blurred patch onto original using the mask
        // imagecopymerge doesn't support alpha channel masks well in all PHP versions effectively for this compositing
        // We use a manual pixel approach or imagecopy with a workaround if needed.
        // But actually, let's keep it simple for stability:
        // Use the previous approach but optimized?? No, previous approach blurred WHOLE image.
        
        // Let's go with a simpler robust method:
        // 1. Copy original region to $blurred
        // 2. Blur $blurred
        // 3. Loop pixels in bounding box, if distance < radius, copy pixel from $blurred to $img
        
        $blurred = imagecreatetruecolor($param_w, $param_h);
        imagecopy($blurred, $img, 0, 0, $box_x, $box_y, $param_w, $param_h);
        
        // Blur more times for stronger effect
        for ($i = 0; $i < 40; $i++) { 
             imagefilter($blurred, IMG_FILTER_GAUSSIAN_BLUR);
        }

        for ($y = 0; $y < $param_h; $y++) {
            for ($x = 0; $x < $param_w; $x++) {
                // Global coords
                $gx = $box_x + $x;
                $gy = $box_y + $y;
                
                // Distance to center
                $dist = sqrt(pow($gx - $cx, 2) + pow($gy - $cy, 2));
                
                if ($dist <= $radius) {
                    $rgb = imagecolorat($blurred, $x, $y);
                    imagesetpixel($img, $gx, $gy, $rgb);
                }
            }
        }

        imagedestroy($patch);
        imagedestroy($mask);
        imagedestroy($blurred);
    }
}

new Image_Face_Blur();

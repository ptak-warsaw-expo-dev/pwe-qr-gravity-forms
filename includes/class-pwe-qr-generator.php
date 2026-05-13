<?php

if (!defined('ABSPATH')) {
    exit;
}

class PWE_QR_Generator {

    // Cache QR values per request (avoid regenerating)
    private $runtime_cache = [];

    /**
     * Generate a unique QR code value based on domain, form ID, entry ID, and an optional random part.
     *
     * @param int    $form_id The ID of the form.
     * @param int    $entry_id The ID of the entry (optional).
     * @param string $random An optional random string to ensure uniqueness.
     *
     * @return string The generated QR code value.
     */
    public function generate_label($form_id, $entry_id = 0, $random = '') {
        // Get domain and clean it
        $domain = $_SERVER['HTTP_HOST'] ?? do_shortcode('[trade_fair_domainadress]');
        $clean = preg_replace('/[^a-z]/i', '', $domain);

        // Build prefix from domain
        $prefix = strtoupper(substr($clean, 0, 4));

        // Format form ID (3 digits)
        $form_part = str_pad(absint($form_id), 3, '0', STR_PAD_LEFT);

        $entry_id = absint($entry_id);

        // Generate random part if not provided
        if (empty($random)) {
            $random = 'RND' . wp_rand(10000, 99999);
        }

        // Final QR value
        return $prefix . $form_part . $entry_id . $random . $entry_id;
    }

    /**
     * Generate a PNG image of a QR code with optional label and logo.
     *
     * @param string $value The value to encode in the QR code.
     * @param string $label An optional label to display under the QR code.
     * @param int    $size The size of the QR code in pixels (default 200).
     * @param string $logo_url An optional URL or path to a logo image to overlay on the center of the QR code.
     *
     * @return string A PNG image data as a binary string, or an empty string on failure.
     */
    public function generate_png($value, $label, $size = 200, $logo_url = '') {
        require_once plugin_dir_path(__FILE__) . '../phpqrcode/qrlib.php';

        $label = trim((string) $label);
        $has_label = ($label !== '');
        $size = absint($size) ?: 200;

        // Generate raw QR with high error correction (H = 30%)
        ob_start();
        \QRcode::png($value, null, QR_ECLEVEL_H, 10, 0);
        $qr_raw = ob_get_clean();

        $qr = imagecreatefromstring($qr_raw);

        if (!$qr) {
            return '';
        }

        // Resize QR
        $qr_resized = imagecreatetruecolor($size, $size);
        $white = imagecolorallocate($qr_resized, 255, 255, 255);
        imagefill($qr_resized, 0, 0, $white);

        imagecopyresampled(
            $qr_resized,
            $qr,
            0, 0, 0, 0,
            $size, $size,
            imagesx($qr),
            imagesy($qr)
        );

        imagedestroy($qr);

        // Final canvas
        $padding = 10;
        $label_space = $has_label ? 35 : 0;

        $final_w = $size + ($padding * 2);
        $final_h = $size + ($padding * 2) + $label_space;

        $img = imagecreatetruecolor($final_w, $final_h);

        $white = imagecolorallocate($img, 255, 255, 255);
        $black = imagecolorallocate($img, 0, 0, 0);

        imagefill($img, 0, 0, $white);

        // Draw QR
        imagecopy($img, $qr_resized, $padding, $padding, 0, 0, $size, $size);
        imagedestroy($qr_resized);

        // Draw logo on center if provided
        if (!empty($logo_url)) {
            $this->overlay_logo_on_center($img, $logo_url, $padding, $size);
        }

        // Draw label under QR
        if ($has_label) {
            $font_file = plugin_dir_path(__FILE__) . '../assets/fonts/DejaVuSans.ttf';

            if (file_exists($font_file)) {
                $font_size = 14;
                $bbox = imagettfbbox($font_size, 0, $font_file, $label);
                $label_w = abs($bbox[2] - $bbox[0]);

                $text_x = ($final_w - $label_w) / 2;
                $text_y = $padding + $size + 25;

                imagettftext($img, $font_size, 0, $text_x, $text_y, $black, $font_file, $label);
            } else {
                $label_w = imagefontwidth(3) * strlen($label);
                $text_x = ($final_w - $label_w) / 2;

                imagestring($img, 3, $text_x, $padding + $size + 10, $label, $black);
            }
        }

        ob_start();
        imagepng($img);
        $png = ob_get_clean();

        imagedestroy($img);

        return $png ?: '';
    }

    /**
     * Overlay a logo image on the center of the QR code.
     *
     * @param resource $img The QR code image resource to modify.
     * @param string   $logo_url The URL or path to the logo image.
     * @param int      $qr_padding The padding around the QR code (used for positioning).
     * @param int      $qr_size The size of the QR code (used for positioning).
     */
    private function overlay_logo_on_center(&$img, $logo_url, $qr_padding, $qr_size) {
        // Resolve logo path - support relative paths
        if (strpos($logo_url, '/') === 0) {
            // Absolute path from root
            $logo_path = ABSPATH . ltrim($logo_url, '/');
        } else {
            // Relative path
            $logo_path = $logo_url;
        }

        // Check if file exists and is readable
        if (!file_exists($logo_path) || !is_readable($logo_path)) {
            return;
        }

        // Load logo image - support webp, png, jpg
        $ext = strtolower(pathinfo($logo_path, PATHINFO_EXTENSION));
        
        switch ($ext) {
            case 'webp':
                if (function_exists('imagecreatefromwebp')) {
                    $logo = imagecreatefromwebp($logo_path);
                } else {
                    return; // WebP not supported
                }
                break;
            case 'png':
                $logo = imagecreatefrompng($logo_path);
                break;
            case 'jpg':
            case 'jpeg':
                $logo = imagecreatefromjpeg($logo_path);
                break;
            default:
                return; // Unsupported format
        }

        if (!$logo) {
            return;
        }

        // Calculate logo size - 15% of QR size (to preserve scanability)
        $logo_size = intval($qr_size * 0.15);
        $logo_w = imagesx($logo);
        $logo_h = imagesy($logo);

        // Resize logo to fit
        $logo_resized = imagecreatetruecolor($logo_size, $logo_size);
        $white = imagecolorallocate($logo_resized, 255, 255, 255);
        imagefill($logo_resized, 0, 0, $white);
        imagealphablending($logo_resized, true);
        imagesavealpha($logo_resized, true);

        imagecopyresampled(
            $logo_resized,
            $logo,
            0, 0, 0, 0,
            $logo_size, $logo_size,
            $logo_w, $logo_h
        );

        imagedestroy($logo);

        // Calculate center position
        $center_x = $qr_padding + intval($qr_size / 2) - intval($logo_size / 2);
        $center_y = $qr_padding + intval($qr_size / 2) - intval($logo_size / 2);

        // Create white background for logo (for better visibility)
        $white = imagecolorallocate($img, 255, 255, 255);
        $light_gray = imagecolorallocate($img, 220, 220, 220);
        
        $box_x1 = $center_x - 5;
        $box_y1 = $center_y - 5;
        $box_x2 = $center_x + $logo_size + 5;
        $box_y2 = $center_y + $logo_size + 5;
        $radius = 6;

        // Draw rounded rectangle background (white)
        $this->draw_rounded_rectangle($img, $box_x1, $box_y1, $box_x2, $box_y2, $radius, $white, true);
        
        // Draw rounded rectangle border (light gray)
        $this->draw_rounded_rectangle($img, $box_x1, $box_y1, $box_x2, $box_y2, $radius, $light_gray, false);

        // Overlay logo on center
        imagecopy(
            $img,
            $logo_resized,
            $center_x,
            $center_y,
            0, 0,
            $logo_size,
            $logo_size
        );

        imagedestroy($logo_resized);
    }

    /**
     * Draw a rounded rectangle on the given image resource.
     *
     * @param resource $img The image resource to draw on.
     * @param int      $x1 The x-coordinate of the top-left corner.
     * @param int      $y1 The y-coordinate of the top-left corner.
     * @param int      $x2 The x-coordinate of the bottom-right corner.
     * @param int      $y2 The y-coordinate of the bottom-right corner.
     * @param int      $radius The radius of the corners.
     * @param int      $color The color to use for drawing (allocated color).
     * @param bool     $filled Whether to draw a filled rectangle (true) or just an outline (false).
     */
    private function draw_rounded_rectangle(&$img, $x1, $y1, $x2, $y2, $radius, $color, $filled = true) {
        if ($filled) {
            // Draw filled rounded rectangle
            imagefilledrectangle($img, $x1 + $radius, $y1, $x2 - $radius, $y2, $color);
            imagefilledrectangle($img, $x1, $y1 + $radius, $x2, $y2 - $radius, $color);
            
            // Draw corner arcs
            imagefilledarc($img, $x1 + $radius, $y1 + $radius, $radius * 2, $radius * 2, 180, 270, $color, IMG_ARC_PIE);
            imagefilledarc($img, $x2 - $radius, $y1 + $radius, $radius * 2, $radius * 2, 270, 360, $color, IMG_ARC_PIE);
            imagefilledarc($img, $x2 - $radius, $y2 - $radius, $radius * 2, $radius * 2, 0, 90, $color, IMG_ARC_PIE);
            imagefilledarc($img, $x1 + $radius, $y2 - $radius, $radius * 2, $radius * 2, 90, 180, $color, IMG_ARC_PIE);
        } else {
            // Draw rounded rectangle border (outline only)
            $thickness = 1;
            imageline($img, $x1 + $radius, $y1, $x2 - $radius, $y1, $color);
            imageline($img, $x2, $y1 + $radius, $x2, $y2 - $radius, $color);
            imageline($img, $x2 - $radius, $y2, $x1 + $radius, $y2, $color);
            imageline($img, $x1, $y2 - $radius, $x1, $y1 + $radius, $color);
            
            // Draw corner arcs for border
            imagearc($img, $x1 + $radius, $y1 + $radius, $radius * 2, $radius * 2, 180, 270, $color);
            imagearc($img, $x2 - $radius, $y1 + $radius, $radius * 2, $radius * 2, 270, 360, $color);
            imagearc($img, $x2 - $radius, $y2 - $radius, $radius * 2, $radius * 2, 0, 90, $color);
            imagearc($img, $x1 + $radius, $y2 - $radius, $radius * 2, $radius * 2, 90, 180, $color);
        }
    }

    /**
     * Get QR code data for a specific feed name, form ID, and entry.
     *
     * @param string $name The name of the QR feed to look up.
     * @param int    $form_id The ID of the form.
     * @param array  $entry The entry data (optional).
     * @param int    $size The desired size of the QR code (optional).
     *
     * @return array|null An array with 'value', 'label', 'size', and 'logo_url' keys, or null if not found.
     */
    public function get_qr_data_for_feed($name, $form_id, $entry = [], $size = 200) {
        if (!class_exists('GFAPI')) {
            return null;
        }

        // Load feeds for this form
        $feeds = GFAPI::get_feeds(null, $form_id, 'pwe_qr');

        if (!$feeds) {
            return null;
        }

        foreach ($feeds as $feed) {
            $meta = $feed['meta'] ?? [];

            // Support both new and old field names
            $feed_name = $meta['feedName'] ?? $meta['qr_name'] ?? '';

            if ($feed_name !== $name) {
                continue;
            }

            // Check if feed is active
            if (empty($feed['is_active'])) {
                continue;
            }

            $entry_id = absint($entry['id'] ?? 0);
            $cache_key = $form_id . '|' . $entry_id . '|' . $name;

            // Build QR value once per request
            if (!isset($this->runtime_cache[$cache_key])) {
                $random = '';

                // Try to reuse stored random part
                if (
                    !empty($meta['qrcodeFields'][1]['custom_key']) &&
                    is_string($meta['qrcodeFields'][1]['custom_key'])
                ) {
                    $random = $meta['qrcodeFields'][1]['custom_key'];
                }

                $this->runtime_cache[$cache_key] =
                    $this->generate_label($form_id, $entry_id, $random);
            }

            // Label (fallback to old key)
            $label = $meta['qrcodeLabel'] ?? $meta['qr_label'] ?? '';

            // Size (fallback to old key)
            $final_size = !empty($meta['qrcodeSize'])
                ? absint($meta['qrcodeSize'])
                : (!empty($meta['qr_size']) ? absint($meta['qr_size']) : absint($size));

            // Logo URL
            $logo_url = $meta['logoUrl'] ?? '';

            return [
                'value'    => $this->runtime_cache[$cache_key],
                'label'    => $label,
                'size'     => $final_size ?: 200,
                'logo_url' => $logo_url,
            ];
        }

        return null;
    }
}
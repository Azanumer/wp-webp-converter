<?php
/**
 * Plugin Name:       WP WebP Converter
 * Plugin URI:        https://github.com/Azanumer/wp-webp-converter
 * Description:       Converts JPEG and PNG uploads to WebP on upload, and bulk-converts your existing Media Library in batches. Smaller files, same quality, no external service.
 * Version:           1.0.0
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * Author:            Azan Umer
 * Author URI:        https://github.com/Azanumer
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       wp-webp-converter
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'WWC_VERSION', '1.0.0' );
define( 'WWC_OPTION', 'wwc_settings' );

/**
 * Default settings.
 *
 * @return array
 */
function wwc_default_settings() {
    return array(
        'enabled'       => true,
        'quality'       => 82,    // 1-100, WebP quality.
        'keep_original' => false, // true = keep the JPEG/PNG next to the WebP and serve WebP via filter.
        'convert_on_upload' => true,
    );
}

/**
 * Get a setting value.
 *
 * @param string $key Setting key.
 * @return mixed
 */
function wwc_get_setting( $key ) {
    $settings = get_option( WWC_OPTION, array() );
    $defaults = wwc_default_settings();
    if ( isset( $settings[ $key ] ) ) {
        return $settings[ $key ];
    }
    return isset( $defaults[ $key ] ) ? $defaults[ $key ] : null;
}

/**
 * Does this server support writing WebP at all?
 *
 * @return bool
 */
function wwc_webp_supported() {
    if ( ! class_exists( 'WP_Image_Editor' ) ) {
        return false;
    }
    return WP_Image_Editor::supports_mime_type( 'image/webp' );
}

/**
 * Convert one image file to WebP next to it.
 *
 * @param string $path    Absolute path of the source JPEG/PNG.
 * @param int    $quality WebP quality 1-100.
 * @return string|WP_Error Absolute path of the WebP file, or WP_Error.
 */
function wwc_convert_file( $path, $quality ) {
    $editor = wp_get_image_editor( $path );
    if ( is_wp_error( $editor ) ) {
        return $editor;
    }
    if ( ! $editor->supports_mime_type( 'image/webp' ) ) {
        return new WP_Error( 'wwc_no_webp', __( 'This server cannot write WebP images.', 'wp-webp-converter' ) );
    }
    $info     = pathinfo( $path );
    $webp_path = $info['dirname'] . '/' . $info['filename'] . '.webp';
    if ( $webp_path === $path ) {
        return new WP_Error( 'wwc_already_webp', __( 'File is already WebP.', 'wp-webp-converter' ) );
    }
    $saved = $editor->save( $webp_path, 'image/webp' );
    if ( is_wp_error( $saved ) ) {
        return $saved;
    }
    return $webp_path;
}

/**
 * Convert a fresh upload to WebP.
 *
 * Runs on wp_handle_upload so WordPress then generates all thumbnails
 * from the WebP file. When "keep original" is on, the original JPEG/PNG
 * is kept and the WebP is served instead via the URL filter below.
 *
 * @param array $upload Upload array (file, url, type).
 * @return array
 */
function wwc_convert_upload( $upload ) {
    if ( ! wwc_get_setting( 'enabled' ) || ! wwc_get_setting( 'convert_on_upload' ) ) {
        return $upload;
    }
    if ( ! isset( $upload['file'], $upload['type'] ) ) {
        return $upload;
    }
    if ( ! in_array( $upload['type'], array( 'image/jpeg', 'image/png' ), true ) ) {
        return $upload;
    }
    if ( ! (bool) apply_filters( 'wwc_convert_on_upload', true, $upload ) ) {
        return $upload;
    }
    $quality = (int) apply_filters( 'wwc_quality', (int) wwc_get_setting( 'quality' ) );
    $quality = max( 1, min( 100, $quality ) );

    $webp_path = wwc_convert_file( $upload['file'], $quality );
    if ( is_wp_error( $webp_path ) ) {
        return $upload; // Leave the original untouched on failure.
    }
    $webp_url = str_replace( wp_basename( $upload['file'] ), wp_basename( $webp_path ), $upload['url'] );

    if ( wwc_get_setting( 'keep_original' ) ) {
        // Keep the original file; record the WebP path on the attachment
        // once it exists (see wwc_store_webp_path) and serve it via filter.
        update_option( 'wwc_pending_' . md5( $upload['file'] ), $webp_path );
        return $upload;
    }

    // Replace the upload with the WebP version and delete the original.
    @unlink( $upload['file'] );
    $upload['file'] = $webp_path;
    $upload['url']  = $webp_url;
    $upload['type'] = 'image/webp';
    return $upload;
}
add_filter( 'wp_handle_upload', 'wwc_convert_upload' );

/**
 * When "keep original" is on, remember the WebP sibling path on the
 * attachment so the URL filter below can serve it.
 *
 * @param int $attachment_id Attachment ID.
 */
function wwc_store_webp_path( $attachment_id ) {
    $file = get_attached_file( $attachment_id );
    if ( ! $file ) {
        return;
    }
    $pending = get_option( 'wwc_pending_' . md5( $file ), false );
    if ( false !== $pending ) {
        delete_option( 'wwc_pending_' . md5( $file ) );
        if ( file_exists( $pending ) ) {
            update_post_meta( $attachment_id, '_wwc_webp_file', $pending );
        }
        return;
    }
    // Bulk conversions store the path directly; nothing to do here.
}
add_action( 'add_attachment', 'wwc_store_webp_path' );

/**
 * Serve the WebP version of an attachment URL when "keep original" is on
 * and the WebP sibling exists.
 *
 * @param string $url           Attachment URL.
 * @param int    $attachment_id Attachment ID.
 * @return string
 */
function wwc_maybe_serve_webp( $url, $attachment_id ) {
    if ( ! wwc_get_setting( 'enabled' ) || ! wwc_get_setting( 'keep_original' ) ) {
        return $url;
    }
    $webp = get_post_meta( $attachment_id, '_wwc_webp_file', true );
    if ( ! $webp || ! file_exists( $webp ) ) {
        return $url;
    }
    $upload_dir = wp_upload_dir();
    $rel        = str_replace( $upload_dir['basedir'] . '/', '', $webp );
    return $upload_dir['baseurl'] . '/' . $rel;
}
add_filter( 'wp_get_attachment_url', 'wwc_maybe_serve_webp', 10, 2 );

/**
 * Count convertible images in the Media Library (JPEG/PNG attachments
 * whose full-size file is not yet WebP).
 *
 * @return int
 */
function wwc_count_convertible() {
    global $wpdb;
    $count = $wpdb->get_var(
        "SELECT COUNT(*) FROM {$wpdb->posts}
         WHERE post_type = 'attachment'
         AND post_mime_type IN ('image/jpeg', 'image/png')"
    );
    return (int) $count;
}

/**
 * Bulk-convert a batch of attachments to WebP.
 *
 * @param int $batch How many attachments to process.
 * @return array {converted, skipped, failed, done}
 */
function wwc_bulk_convert( $batch = 20 ) {
    $quality = max( 1, min( 100, (int) apply_filters( 'wwc_quality', (int) wwc_get_setting( 'quality' ) ) ) );
    $query   = new WP_Query( array(
        'post_type'      => 'attachment',
        'post_mime_type' => array( 'image/jpeg', 'image/png' ),
        'post_status'    => 'inherit',
        'posts_per_page' => $batch,
        'fields'         => 'ids',
        'orderby'        => 'ID',
        'order'          => 'ASC',
        'meta_query'     => array(
            array(
                'key'     => '_wwc_converted',
                'compare' => 'NOT EXISTS',
            ),
        ),
    ) );

    $result = array( 'converted' => 0, 'skipped' => 0, 'failed' => 0, 'done' => false );
    if ( empty( $query->posts ) ) {
        $result['done'] = true;
        return $result;
    }

    foreach ( $query->posts as $attachment_id ) {
        $file = get_attached_file( $attachment_id );
        if ( ! $file || ! file_exists( $file ) ) {
            update_post_meta( $attachment_id, '_wwc_converted', 'failed' );
            $result['failed']++;
            continue;
        }
        $webp_path = wwc_convert_file( $file, $quality );
        if ( is_wp_error( $webp_path ) ) {
            update_post_meta( $attachment_id, '_wwc_converted', 'failed' );
            $result['failed']++;
            continue;
        }
        update_post_meta( $attachment_id, '_wwc_webp_file', $webp_path );
        update_post_meta( $attachment_id, '_wwc_converted', 'yes' );
        $result['converted']++;

        if ( ! wwc_get_setting( 'keep_original' ) ) {
            // Regenerate thumbnails from the WebP file and point the
            // attachment at it, then delete the original.
            require_once ABSPATH . 'wp-admin/includes/image.php';
            $new_meta = wp_generate_attachment_metadata( $attachment_id, $webp_path );
            if ( ! is_wp_error( $new_meta ) && ! empty( $new_meta ) ) {
                wp_update_attachment_metadata( $attachment_id, $new_meta );
                update_attached_file( $attachment_id, $webp_path );
                global $wpdb;
                $wpdb->update(
                    $wpdb->posts,
                    array( 'post_mime_type' => 'image/webp' ),
                    array( 'ID' => $attachment_id ),
                    array( '%s' ),
                    array( '%d' )
                );
                @unlink( $file );
            }
        }
    }

    // Are there more left?
    $remaining = new WP_Query( array(
        'post_type'      => 'attachment',
        'post_mime_type' => array( 'image/jpeg', 'image/png' ),
        'post_status'    => 'inherit',
        'posts_per_page' => 1,
        'fields'         => 'ids',
        'meta_query'     => array(
            array(
                'key'     => '_wwc_converted',
                'compare' => 'NOT EXISTS',
            ),
        ),
    ) );
    $result['done'] = empty( $remaining->posts );
    return $result;
}

/**
 * Tools page: bulk conversion UI with auto-continue batches.
 */
function wwc_tools_menu() {
    add_media_page(
        __( 'WebP Converter', 'wp-webp-converter' ),
        __( 'WebP Converter', 'wp-webp-converter' ),
        'upload_files',
        'wp-webp-converter',
        'wwc_tools_page'
    );
}
add_action( 'admin_menu', 'wwc_tools_menu' );

/**
 * Render the Tools page.
 */
function wwc_tools_page() {
    $ran = null;
    if ( isset( $_GET['wwc_convert'] ) && '1' === $_GET['wwc_convert'] ) {
        if ( ! current_user_can( 'upload_files' ) ) {
            wp_die( esc_html__( 'You do not have permission to do that.', 'wp-webp-converter' ) );
        }
        check_admin_referer( 'wwc_bulk_convert' );
        $ran = wwc_bulk_convert( 20 );
    }
    $convertible = wwc_count_convertible();
    ?>
    <div class="wrap">
        <h1><?php esc_html_e( 'WebP Converter', 'wp-webp-converter' ); ?></h1>
        <?php if ( ! wwc_webp_supported() ) : ?>
            <div class="notice notice-error"><p><?php esc_html_e( 'This server cannot write WebP images (no GD/Imagick WebP support). Conversion is disabled.', 'wp-webp-converter' ); ?></p></div>
        <?php endif; ?>
        <?php if ( $ran ) : ?>
            <div class="notice notice-success"><p>
                <?php
                printf(
                    /* translators: 1: converted, 2: failed */
                    esc_html__( 'Batch done: %1$d converted, %2$d failed.', 'wp-webp-converter' ),
                    (int) $ran['converted'],
                    (int) $ran['failed']
                );
                ?>
            </p></div>
            <?php if ( ! $ran['done'] ) : ?>
                <p><?php esc_html_e( 'More images remain — the next batch starts automatically.', 'wp-webp-converter' ); ?></p>
                <meta http-equiv="refresh" content="2;url=<?php echo esc_url( add_query_arg( array( 'wwc_convert' => '1', '_wpnonce' => wp_create_nonce( 'wwc_bulk_convert' ) ) ) ); ?>">
            <?php else : ?>
                <p><strong><?php esc_html_e( 'All convertible images are now WebP.', 'wp-webp-converter' ); ?></strong></p>
            <?php endif; ?>
        <?php endif; ?>
        <p><?php esc_html_e( 'WebP images are typically 25–35% smaller than the same JPEG/PNG at equal quality, which means faster pages with no visible difference.', 'wp-webp-converter' ); ?></p>
        <p>
            <?php
            printf(
                /* translators: %d: number of images */
                esc_html__( '%d JPEG/PNG attachments are waiting to be converted.', 'wp-webp-converter' ),
                (int) $convertible
            );
            ?>
        </p>
        <p>
            <a class="button button-primary" href="<?php echo esc_url( wp_nonce_url( add_query_arg( 'wwc_convert', '1' ), 'wwc_bulk_convert' ) ); ?>">
                <?php esc_html_e( 'Convert all (20 per batch)', 'wp-webp-converter' ); ?>
            </a>
        </p>
        <p class="description">
            <?php esc_html_e( 'New uploads are converted automatically when "Convert on upload" is enabled in Settings. With "Keep original" on, the JPEG/PNG stays on disk and the WebP is served instead; with it off, the original is replaced.', 'wp-webp-converter' ); ?>
        </p>
    </div>
    <?php
}

/**
 * Settings page under Settings menu.
 */
function wwc_settings_menu() {
    add_options_page(
        __( 'WebP Converter', 'wp-webp-converter' ),
        __( 'WebP Converter', 'wp-webp-converter' ),
        'manage_options',
        'wp-webp-converter',
        'wwc_settings_page'
    );
}
add_action( 'admin_menu', 'wwc_settings_menu' );

/**
 * Register settings.
 */
function wwc_admin_init() {
    register_setting( 'wwc_settings_group', WWC_OPTION, array( 'sanitize_callback' => 'wwc_sanitize_settings' ) );
    add_settings_section( 'wwc_main', __( 'Conversion Settings', 'wp-webp-converter' ), '__return_false', 'wp-webp-converter' );
    add_settings_field( 'wwc_enabled', __( 'Enable conversion', 'wp-webp-converter' ), 'wwc_field_enabled', 'wp-webp-converter', 'wwc_main' );
    add_settings_field( 'wwc_convert_on_upload', __( 'Convert on upload', 'wp-webp-converter' ), 'wwc_field_convert_on_upload', 'wp-webp-converter', 'wwc_main' );
    add_settings_field( 'wwc_quality', __( 'WebP quality (1–100)', 'wp-webp-converter' ), 'wwc_field_quality', 'wp-webp-converter', 'wwc_main' );
    add_settings_field( 'wwc_keep_original', __( 'Keep original file', 'wp-webp-converter' ), 'wwc_field_keep_original', 'wp-webp-converter', 'wwc_main' );
}
add_action( 'admin_init', 'wwc_admin_init' );

/**
 * Sanitize settings input.
 *
 * @param array $input Raw input.
 * @return array
 */
function wwc_sanitize_settings( $input ) {
    $out                          = wwc_default_settings();
    $out['enabled']               = ! empty( $input['enabled'] );
    $out['convert_on_upload']     = ! empty( $input['convert_on_upload'] );
    $out['keep_original']         = ! empty( $input['keep_original'] );
    $out['quality']               = isset( $input['quality'] ) ? max( 1, min( 100, (int) $input['quality'] ) ) : 82;
    return $out;
}

function wwc_field_enabled() {
    printf(
        '<input type="checkbox" name="%s[enabled]" value="1" %s />',
        esc_attr( WWC_OPTION ),
        checked( wwc_get_setting( 'enabled' ), true, false )
    );
}

function wwc_field_convert_on_upload() {
    printf(
        '<input type="checkbox" name="%s[convert_on_upload]" value="1" %s />',
        esc_attr( WWC_OPTION ),
        checked( wwc_get_setting( 'convert_on_upload' ), true, false )
    );
}

function wwc_field_quality() {
    printf(
        '<input type="number" min="1" max="100" name="%s[quality]" value="%d" class="small-text" />',
        esc_attr( WWC_OPTION ),
        (int) wwc_get_setting( 'quality' )
    );
    echo '<p class="description">' . esc_html__( '82 is a good balance: visibly identical to the original JPEG at ~30% smaller size.', 'wp-webp-converter' ) . '</p>';
}

function wwc_field_keep_original() {
    printf(
        '<input type="checkbox" name="%s[keep_original]" value="1" %s />',
        esc_attr( WWC_OPTION ),
        checked( wwc_get_setting( 'keep_original' ), true, false )
    );
    echo '<p class="description">' . esc_html__( 'Keep the JPEG/PNG on disk as a fallback and serve the WebP instead. Off (default): the original is replaced by the WebP, saving disk space.', 'wp-webp-converter' ) . '</p>';
}

/**
 * Render the settings page.
 */
function wwc_settings_page() {
    ?>
    <div class="wrap">
        <h1><?php esc_html_e( 'WebP Converter', 'wp-webp-converter' ); ?></h1>
        <form method="post" action="options.php">
            <?php
            settings_fields( 'wwc_settings_group' );
            do_settings_sections( 'wp-webp-converter' );
            submit_button();
            ?>
        </form>
    </div>
    <?php
}

<?php
/**
 * Plugin Name: Clawd WordPress API Connector
 * Description: إضافة تتيح الاتصال الخارجي الآمن مع Clawd لتبادل البيانات ونشر المقالات
 * Version: 1.0.0
 * Author: Clawd Assistant
 */

// منع الوصول المباشر للملف
if (!defined('ABSPATH')) {
    exit;
}

// تعريف الإضافة
class Clawd_WP_API_Connector {
    
    private $api_key;
    private $secret_key;
    
    public function __construct() {
        add_action('init', array($this, 'init'));
        add_action('rest_api_init', array($this, 'register_routes'));
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_init', array($this, 'settings_init'));
        
        // تحميل المفاتيح من الإعدادات
        $this->api_key = get_option('clawd_api_key');
        $this->secret_key = get_option('clawd_secret_key');
    }
    
    public function init() {
        // بدء الإضافة
    }
    
    /**
     * تسجيل مسارات REST API
     */
    public function register_routes() {
        register_rest_route('clawd/v1', '/post/create', array(
            'methods' => 'POST',
            'callback' => array($this, 'create_post'),
            'permission_callback' => array($this, 'check_clawd_permission')
        ));
        
        register_rest_route('clawd/v1', '/post/update/(?P<id>\d+)', array(
            'methods' => 'POST',
            'callback' => array($this, 'update_post'),
            'permission_callback' => array($this, 'check_clawd_permission')
        ));
        
        register_rest_route('clawd/v1', '/post/delete/(?P<id>\d+)', array(
            'methods' => 'DELETE',
            'callback' => array($this, 'delete_post'),
            'permission_callback' => array($this, 'check_clawd_permission')
        ));
        
        register_rest_route('clawd/v1', '/posts', array(
            'methods' => 'GET',
            'callback' => array($this, 'get_posts'),
            'permission_callback' => array($this, 'check_clawd_permission')
        ));
        
        register_rest_route('clawd/v1', '/categories', array(
            'methods' => 'GET',
            'callback' => array($this, 'get_categories'),
            'permission_callback' => array($this, 'check_clawd_permission')
        ));
        
        register_rest_route('clawd/v1', '/test-connection', array(
            'methods' => 'GET',
            'callback' => array($this, 'test_connection'),
            'permission_callback' => array($this, 'check_clawd_permission')
        ));
    }
    
    /**
     * التحقق من صلاحيات Clawd
     */
    public function check_clawd_permission($request) {
        $provided_key = $request->get_header('X-Clawd-API-Key');
        $provided_signature = $request->get_header('X-Clawd-Signature');
        
        if (!$provided_key || !$provided_signature) {
            return new WP_Error('missing_auth', 'Missing authentication headers', array('status' => 401));
        }
        
        if ($provided_key !== $this->api_key) {
            return new WP_Error('invalid_key', 'Invalid API key', array('status' => 401));
        }
        
        // التحقق من التوقيع (signature) للتأكد من أن الطلب آمن
        $expected_signature = hash_hmac('sha256', $request->get_body(), $this->secret_key);
        
        if (!hash_equals($expected_signature, $provided_signature)) {
            return new WP_Error('invalid_signature', 'Invalid signature', array('status' => 401));
        }
        
        return true;
    }
    
    /**
     * إنشاء مقالة جديدة
     */
    public function create_post($request) {
        $params = $request->get_params();
        
        $post_data = array(
            'post_title'    => sanitize_text_field($params['title']),
            'post_content'  => wp_kses_post($params['content']),
            'post_status'   => sanitize_text_field($params['status']) ?: 'publish',
            'post_author'   => get_current_user_id(),
            'post_type'     => sanitize_text_field($params['type']) ?: 'post',
            'post_category' => isset($params['category']) ? array($params['category']) : array(),
            'tags_input'    => isset($params['tags']) ? explode(',', $params['tags']) : array()
        );
        
        if (isset($params['excerpt'])) {
            $post_data['post_excerpt'] = sanitize_text_field($params['excerpt']);
        }
        
        $post_id = wp_insert_post($post_data);
        
        if (is_wp_error($post_id)) {
            return new WP_Error('create_failed', 'Failed to create post: ' . $post_id->get_error_message(), array('status' => 500));
        }
        
        // إضافة ميتا فيلدز إذا كانت موجودة
        if (isset($params['meta_fields']) && is_array($params['meta_fields'])) {
            foreach ($params['meta_fields'] as $key => $value) {
                update_post_meta($post_id, $key, $value);
            }
        }
        
        // إضافة الصورة البارزة إذا كانت موجودة
        if (isset($params['featured_image_url'])) {
            $this->set_featured_image($post_id, $params['featured_image_url']);
        }
        
        return array(
            'success' => true,
            'post_id' => $post_id,
            'message' => 'Post created successfully'
        );
    }
    
    /**
     * تحديث مقالة موجودة
     */
    public function update_post($request) {
        $post_id = $request['id'];
        $params = $request->get_params();
        
        $post = get_post($post_id);
        if (!$post) {
            return new WP_Error('post_not_found', 'Post not found', array('status' => 404));
        }
        
        $post_data = array(
            'ID'           => $post_id,
            'post_title'   => sanitize_text_field($params['title']),
            'post_content' => wp_kses_post($params['content']),
            'post_status'  => sanitize_text_field($params['status']) ?: $post->post_status,
            'post_type'    => sanitize_text_field($params['type']) ?: $post->post_type,
            'post_excerpt' => isset($params['excerpt']) ? sanitize_text_field($params['excerpt']) : $post->post_excerpt
        );
        
        $result = wp_update_post($post_data);
        
        if (is_wp_error($result)) {
            return new WP_Error('update_failed', 'Failed to update post: ' . $result->get_error_message(), array('status' => 500));
        }
        
        // تحديث ميتا فيلدز إذا كانت موجودة
        if (isset($params['meta_fields']) && is_array($params['meta_fields'])) {
            foreach ($params['meta_fields'] as $key => $value) {
                update_post_meta($post_id, $key, $value);
            }
        }
        
        // تحديث الصورة البارزة إذا كانت موجودة
        if (isset($params['featured_image_url'])) {
            $this->set_featured_image($post_id, $params['featured_image_url']);
        }
        
        return array(
            'success' => true,
            'post_id' => $post_id,
            'message' => 'Post updated successfully'
        );
    }
    
    /**
     * حذف مقالة
     */
    public function delete_post($request) {
        $post_id = $request['id'];
        
        $post = get_post($post_id);
        if (!$post) {
            return new WP_Error('post_not_found', 'Post not found', array('status' => 404));
        }
        
        $result = wp_delete_post($post_id, true); // true يعني الحذف النهائي
        
        if (!$result) {
            return new WP_Error('delete_failed', 'Failed to delete post', array('status' => 500));
        }
        
        return array(
            'success' => true,
            'message' => 'Post deleted successfully'
        );
    }
    
    /**
     * الحصول على المقالات
     */
    public function get_posts($request) {
        $args = array(
            'numberposts' => $request['per_page'] ?? 10,
            'offset' => $request['offset'] ?? 0,
            'post_type' => $request['post_type'] ?? 'post',
            'post_status' => $request['status'] ?? 'publish'
        );
        
        if (isset($request['category'])) {
            $args['cat'] = $request['category'];
        }
        
        if (isset($request['search'])) {
            $args['s'] = $request['search'];
        }
        
        $posts = get_posts($args);
        $formatted_posts = array();
        
        foreach ($posts as $post) {
            $formatted_posts[] = array(
                'id' => $post->ID,
                'title' => $post->post_title,
                'content' => $post->post_content,
                'excerpt' => $post->post_excerpt,
                'status' => $post->post_status,
                'date' => $post->post_date,
                'author' => get_the_author_meta('display_name', $post->post_author),
                'permalink' => get_permalink($post->ID),
                'featured_image' => get_the_post_thumbnail_url($post->ID, 'full')
            );
        }
        
        return array(
            'posts' => $formatted_posts,
            'total' => count($posts)
        );
    }
    
    /**
     * الحصول على التصنيفات
     */
    public function get_categories($request) {
        $categories = get_categories(array(
            'hide_empty' => false
        ));
        
        $formatted_categories = array();
        
        foreach ($categories as $category) {
            $formatted_categories[] = array(
                'id' => $category->term_id,
                'name' => $category->name,
                'slug' => $category->slug,
                'count' => $category->count
            );
        }
        
        return array('categories' => $formatted_categories);
    }
    
    /**
     * اختبار الاتصال
     */
    public function test_connection($request) {
        return array(
            'connected' => true,
            'timestamp' => current_time('mysql'),
            'site_url' => get_site_url(),
            'wp_version' => get_bloginfo('version'),
            'plugin_version' => '1.0.0'
        );
    }
    
    /**
     * إضافة صورة مميزة للمقالة
     */
    private function set_featured_image($post_id, $image_url) {
        require_once(ABSPATH . 'wp-admin/includes/media.php');
        require_once(ABSPATH . 'wp-admin/includes/file.php');
        require_once(ABSPATH . 'wp-admin/includes/image.php');
        
        // تحميل الصورة من الرابط
        $upload_dir = wp_upload_dir();
        $image_data = file_get_contents($image_url);
        
        if (!$image_data) {
            return false;
        }
        
        $filename = basename($image_url);
        
        if (wp_mkdir_p($upload_dir['path'])) {
            $file = $upload_dir['path'] . '/' . $filename;
        } else {
            $file = $upload_dir['basedir'] . '/' . $filename;
        }
        
        file_put_contents($file, $image_data);
        
        $wp_filetype = wp_check_filetype($filename, null);
        $attachment = array(
            'post_mime_type' => $wp_filetype['type'],
            'post_title' => sanitize_file_name($filename),
            'post_content' => '',
            'post_status' => 'inherit'
        );
        
        $attach_id = wp_insert_attachment($attachment, $file, $post_id);
        $attach_data = wp_generate_attachment_metadata($attach_id, $file);
        wp_update_attachment_metadata($attach_id, $attach_data);
        
        set_post_thumbnail($post_id, $attach_id);
    }
    
    /**
     * إضافة صفحة الإعدادات
     */
    public function add_admin_menu() {
        add_options_page(
            'إعدادات Clawd API',
            'Clawd API',
            'manage_options',
            'clawd-wp-api-connector',
            array($this, 'options_page')
        );
    }
    
    /**
     * تهيئة الإعدادات
     */
    public function settings_init() {
        register_setting('clawdSettings', 'clawd_api_key');
        register_setting('clawdSettings', 'clawd_secret_key');
        
        add_settings_section(
            'clawd_settings_section',
            'إعدادات API',
            array($this, 'settings_section_callback'),
            'clawd-wp-api-connector'
        );
        
        add_settings_field(
            'clawd_api_key',
            'مفتاح API',
            array($this, 'api_key_render'),
            'clawd-wp-api-connector',
            'clawd_settings_section'
        );
        
        add_settings_field(
            'clawd_secret_key',
            'المفتاح السري',
            array($this, 'secret_key_render'),
            'clawd-wp-api-connector',
            'clawd_settings_section'
        );
    }
    
    /**
     * عرض حقل مفتاح API
     */
    public function api_key_render() {
        $option = get_option('clawd_api_key');
        echo '<input type="text" name="clawd_api_key" value="' . $option . '" size="50"/>';
        echo '<p class="description">هذا المفتاح سيستخدمه Clawd للاتصال بموقعك (أول إنشاء تلقائي إذا كان فارغًا)</p>';
    }
    
    /**
     * عرض حقل المفتاح السري
     */
    public function secret_key_render() {
        $option = get_option('clawd_secret_key');
        echo '<input type="text" name="clawd_secret_key" value="' . $option . '" size="50"/>';
        echo '<p class="description">هذا المفتاح السري يستخدم للتوقيع على الطلبات (أول إنشاء تلقائي إذا كان فارغًا)</p>';
    }
    
    /**
     * وصف قسم الإعدادات
     */
    public function settings_section_callback() {
        echo '<p>إعدادات الاتصال الآمن بين Clawd وموقعك ووردبريس.</p>';
    }
    
    /**
     * صفحة الإعدادات
     */
    public function options_page() {
        // إنشاء مفاتيح تلقائية إذا لم تكن موجودة
        if (!get_option('clawd_api_key')) {
            update_option('clawd_api_key', bin2hex(random_bytes(16)));
        }
        if (!get_option('clawd_secret_key')) {
            update_option('clawd_secret_key', bin2hex(random_bytes(32)));
        }
        
        ?>
        <div class="wrap">
            <h1>إعدادات Clawd WordPress API Connector</h1>
            <form method="post" action="options.php">
                <?php
                settings_fields('clawdSettings');
                do_settings_sections('clawd-wp-api-connector');
                submit_button();
                ?>
            </form>
            
            <h2>تعليمات الاستخدام</h2>
            <p>للاتصال بموقعك من خلال Clawd، ستحتاج إلى:</p>
            <ol>
                <li>مفتاح API: <code><?php echo get_option('clawd_api_key'); ?></code></li>
                <li>المفتاح السري: <code><?php echo get_option('clawd_secret_key'); ?></code></li>
                <li>رابط API: <code><?php echo get_site_url(); ?>/wp-json/clawd/v1/</code></li>
            </ol>
            
            <h3>اختبار الاتصال</h3>
            <p>لทดสอบ الاتصال، استخدم الرابط التالي:</p>
            <p><code><?php echo get_site_url(); ?>/wp-json/clawd/v1/test-connection</code></p>
            <p>مع تضمين رؤوس HTTP التالية:</p>
            <ul>
                <li><code>X-Clawd-API-Key</code>: <?php echo get_option('clawd_api_key'); ?></li>
                <li><code>X-Clawd-Signature</code>: HMAC-SHA256 للRequestBody باستخدام المفتاح السري</li>
            </ul>
        </div>
        <?php
    }
}

// بدء تشغيل الإضافة
new Clawd_WP_API_Connector();

?>
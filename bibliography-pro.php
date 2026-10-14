<?php
/**
 * Plugin Name: Bibliography Pro
 * Plugin URI: https://github.com/elizarov-n906/bibliography-pro
 * Description: Плагин для управления библиографией и примечаниями в научных статьях WordPress.
 * Version: 1.3.0
 * Author: Elizarov_n906
 * Author URI: https://github.com/elizarov-n906
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: bibliography-pro
 * Domain Path: /languages
 */

if (!defined('ABSPATH')) exit;

define('BP_URL', plugin_dir_url(__FILE__));

// === ПУСТОЙ ДЕФОЛТНЫЙ CSS (все стили в style.css) ===
function bp_get_default_css() {
    return '';
}

// === ПОЛУЧИТЬ ПОРЯДОК ВЫВОДА ===
function bp_get_display_order($post_id = null) {
    if ($post_id) {
        $post_order = get_post_meta($post_id, '_bp_display_order', true);
        if ($post_order) return $post_order;
    }
    return get_option('bp_display_order', 'notes_first');
}

// === АКТИВАЦИЯ ===
register_activation_hook(__FILE__, function() {
    global $wpdb;
    $wpdb->query("CREATE TABLE IF NOT EXISTS {$wpdb->prefix}bp_authors (
        id bigint(20) NOT NULL AUTO_INCREMENT,
        post_id bigint(20) NOT NULL,
        author_name text NOT NULL,
        author_order int(11) NOT NULL,
        is_linked tinyint(1) NOT NULL DEFAULT 0,
        type varchar(10) NOT NULL DEFAULT 'bib',
        PRIMARY KEY (id),
        KEY post_id (post_id),
        KEY type (type)
    )");
    
    if (!get_option('bp_notes_title')) {
        update_option('bp_notes_title', 'Примечания');
    }
});

// === АВТООБНОВЛЕНИЕ ТАБЛИЦЫ ===
add_action('plugins_loaded', function() {
    global $wpdb;
    $table = $wpdb->prefix . 'bp_authors';
    
    $columns = $wpdb->get_results("SHOW COLUMNS FROM $table");
    $fields = array_map(function($c){ return $c->Field; }, $columns);
    
    if (!in_array('is_linked', $fields)) {
        $wpdb->query("ALTER TABLE $table ADD COLUMN is_linked tinyint(1) NOT NULL DEFAULT 0");
    }
    
    if (!in_array('type', $fields)) {
        $wpdb->query("ALTER TABLE $table ADD COLUMN type varchar(10) NOT NULL DEFAULT 'bib'");
    }
    
    $wpdb->query("
        DELETE a FROM $table a
        INNER JOIN {$wpdb->posts} p ON a.post_id = p.ID
        WHERE p.post_type = 'revision'
    ");
});

// === ПОДКЛЮЧЕНИЕ CSS/JS ===
add_action('wp_enqueue_scripts', function() {
    if (!is_single() || get_post_type() !== 'post') return;
    
    wp_register_style('bp-style', BP_URL . 'style.css', [], '1.3');
    wp_enqueue_style('bp-style');
    
    $custom_css = get_option('bp_custom_css', '');
    if (!empty($custom_css)) {
        wp_add_inline_style('bp-style', $custom_css);
    }
    
    wp_enqueue_script('bp-script', BP_URL . 'script.js', ['jquery'], '1.3', true);
    wp_localize_script('bp-script', 'bpData', ['ajaxurl' => admin_url('admin-ajax.php')]);
});

add_action('admin_enqueue_scripts', function($hook) {
    if (strpos($hook, 'bp-plugin') !== false || in_array($hook, ['post.php', 'post-new.php'])) {
        wp_enqueue_style('bp-style', BP_URL . 'style.css', [], '1.3');
        wp_enqueue_script('bp-script', BP_URL . 'script.js', ['jquery'], '1.3', true);
        wp_localize_script('bp-script', 'bpData', ['ajaxurl' => admin_url('admin-ajax.php')]);
    }
});

// === ФУНКЦИЯ: ПРЕВРАЩЕНИЕ URL В ССЫЛКУ ===
function bp_linkify($text, $is_linked) {
    $text = esc_html($text);
    if ($is_linked) {
        $text = preg_replace(
            '/(https?:\/\/[^\s<]+)/i',
            '<a href="$1" target="_blank" rel="noopener">$1</a>',
            $text
        );
    }
    return $text;
}

// === МЕТАБОКС В СТАТЬЕ ===
add_action('add_meta_boxes', function() {
    $bp_title = get_option('bp_title', 'Библиография');
    $notes_title = get_option('bp_notes_title', 'Примечания');
    add_meta_box('bp_box', $notes_title . ' / ' . $bp_title, function($post) use ($bp_title, $notes_title) {
        global $wpdb;
        $table = $wpdb->prefix . 'bp_authors';
        wp_nonce_field('bp_save', 'bp_nonce');
        
        $post_notes_title = get_post_meta($post->ID, '_bp_notes_title', true);
        $post_bib_title = get_post_meta($post->ID, '_bp_bib_title', true);
        $post_order = get_post_meta($post->ID, '_bp_display_order', true);
        $default_order = get_option('bp_display_order', 'notes_first');
        
        $notes = $wpdb->get_results("SELECT * FROM $table WHERE post_id={$post->ID} AND type='note' ORDER BY author_order");
        $authors = $wpdb->get_results("SELECT * FROM $table WHERE post_id={$post->ID} AND type='bib' ORDER BY author_order");
        
        // ===== БЛОК НАСТРОЕК СТАТЬИ =====
        echo '<div class="bp-settings-section">';
        echo '<table class="bp-settings-table">';
        
        echo '<tr>';
        echo '<th>Заголовок блока библиографии:</th>';
        echo '<td><input type="text" name="bp_post_bib_title" value="' . esc_attr($post_bib_title) . '" placeholder="По умолчанию: ' . esc_attr($bp_title) . '" class="regular-text">';
        echo '<span class="bp-hint-inline">Оставьте пустым чтобы использовать "' . esc_html($bp_title) . '"</span></td>';
        echo '</tr>';
        
        echo '<tr>';
        echo '<th>Заголовок блока примечаний:</th>';
        echo '<td><input type="text" name="bp_post_notes_title" value="' . esc_attr($post_notes_title) . '" placeholder="По умолчанию: ' . esc_attr($notes_title) . '" class="regular-text">';
        echo '<span class="bp-hint-inline">Оставьте пустым чтобы использовать "' . esc_html($notes_title) . '"</span></td>';
        echo '</tr>';
        
        echo '<tr>';
        echo '<th>Порядок вывода на сайте:</th>';
        echo '<td>';
        $current_order = $post_order ?: $default_order;
        echo '<label><input type="radio" name="bp_post_display_order" value="notes_first" ' . ($current_order === 'notes_first' ? 'checked' : '') . '> Сначала примечания, потом библиография</label><br>';
        echo '<label><input type="radio" name="bp_post_display_order" value="bib_first" ' . ($current_order === 'bib_first' ? 'checked' : '') . '> Сначала библиография, потом примечания</label>';
        echo '<span class="bp-hint-inline">По умолчанию: ' . ($default_order === 'notes_first' ? 'примечания сверху' : 'библиография сверху') . '</span>';
        echo '</td>';
        echo '</tr>';
        
        echo '</table>';
        echo '</div>';
        
        // ===== СЕКЦИЯ БИБЛИОГРАФИИ (ПЕРВАЯ) =====
        echo '<div class="bp-section bp-section-bib">';
        echo '<h3 class="bp-section-title">' . esc_html($post_bib_title ?: $bp_title) . '</h3>';
        echo '<div class="bp-rows" data-type="bib">';
        if ($authors) {
            foreach ($authors as $i => $a) {
                $linked_class = $a->is_linked ? 'bp-active' : '';
                $linked_text = $a->is_linked ? 'Активна' : 'Ссылка';
                echo '<div class="bp-row" data-id="' . $a->id . '" data-order="' . ($i + 1) . '" data-type="bib">';
                echo '<span class="bp-num">' . ($i + 1) . '</span>';
                echo '<input type="text" name="bp_authors[]" value="' . esc_attr($a->author_name) . '" placeholder="Пример: Кант Э. Собрание сочинений...">';
                echo '<input type="hidden" name="bp_linked[]" value="' . ($a->is_linked ? 1 : 0) . '">';
                echo '<button type="button" class="button bp-toggle-link ' . $linked_class . '">' . $linked_text . '</button>';
                echo '<button type="button" class="button bp-insert-cite" title="Вставить ссылку в текст">[+]</button>';
                echo '<button type="button" class="button bp-up">Вверх</button>';
                echo '<button type="button" class="button bp-down">Вниз</button>';
                echo '<button type="button" class="button-link-delete bp-del">Удалить</button>';
                echo '</div>';
            }
        }
        echo '</div>';
        echo '<button type="button" class="button button-primary bp-add">Добавить автора</button> ';
        echo '<button type="button" class="button bp-add-10">Добавить 10 строк</button>';
        echo '<p class="bp-hint">Источники выводятся в квадратных скобках [1][2] в тексте.</p>';
        echo '</div>';
        
        // ===== СЕКЦИЯ ПРИМЕЧАНИЙ (ВТОРАЯ) =====
        echo '<div class="bp-section bp-section-notes">';
        echo '<h3 class="bp-section-title">' . esc_html($post_notes_title ?: $notes_title) . '</h3>';
        echo '<div class="bp-rows" data-type="note">';
        if ($notes) {
            foreach ($notes as $i => $n) {
                echo '<div class="bp-row" data-id="' . $n->id . '" data-order="' . ($i + 1) . '" data-type="note">';
                echo '<span class="bp-num">' . ($i + 1) . '</span>';
                echo '<input type="text" name="bp_notes[]" value="' . esc_attr($n->author_name) . '" placeholder="Текст примечания...">';
                echo '<button type="button" class="button bp-insert-note" title="Вставить примечание в текст">[прим]</button>';
                echo '<button type="button" class="button bp-up">Вверх</button>';
                echo '<button type="button" class="button bp-down">Вниз</button>';
                echo '<button type="button" class="button-link-delete bp-del">Удалить</button>';
                echo '</div>';
            }
        }
        echo '</div>';
        echo '<button type="button" class="button button-primary bp-add-note">Добавить примечание</button> ';
        echo '<button type="button" class="button bp-add-note-10">Добавить 10 строк</button>';
        echo '<p class="bp-hint">Примечания выводятся надстрочными (1, 2, 3) в тексте.</p>';
        echo '</div>';
    }, 'post', 'normal', 'high');
});

// === СОХРАНЕНИЕ ===
add_action('save_post', function($post_id) {
    if (!isset($_POST['bp_nonce']) || !wp_verify_nonce($_POST['bp_nonce'], 'bp_save')) return;
    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
    if (wp_is_post_revision($post_id)) return;
    if (wp_is_post_autosave($post_id)) return;
    if (!current_user_can('edit_post', $post_id)) return;
    
    global $wpdb;
    $table = $wpdb->prefix . 'bp_authors';
    
    $post_notes_title = isset($_POST['bp_post_notes_title']) ? sanitize_text_field($_POST['bp_post_notes_title']) : '';
    $post_bib_title = isset($_POST['bp_post_bib_title']) ? sanitize_text_field($_POST['bp_post_bib_title']) : '';
    $post_order = isset($_POST['bp_post_display_order']) ? sanitize_text_field($_POST['bp_post_display_order']) : '';
    
    update_post_meta($post_id, '_bp_notes_title', $post_notes_title);
    update_post_meta($post_id, '_bp_bib_title', $post_bib_title);
    update_post_meta($post_id, '_bp_display_order', $post_order);
    
    $wpdb->delete($table, ['post_id' => $post_id]);
    
    if (isset($_POST['bp_notes'])) {
        foreach ($_POST['bp_notes'] as $i => $text) {
            if (trim($text)) {
                $wpdb->insert($table, [
                    'post_id' => $post_id,
                    'author_name' => trim($text),
                    'author_order' => $i + 1,
                    'is_linked' => 0,
                    'type' => 'note'
                ]);
            }
        }
    }
    
    if (isset($_POST['bp_authors'])) {
        $linked = $_POST['bp_linked'] ?? [];
        foreach ($_POST['bp_authors'] as $i => $name) {
            if (trim($name)) {
                $is_linked = isset($linked[$i]) && $linked[$i] == 1 ? 1 : 0;
                $wpdb->insert($table, [
                    'post_id' => $post_id,
                    'author_name' => trim($name),
                    'author_order' => $i + 1,
                    'is_linked' => $is_linked,
                    'type' => 'bib'
                ]);
            }
        }
    }
});

// === ПРЕОБРАЗОВАНИЕ {note:N} И [N] В ССЫЛКИ ===
add_filter('the_content', 'bp_convert_citations');
function bp_convert_citations($content) {
    if (!is_single() || get_post_type() !== 'post') return $content;
    
    global $wpdb;
    $table = $wpdb->prefix . 'bp_authors';
    $all = $wpdb->get_results($wpdb->prepare("SELECT * FROM $table WHERE post_id = %d ORDER BY author_order", get_the_ID()));
    
    if (!$all) return $content;
    
    $notes_map = [];
    $bib_map = [];
    foreach ($all as $a) {
        if ($a->type === 'note') {
            $notes_map[$a->author_order] = $a->author_name;
        } else {
            $bib_map[$a->author_order] = $a->author_name;
        }
    }
    
    $content = preg_replace_callback(
        '/\{note:(\d+)\}/',
        function($matches) use ($notes_map) {
            $num = intval($matches[1]);
            if (isset($notes_map[$num])) {
                return '<sup class="bp-note-link" data-num="' . $num . '" data-text="' . esc_attr($notes_map[$num]) . '">' . $num . '</sup>';
            }
            return $matches[0];
        },
        $content
    );
    
    $content = preg_replace_callback(
        '/\[(\d+)\]/',
        function($matches) use ($bib_map) {
            $num = intval($matches[1]);
            if (isset($bib_map[$num])) {
                return '<span class="bp-link" data-num="' . $num . '" data-name="' . esc_attr($bib_map[$num]) . '">[' . $num . ']</span>';
            }
            return $matches[0];
        },
        $content
    );
    
    return $content;
}

// === ВЫВОД СПИСКОВ С УЧЕТОМ ПОРЯДКА ===
add_filter('the_content', 'bp_add_lists_to_content', 20);
function bp_add_lists_to_content($content) {
    if (!is_single() || get_post_type() !== 'post') return $content;
    
    global $wpdb;
    $table = $wpdb->prefix . 'bp_authors';
    $post_id = get_the_ID();
    
    $notes = $wpdb->get_results($wpdb->prepare("SELECT * FROM $table WHERE post_id = %d AND type='note' ORDER BY author_order", $post_id));
    $authors = $wpdb->get_results($wpdb->prepare("SELECT * FROM $table WHERE post_id = %d AND type='bib' ORDER BY author_order", $post_id));
    
    $notes_title = get_post_meta($post_id, '_bp_notes_title', true) ?: get_option('bp_notes_title', 'Примечания');
    $bp_title = get_post_meta($post_id, '_bp_bib_title', true) ?: get_option('bp_title', 'Библиография');
    
    $display_order = bp_get_display_order($post_id);
    
    $notes_block = '';
    if ($notes) {
        $notes_block .= '<div class="bp-bib bp-notes"><h3>' . esc_html($notes_title) . '</h3><ol>';
        foreach ($notes as $n) {
            $notes_block .= '<li>' . esc_html($n->author_name) . '</li>';
        }
        $notes_block .= '</ol></div>';
    }
    
    $bib_block = '';
    if ($authors) {
        $bib_block .= '<div class="bp-bib"><h3>' . esc_html($bp_title) . '</h3><ol>';
        foreach ($authors as $a) {
            $bib_block .= '<li>' . bp_linkify($a->author_name, $a->is_linked) . '</li>';
        }
        $bib_block .= '</ol></div>';
    }
    
    if ($display_order === 'bib_first') {
        $content .= $bib_block;
        $content .= $notes_block;
    } else {
        $content .= $notes_block;
        $content .= $bib_block;
    }
    
    $content .= '<div class="bp-popup"><div class="bp-popup-box"><span class="bp-popup-close">×</span><div class="bp-popup-text"></div></div></div>';
    return $content;
}

// === АДМИНКА ===
add_action('admin_menu', function() {
    add_menu_page('Библиография', 'Библиография', 'manage_options', 'bp-plugin', 'bp_admin_page', 'dashicons-book', 30);
});

// === СОХРАНЕНИЕ НАСТРОЕК ===
add_action('admin_init', function() {
    if (isset($_POST['bp_save_settings']) && wp_verify_nonce($_POST['bp_settings_nonce'], 'bp_settings')) {
        $title = sanitize_text_field($_POST['bp_title'] ?? '');
        $notes_title = sanitize_text_field($_POST['bp_notes_title'] ?? '');
        $display_order = sanitize_text_field($_POST['bp_display_order'] ?? 'notes_first');
        
        update_option('bp_title', $title);
        update_option('bp_notes_title', $notes_title);
        update_option('bp_display_order', $display_order);
        
        $css = isset($_POST['bp_custom_css']) ? wp_strip_all_tags($_POST['bp_custom_css']) : '';
        update_option('bp_custom_css', $css);
        
        add_action('admin_notices', function() {
            echo '<div class="notice notice-success is-dismissible"><p>Настройки сохранены!</p></div>';
        });
    }
    
    if (isset($_POST['bp_reset_css']) && wp_verify_nonce($_POST['bp_settings_nonce'], 'bp_settings')) {
        update_option('bp_custom_css', '');
        add_action('admin_notices', function() {
            echo '<div class="notice notice-info is-dismissible"><p>CSS стили очищены. Все стили берутся из файла style.css</p></div>';
        });
    }
});

function bp_admin_page() {
    global $wpdb;
    $table = $wpdb->prefix . 'bp_authors';
    $tab = $_GET['tab'] ?? 'articles';
    $bp_title = get_option('bp_title', 'Библиография');
    $notes_title = get_option('bp_notes_title', 'Примечания');
    $display_order = get_option('bp_display_order', 'notes_first');
    $bp_css = get_option('bp_custom_css', '');
    
    echo '<div class="wrap"><h1>Библиография</h1>';
    echo '<h2 class="nav-tab-wrapper">';
    echo '<a href="?page=bp-plugin&tab=articles" class="nav-tab ' . ($tab === 'articles' ? 'nav-tab-active' : '') . '">Статьи</a>';
    echo '<a href="?page=bp-plugin&tab=authors" class="nav-tab ' . ($tab === 'authors' ? 'nav-tab-active' : '') . '">Все записи</a>';
    echo '<a href="?page=bp-plugin&tab=settings" class="nav-tab ' . ($tab === 'settings' ? 'nav-tab-active' : '') . '">Настройки</a>';
    echo '</h2>';
    
    if ($tab === 'settings') {
        echo '<form method="post" style="max-width:900px;margin-top:20px">';
        wp_nonce_field('bp_settings', 'bp_settings_nonce');
        
        echo '<table class="form-table">';
        echo '<tr><th><label for="bp_notes_title">Заголовок примечаний (по умолчанию)</label></th>';
        echo '<td><input type="text" id="bp_notes_title" name="bp_notes_title" value="' . esc_attr($notes_title) . '" class="regular-text">';
        echo '<p class="description">Заголовок блока примечаний для всех статей. Можно переопределить в каждой статье отдельно.</p></td></tr>';
        
        echo '<tr><th><label for="bp_title">Заголовок библиографии (по умолчанию)</label></th>';
        echo '<td><input type="text" id="bp_title" name="bp_title" value="' . esc_attr($bp_title) . '" class="regular-text">';
        echo '<p class="description">Заголовок блока библиографии для всех статей. Можно переопределить в каждой статье отдельно.</p></td></tr>';
        
        echo '<tr><th>Порядок вывода на сайте (по умолчанию)</th>';
        echo '<td>';
        echo '<label><input type="radio" name="bp_display_order" value="notes_first" ' . ($display_order === 'notes_first' ? 'checked' : '') . '> Сначала примечания, потом библиография</label><br>';
        echo '<label><input type="radio" name="bp_display_order" value="bib_first" ' . ($display_order === 'bib_first' ? 'checked' : '') . '> Сначала библиография, потом примечания</label>';
        echo '<p class="description">Порядок вывода блоков в конце статьи. Можно переопределить для каждой статьи отдельно.</p></td></tr>';
        
        echo '<tr><th><label for="bp_custom_css">Дополнительные CSS стили (необязательно)</label></th>';
        echo '<td>';
        echo '<textarea id="bp_custom_css" name="bp_custom_css" class="bp-css-editor" placeholder="Здесь можно добавить свои CSS стили поверх стандартных из файла style.css">' . esc_textarea($bp_css) . '</textarea>';
        echo '<p class="bp-css-hint">Основные стили находятся в файле <code>style.css</code>. Это поле для дополнительных стилей если нужно что-то переопределить.</p>';
        echo '</td></tr>';
        echo '</table>';
        
        echo '<p class="submit">';
        echo '<button type="submit" name="bp_save_settings" class="button button-primary">Сохранить настройки</button> ';
        echo '<button type="submit" name="bp_reset_css" class="button" onclick="return confirm(\'Очистить дополнительные CSS стили?\')">Очистить CSS</button>';
        echo '</p>';
        echo '</form>';
        
    } elseif ($tab === 'articles') {
        $results = $wpdb->get_results("
            SELECT a.post_id, COUNT(*) as cnt
            FROM $table a 
            INNER JOIN {$wpdb->posts} p ON a.post_id = p.ID 
            WHERE p.post_type != 'revision' AND p.post_type != 'nav_menu_item'
            GROUP BY a.post_id 
            ORDER BY cnt DESC
        ");
        
        $status_labels = [
            'publish' => 'Опубликована',
            'draft' => 'Черновик',
            'pending' => 'На проверке',
            'private' => 'Приватная',
            'future' => 'Запланирована',
            'trash' => 'В корзине'
        ];
        
        echo '<table class="wp-list-table widefat striped"><thead><tr><th>Статья</th><th>Статус</th><th>Записей</th><th></th></tr></thead><tbody>';
        if ($results) {
            foreach ($results as $r) {
                $post = get_post($r->post_id);
                if (!$post) {
                    echo '<tr><td><em>Запись не найдена (ID: ' . $r->post_id . ')</em></td><td>—</td><td>' . $r->cnt . '</td><td></td></tr>';
                    continue;
                }
                $status = $status_labels[$post->post_status] ?? $post->post_status;
                $status_color = '#2271b1';
                if ($post->post_status === 'future') $status_color = '#f0b849';
                if ($post->post_status === 'draft') $status_color = '#999';
                if ($post->post_status === 'trash') $status_color = '#d63638';
                if ($post->post_status === 'pending') $status_color = '#dba617';
                if ($post->post_status === 'private') $status_color = '#8c8f94';
                
                echo '<tr>';
                echo '<td><strong>' . esc_html($post->post_title) . '</strong></td>';
                echo '<td><span style="color:' . $status_color . ';font-weight:600">' . esc_html($status) . '</span></td>';
                echo '<td>' . $r->cnt . '</td>';
                echo '<td>';
                echo '<a href="' . get_edit_post_link($r->post_id) . '" class="button button-small">Редактировать</a> ';
                echo '<a href="' . get_permalink($r->post_id) . '" target="_blank" class="button button-small">Открыть</a>';
                echo '</td>';
                echo '</tr>';
            }
        } else {
            echo '<tr><td colspan="4">Нет статей с библиографией</td></tr>';
        }
        echo '</tbody></table>';
    } else {
        $search = $_GET['s'] ?? '';
        $where = $search ? "AND a.author_name LIKE '%" . esc_sql($search) . "%'" : '';
        $results = $wpdb->get_results("
            SELECT a.*, p.post_title 
            FROM $table a 
            LEFT JOIN {$wpdb->posts} p ON a.post_id=p.ID 
            WHERE (p.post_type IS NULL OR p.post_type != 'revision') $where 
            ORDER BY a.post_id, a.type, a.author_order
        ");
        
        echo '<form method="get"><input type="hidden" name="page" value="bp-plugin"><input type="hidden" name="tab" value="authors"><p><input type="text" name="s" value="' . esc_attr($search) . '" placeholder="Поиск..." class="regular-text"> <button class="button button-primary">Искать</button></p></form>';
        
        echo '<table class="wp-list-table widefat striped"><thead><tr><th>ID</th><th>Тип</th><th>Запись</th><th>Статья</th><th>№</th></tr></thead><tbody>';
        if ($results) {
            foreach ($results as $r) {
                $type_label = $r->type === 'note' ? 'Примечание' : 'Литература';
                echo '<tr><td>' . $r->id . '</td><td>' . $type_label . '</td><td>' . esc_html($r->author_name) . '</td><td>' . esc_html($r->post_title ?: 'Удалено') . '</td><td>' . $r->author_order . '</td></tr>';
            }
        } else {
            echo '<tr><td colspan="5">Не найдено</td></tr>';
        }
        echo '</tbody></table>';
    }
    echo '</div>';
}

// === AJAX ===
add_action('wp_ajax_bp_get_authors_list', function() {
    $post_id = intval($_POST['post_id'] ?? 0);
    if (!$post_id) wp_send_json_error('Нет статьи');
    
    global $wpdb;
    $table = $wpdb->prefix . 'bp_authors';
    $authors = $wpdb->get_results($wpdb->prepare("SELECT author_order, author_name, type FROM $table WHERE post_id = %d ORDER BY type, author_order", $post_id));
    
    wp_send_json_success($authors);
});

// === ССЫЛКА "НАСТРОЙКИ" В СПИСКЕ ПЛАГИНОВ ===
add_filter('plugin_action_links_' . plugin_basename(__FILE__), function($links) {
    $settings_link = '<a href="' . admin_url('admin.php?page=bp-plugin') . '">Настройки</a>';
    array_unshift($links, $settings_link);
    return $links;
});

// === КНОПКА В РЕДАКТОРЕ ===
add_action('admin_print_footer_scripts', function() {
    global $pagenow;
    if (!in_array($pagenow, ['post.php', 'post-new.php'])) return;
    ?>
    <script>
    (function() {
        if (typeof tinymce !== 'undefined') {
            tinymce.on('AddEditor', function(e) {
                var editor = e.editor;
                editor.on('init', function() {
                    editor.addButton('bp_insert_citation', {
                        text: 'Сноска',
                        icon: false,
                        type: 'menubutton',
                        menu: [
                            {text: 'Примечание', onclick: function() { bpOpenDialog(editor, 'note'); }},
                            {text: 'Библиография', onclick: function() { bpOpenDialog(editor, 'bib'); }}
                        ]
                    });
                });
            });
        }
        
        if (typeof wp !== 'undefined' && wp.blocks) {
            wp.domReady(function() {
                if (typeof wp.plugins !== 'undefined' && typeof wp.editPost !== 'undefined') {
                    var registerPlugin = wp.plugins.registerPlugin;
                    var PluginMoreMenuItem = wp.editPost.PluginMoreMenuItem;
                    var el = wp.element.createElement;
                    
                    registerPlugin('bp-citation-plugin', {
                        render: function() {
                            return el(
                                PluginMoreMenuItem,
                                { icon: 'editor-quote', onClick: function() { bpOpenDialogGutenberg('bib'); }},
                                'Вставить ссылку литературы'
                            );
                        }
                    });
                }
            });
        }
    })();
    
    function bpOpenDialog(editor, type) {
        var postId = jQuery('#post_ID').val();
        if (!postId) { alert('Сначала сохраните статью'); return; }
        
        jQuery.ajax({
            url: bpData.ajaxurl,
            type: 'POST',
            data: { action: 'bp_get_authors_list', post_id: postId },
            success: function(response) {
                if (response.success && response.data.length > 0) {
                    var filtered = response.data.filter(function(a) { return a.type === type; });
                    if (filtered.length === 0) {
                        alert('Нет записей типа ' + (type === 'note' ? 'Примечание' : 'Библиография'));
                        return;
                    }
                    var list = filtered.map(function(a) {
                        return a.author_order + '. ' + a.author_name.substring(0, 50);
                    }).join('\n');
                    
                    var num = prompt('Введите номер:\n\n' + list + '\n\nНомер:');
                    if (num && !isNaN(num)) {
                        var insert = type === 'note' ? '{note:' + num + '}' : '[' + num + ']';
                        editor.insertContent(insert);
                    }
                } else {
                    alert('Сначала добавьте записи в блоке ниже и сохраните статью');
                }
            }
        });
    }
    
    function bpOpenDialogGutenberg(type) {
        var postId = wp.data.select('core/editor').getCurrentPostId();
        if (!postId) { alert('Сначала сохраните статью'); return; }
        
        jQuery.ajax({
            url: bpData.ajaxurl,
            type: 'POST',
            data: { action: 'bp_get_authors_list', post_id: postId },
            success: function(response) {
                if (response.success && response.data.length > 0) {
                    var filtered = response.data.filter(function(a) { return a.type === type; });
                    var list = filtered.map(function(a) {
                        return a.author_order + '. ' + a.author_name.substring(0, 50);
                    }).join('\n');
                    
                    var num = prompt('Введите номер:\n\n' + list + '\n\nНомер:');
                    if (num && !isNaN(num)) {
                        var insert = type === 'note' ? '{note:' + num + '}' : '[' + num + ']';
                        var block = wp.blocks.createBlock('core/paragraph', { content: insert });
                        wp.data.dispatch('core/block-editor').insertBlock(block);
                    }
                } else {
                    alert('Сначала добавьте записи в блоке ниже и сохраните статью');
                }
            }
        });
    }
    </script>
    <?php
});
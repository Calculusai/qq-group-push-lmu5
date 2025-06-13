<?php
/**
 * Plugin Name: 子比主题-Q群/QQ推送
 * Plugin URI: https://hoshinoai.xin
 * Description: 原作者落幕-发布或更新文章时向QQ群发送通知，支持QQ群消息触发论坛签到。星野爱-子比主题适配、论坛绑定、签到、解绑功能、最新帖子查询。
 * Version: 0.0.5
 * Author: 星野爱
 * Author URI: https://hoshinoai.xin
 * Contributors: 星野爱 (https://hoshinoai.xin)
 * 
 * 这是一个WordPress插件，用于在文章发布、更新或收到评论时
 * 自动向指定的QQ群发送通知消息。支持napcat/ntqq协议。
 * 同时支持通过QQ群消息触发网站操作，如论坛签到。
 * 
 * 原作者：落幕（基础推送功能）
 * 子比主题适配及扩展功能：星野爱 (https://hoshinoai.xin)
 * - 论坛账号绑定功能
 * - 论坛签到功能
 * - 论坛账号解绑功能
 * - 最新帖子查询功能
 * - QQ群消息处理
 */

// 防止直接访问
if (!defined('ABSPATH')) {
    exit;
}

// 定义插件常量
define('QQPUSH_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('QQPUSH_PLUGIN_URL', plugin_dir_url(__FILE__));
define('QQPUSH_VERSION', '0.0.5');

// 添加卸载钩子
register_uninstall_hook(__FILE__, 'qqpush_uninstall');

/**
 * 插件卸载时的清理操作
 * 删除所有插件相关的选项和元数据
 */
function qqpush_uninstall() {
    // 删除插件选项
    delete_option('qqpush_options');
    
    // 删除所有用户的QQ绑定元数据
    global $wpdb;
    $wpdb->delete($wpdb->usermeta, array('meta_key' => 'qqpush_bound_qq_id'));
    
    // 清理可能的其他数据
    // 如果将来添加了其他元数据，也应在此处删除
}

if (!class_exists('CSF')) {
    $theme_csf_path = get_template_directory() . '/inc/codestar-framework/codestar-framework.php';
    $theme_csf_path2 = get_template_directory() . '/inc/csf-framework/classes/setup.class.php';
    
    if (file_exists($theme_csf_path)) {
        require_once $theme_csf_path;
    } elseif (file_exists($theme_csf_path2)) {
        require_once $theme_csf_path2;
    } else {
        add_action('admin_notices', function() {
            echo '<div class="error"><p>星野爱悬赏系统需要子比主题的Codestar Framework框架支持，请确保子比主题已安装并激活。</p></div>';
        });
        return;
    }
}

// 引入功能文件
require_once QQPUSH_PLUGIN_DIR . 'qqpush-functions.php';

// 引入后台管理文件
if (is_admin()) {
    require_once QQPUSH_PLUGIN_DIR . 'qqpush-admin.php';
}

// 引入扩展功能文件
require_once QQPUSH_PLUGIN_DIR . 'qqpush-extend.php';

/**
 * 插件初始化
 */
function qqpush_init() {
    // 初始化推送功能钩子
    qqpush_init_hooks();
    
    // 初始化后台设置页面（仅在后台）
    if (is_admin()) {
        add_action('init', 'qqpush_create_csf_options');
    }
}
add_action('plugins_loaded', 'qqpush_init');

/**
 * 插件激活时的操作
 */
function qqpush_activate() {
    // 插件激活时的初始化操作
    flush_rewrite_rules();
}
register_activation_hook(__FILE__, 'qqpush_activate');

/**
 * 插件停用时的操作
 */
function qqpush_deactivate() {
    // 插件停用时的清理操作
    flush_rewrite_rules();
}
register_deactivation_hook(__FILE__, 'qqpush_deactivate');
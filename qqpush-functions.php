<?php
/**
 * QQ群推送插件 - 核心功能
 * 
 * @package QQGroupPush
 * @author 落幕
 * @contributor 星野爱 (https://hoshinoai.xin)
 * @version 0.0.4
 * 
 * 原作者：落幕（基础推送功能）
 * 子比主题适配及扩展功能：星野爱 (https://hoshinoai.xin)
 * - 论坛账号绑定功能
 * - 论坛签到功能
 * - 论坛账号解绑功能
 * - QQ群消息处理
 */

// 防止直接访问
if (!defined('ABSPATH')) {
    exit;
}

/**
 * 获取CSF选项值的辅助函数
 */
function qqpush_get_option($key, $default = '') {
    $options = get_option('qqpush_options', array());
    return isset($options[$key]) ? $options[$key] : $default;
}

/**
 * 处理文章状态变化事件
 * 当文章发布或更新时触发QQ群推送
 * 
 * @param string $new_status 新状态
 * @param string $old_status 旧状态
 * @param WP_Post $post 文章对象
 */
function qqpush_send_qq_group_notification_with_image($new_status, $old_status, $post) {
    // 只处理文章和论坛帖子类型，忽略自动草稿
    if (!in_array($post->post_type, ['post', 'forum_post']) || $new_status === 'auto-draft') {
        return;
    }

    // 判断是否为新文章发布（需要开启新文章推送选项）
    $publishNotification = $new_status === 'publish' && $old_status !== 'publish' && qqpush_get_option('qq_group_push_enable_new_post_notice', true);
    // 判断是否为文章更新（需要开启更新推送选项）
    $updateNotification = $new_status === 'publish' && $old_status === 'publish' && qqpush_get_option('qq_group_push_enable_update_notice');

    // 如果满足推送条件，则发送通知
    if ($publishNotification || $updateNotification) {
        $content_type = ($post->post_type === 'forum_post') ? '帖子' : '文章';
        $action_word = $publishNotification ? "新{$content_type}发布" : "{$content_type}更新";
        qqpush_send_qq_group_notification($post, $action_word);
    }
}

/**
 * 发送QQ群通知消息
 * 向配置的QQ群发送文章发布/更新通知
 * 
 * @param WP_Post $post 文章对象
 * @param string $action_word 操作描述（新文章发布/文章更新）
 */
function qqpush_send_qq_group_notification($post, $action_word) {
    // 构建API请求URL
    $url = esc_url_raw(trim(qqpush_get_option('qq_group_push_url')) . '/send_group_msg');
    // 获取访问令牌
    $access_token = qqpush_get_option('qq_group_push_access_token'); 
    // 获取并处理QQ群号列表
    $qq_group_numbers = sanitize_text_field(qqpush_get_option('qq_group_numbers'));
    $group_ids = array_filter(array_map('trim', explode(',', $qq_group_numbers)));
    // 是否@全体成员
    $enable_at_all = qqpush_get_option('qq_group_push_enable_at_all');
    
    // 获取文章所属分类
    $category_name = '';
    if ($post->post_type === 'post') {
        // 普通文章获取分类名称
        $categories = get_the_category($post->ID);
        if (!empty($categories)) {
            $category_name = $categories[0]->name;
        }
    } elseif ($post->post_type === 'forum_post') {
        // 论坛帖子获取板块名称
        // 子比主题的论坛帖子使用plate_id元数据关联板块
        $plate_id = get_post_meta($post->ID, 'plate_id', true);
        if ($plate_id) {
            $plate = get_post($plate_id);
            if ($plate) {
                $category_name = $plate->post_title;
            }
        }
    }
    
    // 分类名称显示
    $category_text = !empty($category_name) ? "【{$category_name}】" : "";

    // 遍历每个QQ群发送消息
    foreach ($group_ids as $group_id) {
        // 获取文章图片：优先特色图片，其次内容中的第一张图片
        $image_url = get_the_post_thumbnail_url($post);
        if (!$image_url) {
            preg_match('/<img.+src=[\'"](?P<src>.+?)[\'"].*>/i', $post->post_content, $image);
            $image_url = !empty($image['src']) ? $image['src'] : '';
        }

        // 格式化时间为北京时间
        $formatted_date = (new DateTime('now', new DateTimeZone('Asia/Shanghai')))->format('Y年m月d日 H:i');
        // 构建CQ码格式的图片
        $cq_image_code = $image_url ? "[CQ:image,file={$image_url}]" : "";
        // 获取文章标题和链接
        $post_title = get_the_title($post->ID);
        $post_url = get_permalink($post->ID); 
        // 构建@全体成员消息
        $at_all_message = $enable_at_all ? "[CQ:at,qq=all]\n" : "";
        // 组装完整消息内容
        $message = $at_all_message . "[CQ:face,id=144] {$action_word}：\n{$category_text}【{$post_title}】\n{$cq_image_code}\n【{$formatted_date}】\n查看详情：{$post_url}";

        // 构建请求体
        $body = wp_json_encode([
            'group_id' => $group_id,
            'message'  => $message
        ]);

        // 设置请求头
        $headers = ['Content-Type' => 'application/json; charset=utf-8'];
        if (!empty($access_token)) {
            $headers['Authorization'] = 'Bearer ' . $access_token;
        }

        // 发送HTTP请求（非阻塞模式）
        $timeout = qqpush_get_option('qq_group_push_timeout', 15);
        $response = wp_remote_post($url, [
            'method'    => 'POST',
            'headers'   => $headers,
            'body'      => $body,
            'blocking'  => false,  // 非阻塞模式，不等待响应
            'timeout'   => $timeout,
        ]);
        
        // 调试模式下记录日志
        if (qqpush_get_option('qq_group_push_debug_mode')) {
            error_log('QQ群推送调试: 向群' . $group_id . '发送消息: ' . $message);
        }
    }
}

/**
 * 处理新评论通知
 * 当有新评论时向指定QQ号发送私信通知
 * 
 * @param int $comment_id 评论ID
 * @param WP_Comment $comment_object 评论对象
 */
function qqpush_notify_on_new_comment($comment_id, $comment_object) {
    // 获取被评论的文章对象
    $post = get_post($comment_object->comment_post_ID);
    // 只处理文章和论坛帖子的评论
    if (!$post || !in_array($post->post_type, ['post', 'forum_post'])) {
        return;
    }
    
    // 获取评论时间
    $comment_time = current_time('Y年m月d日 H:i:s');
    // 获取评论者信息
    $comment_author = $comment_object->comment_author;
    $comment_content = $comment_object->comment_content;
    // 获取被评论文章信息
    $post_title = get_the_title($comment_object->comment_post_ID);
    $post_url = get_permalink($comment_object->comment_post_ID);
    // 获取接收通知的QQ号
    $user_id = qqpush_get_option('qq_group_push_comment_notice_qq');
    // 构建通知消息
    $post_type_name = ($post->post_type === 'forum_post') ? '帖子' : '文章';
    $message = "新评论提醒：\n评论时间：{$comment_time}\n用户【{$comment_author}】在{$post_type_name}【{$post_title}】评论如下：\n{$comment_content}\n点击前往查看：{$post_url}";
        
    // 检查是否启用评论推送
    $updateNotification = qqpush_get_option('qq_group_push_enable_article_comment_update_notice');
    if ($updateNotification) {
        qqpush_send_private_msg_to_qq($user_id, $message);
    }
}

/**
 * 发送QQ私信消息
 * 向指定QQ号发送私信通知
 * 
 * @param string $user_id 接收消息的QQ号
 * @param string $message 消息内容
 */
function qqpush_send_private_msg_to_qq($user_id, $message) {
    error_log('QQ群推送: 开始发送私信，QQ号=' . $user_id . ', 消息=' . $message);
    
    // 构建私信API请求URL
    $url = esc_url_raw(trim(qqpush_get_option('qq_group_push_url')) . '/send_msg');
    error_log('QQ群推送: 发送私信URL=' . $url);
    
    // 获取访问令牌
    $access_token = qqpush_get_option('qq_group_push_access_token'); 

    // 构建私信请求体
    $body = wp_json_encode([
        'message_type' => 'private',  // 私信类型
        'user_id' => intval($user_id), // 接收者QQ号，确保是整数
        'message'  => $message        // 消息内容
    ]);

    // 设置请求头
    $headers = ['Content-Type' => 'application/json; charset=utf-8'];
    if (!empty($access_token)) {
        $headers['Authorization'] = 'Bearer ' . $access_token;
    }

    // 发送HTTP请求（非阻塞模式）
    $timeout = qqpush_get_option('qq_group_push_timeout', 15);
    $response = wp_remote_post($url, [
        'method'    => 'POST',
        'headers'   => $headers,
        'body'      => $body,
        'blocking'  => true,  // 改为阻塞模式以便获取响应
        'timeout'   => $timeout,
    ]);
    
    // 记录发送结果
    if (is_wp_error($response)) {
        error_log('QQ群推送: 发送私信失败: ' . $response->get_error_message());
    } else {
        $response_code = wp_remote_retrieve_response_code($response);
        $response_body = wp_remote_retrieve_body($response);
        error_log('QQ群推送: 私信已发送，响应码: ' . $response_code . ', 响应内容: ' . $response_body);
    }
}

/**
 * 初始化推送功能钩子
 */
function qqpush_init_hooks() {
    // 监听文章状态变化，触发QQ群推送
    add_action('transition_post_status', 'qqpush_send_qq_group_notification_with_image', 10, 3);
    
    // 监听新评论事件，触发QQ通知
    add_action('wp_insert_comment', 'qqpush_notify_on_new_comment', 10, 2);
    
    // 注册接收QQ群消息的REST API端点
    add_action('rest_api_init', 'qqpush_register_message_endpoint');
}

/**
 * 注册REST API端点以接收QQ群消息
 */
function qqpush_register_message_endpoint() {
    register_rest_route('qqpush/v1', '/receive', array(
        'methods' => 'POST',
        'callback' => 'qqpush_handle_message',
        'permission_callback' => 'qqpush_verify_request'
    ));
}

/**
 * 验证请求是否合法
 * 
 * @param WP_REST_Request $request
 * @return bool
 */
function qqpush_verify_request($request) {
    // 添加日志记录
    error_log('QQ群推送: 收到API请求，开始验证');
    
    // 验证访问令牌
    $access_token = qqpush_get_option('qq_group_push_access_token');
    if (empty($access_token)) {
        error_log('QQ群推送: 未设置访问令牌，跳过验证');
        return true; // 如果未设置令牌，则不验证
    }
    
    $auth_header = $request->get_header('authorization');
    if (empty($auth_header) || strpos($auth_header, 'Bearer ') !== 0) {
        error_log('QQ群推送: 验证失败，缺少有效的Authorization头');
        return false;
    }
    
    $token = substr($auth_header, 7); // 去除 "Bearer " 前缀
    $result = $token === $access_token;
    error_log('QQ群推送: 令牌验证' . ($result ? '成功' : '失败'));
    return $result;
}

/**
 * 发送QQ群消息
 * 向指定QQ群发送消息
 * 
 * @author 星野爱
 * @param string $group_id 群号
 * @param string $message 消息内容
 */
function qqpush_send_group_msg_to_qq($group_id, $message) {
    error_log('QQ群推送: 开始发送群消息，群号=' . $group_id . ', 消息=' . $message);
    
    // 构建群消息API请求URL
    $url = esc_url_raw(trim(qqpush_get_option('qq_group_push_url')) . '/send_group_msg');
    error_log('QQ群推送: 发送群消息URL=' . $url);
    
    // 获取访问令牌
    $access_token = qqpush_get_option('qq_group_push_access_token'); 

    // 构建群消息请求体
    $body = wp_json_encode([
        'group_id' => intval($group_id), // 群号，确保是整数
        'message'  => $message           // 消息内容
    ]);

    // 设置请求头
    $headers = ['Content-Type' => 'application/json; charset=utf-8'];
    if (!empty($access_token)) {
        $headers['Authorization'] = 'Bearer ' . $access_token;
    }

    // 发送HTTP请求
    $timeout = qqpush_get_option('qq_group_push_timeout', 15);
    $response = wp_remote_post($url, [
        'method'    => 'POST',
        'headers'   => $headers,
        'body'      => $body,
        'blocking'  => true,  // 阻塞模式以便获取响应
        'timeout'   => $timeout,
    ]);
    
    // 记录发送结果
    if (is_wp_error($response)) {
        error_log('QQ群推送: 发送群消息失败: ' . $response->get_error_message());
    } else {
        $response_code = wp_remote_retrieve_response_code($response);
        $response_body = wp_remote_retrieve_body($response);
        error_log('QQ群推送: 群消息已发送，响应码: ' . $response_code . ', 响应内容: ' . $response_body);
    }
}

/**
 * 发送QQ群@消息，避免用户名重复显示
 * 
 * @author 星野爱
 * @param string $group_id 群号
 * @param string $qq_id 要@的QQ号
 * @param string $message 消息内容
 */
function qqpush_send_at_msg_to_qq($group_id, $qq_id, $message) {
    // 构建@消息
    $at_message = "[CQ:at,qq={$qq_id}] " . $message;
    
    // 发送群消息
    qqpush_send_group_msg_to_qq($group_id, $at_message);
}

/**
 * 处理接收到的QQ群消息
 * 
 * @author 星野爱
 * @param WP_REST_Request $request
 * @return WP_REST_Response
 */
function qqpush_handle_message($request) {
    // 记录原始请求数据
    $raw_params = $request->get_params();
    error_log('QQ群推送: 收到消息: ' . json_encode($raw_params, JSON_UNESCAPED_UNICODE));
    
    $params = $request->get_params();
    
    // 如果未启用QQ群互动功能，则忽略所有消息
    if (!qqpush_get_option('qq_group_interaction_enable')) {
        error_log('QQ群推送: QQ群互动功能未启用，忽略消息');
        return rest_ensure_response(array('status' => 'ignored', 'reason' => 'interaction_disabled'));
    }
    
    // 如果不是群消息，则忽略
    if (empty($params['message_type']) || $params['message_type'] !== 'group') {
        error_log('QQ群推送: 非群消息，message_type=' . (isset($params['message_type']) ? $params['message_type'] : '空'));
        return rest_ensure_response(array('status' => 'ignored', 'reason' => 'not_group_message'));
    }
    
    // 获取配置的QQ群号列表
    $qq_group_numbers = sanitize_text_field(qqpush_get_option('qq_group_numbers'));
    $group_ids = array_filter(array_map('trim', explode(',', $qq_group_numbers)));
    error_log('QQ群推送: 配置的群号: ' . implode(',', $group_ids));
    
    // 如果消息不是来自配置的群，则忽略
    if (empty($params['group_id']) || !in_array($params['group_id'], $group_ids)) {
        error_log('QQ群推送: 消息来自未配置的群，group_id=' . (isset($params['group_id']) ? $params['group_id'] : '空'));
        return rest_ensure_response(array('status' => 'ignored', 'reason' => 'group_not_configured'));
    }
    
    // 获取群号
    $group_id = $params['group_id'];
    
    // 获取消息内容和发送者QQ号
    $message = '';
    $qq_id = isset($params['user_id']) ? $params['user_id'] : '';
    
    // 获取发送者昵称
    $sender_name = '';
    if (isset($params['sender']) && is_array($params['sender'])) {
        if (!empty($params['sender']['card'])) {
            $sender_name = $params['sender']['card']; // 群名片
        } elseif (!empty($params['sender']['nickname'])) {
            $sender_name = $params['sender']['nickname']; // 昵称
        }
    }
    
    // 处理不同格式的消息内容
    if (isset($params['raw_message']) && is_string($params['raw_message'])) {
        // go-cqhttp格式
        $message = $params['raw_message'];
        error_log('QQ群推送: 使用raw_message字段: ' . $message);
    } elseif (isset($params['message']) && is_string($params['message'])) {
        // 字符串格式
        $message = $params['message'];
        error_log('QQ群推送: 使用message字符串字段: ' . $message);
    } elseif (isset($params['message']) && is_array($params['message'])) {
        // 数组格式（消息段）
        error_log('QQ群推送: 检测到数组格式消息: ' . json_encode($params['message'], JSON_UNESCAPED_UNICODE));
        $message_text = '';
        foreach ($params['message'] as $msg_segment) {
            if (isset($msg_segment['type']) && $msg_segment['type'] === 'text' && isset($msg_segment['data']['text'])) {
                $message_text .= $msg_segment['data']['text'];
            }
        }
        $message = $message_text;
        error_log('QQ群推送: 从数组提取的文本: ' . $message);
    }
    
    error_log('QQ群推送: 处理消息，内容="' . $message . '", QQ号=' . $qq_id);
    
    if (empty($message) || empty($qq_id)) {
        error_log('QQ群推送: 消息内容或QQ号为空');
        return rest_ensure_response(array('status' => 'error', 'reason' => 'missing_parameters'));
    }
    
    // 处理绑定命令
    if (preg_match('/^\s*\+\s*论坛绑定\s+(.+@.+)$/i', $message, $matches)) {
        error_log('QQ群推送: 检测到绑定命令');
        // 检查是否启用了账号绑定功能
        if (!qqpush_get_option('qq_group_bind_enable')) {
            error_log('QQ群推送: 账号绑定功能未启用');
            $reply_msg = "账号绑定功能未启用，请联系管理员";
            qqpush_send_at_msg_to_qq($group_id, $qq_id, $reply_msg);
            return rest_ensure_response(array('status' => 'error', 'reason' => 'bind_feature_disabled'));
        }
        
        $email = trim($matches[1]);
        error_log('QQ群推送: 尝试绑定邮箱: ' . $email);
        $result = qqpush_bind_user($qq_id, $email, $group_id, $sender_name);
        error_log('QQ群推送: 绑定结果: ' . json_encode($result));
        return rest_ensure_response($result);
    }
    
    // 处理解绑命令
    if (preg_match('/^\s*\+\s*论坛解绑\s*$/i', $message)) {
        error_log('QQ群推送: 检测到解绑命令');
        // 检查是否启用了账号绑定功能
        if (!qqpush_get_option('qq_group_bind_enable')) {
            error_log('QQ群推送: 账号绑定功能未启用');
            $reply_msg = "账号绑定功能未启用，请联系管理员";
            qqpush_send_at_msg_to_qq($group_id, $qq_id, $reply_msg);
            return rest_ensure_response(array('status' => 'error', 'reason' => 'bind_feature_disabled'));
        }
        
        error_log('QQ群推送: 尝试解绑QQ号: ' . $qq_id);
        $result = qqpush_unbind_user($qq_id, $group_id, $sender_name);
        error_log('QQ群推送: 解绑结果: ' . json_encode($result));
        return rest_ensure_response($result);
    }
    
    // 处理签到命令
    if (preg_match('/^\s*\+\s*论坛签到\s*$/i', $message)) {
        error_log('QQ群推送: 检测到签到命令');
        // 检查是否启用了群签到功能
        if (!qqpush_get_option('qq_group_checkin_enable')) {
            error_log('QQ群推送: 群签到功能未启用');
            $reply_msg = "群签到功能未启用，请联系管理员";
            qqpush_send_at_msg_to_qq($group_id, $qq_id, $reply_msg);
            return rest_ensure_response(array('status' => 'error', 'reason' => 'checkin_feature_disabled'));
        }
        
        // 检查子比主题的签到功能是否启用
        if (!function_exists('zib_user_checkin') || !function_exists('zib_user_is_checkined')) {
            error_log('QQ群推送: 子比主题签到函数未找到');
            $reply_msg = "网站签到功能未启用，请联系管理员";
            qqpush_send_at_msg_to_qq($group_id, $qq_id, $reply_msg);
            return rest_ensure_response(array('status' => 'error', 'reason' => 'site_checkin_disabled'));
        }
        
        if (!function_exists('_pz') || !_pz('checkin_s')) {
            error_log('QQ群推送: 子比主题签到功能未启用');
            $reply_msg = "网站签到功能未启用，请联系管理员";
            qqpush_send_at_msg_to_qq($group_id, $qq_id, $reply_msg);
            return rest_ensure_response(array('status' => 'error', 'reason' => 'site_checkin_disabled'));
        }
        
        error_log('QQ群推送: 开始执行签到');
        $result = qqpush_user_checkin($qq_id, $group_id, $sender_name);
        error_log('QQ群推送: 签到结果: ' . json_encode($result));
        return rest_ensure_response($result);
    }
    
    // 其他消息不处理
    error_log('QQ群推送: 未识别的命令: ' . $message);
    return rest_ensure_response(array('status' => 'ignored', 'reason' => 'command_not_recognized'));
}

/**
 * 绑定QQ用户与网站用户
 * 
 * @author 星野爱
 * @param string $qq_id QQ号
 * @param string $email 用户邮箱
 * @param string $group_id 群号（可选）
 * @param string $sender_name 发送者昵称（可选）
 * @return array 处理结果
 */
function qqpush_bind_user($qq_id, $email, $group_id = '', $sender_name = '') {
    error_log('QQ群推送: 开始绑定用户，QQ号=' . $qq_id . ', 邮箱=' . $email);
    
    // 查找对应邮箱的用户
    $user = get_user_by('email', $email);
    
    if (!$user) {
        error_log('QQ群推送: 未找到邮箱对应的用户');
        // 发送绑定失败消息
        $reply_msg = "绑定失败：未找到邮箱为 {$email} 的用户";
        
        // 如果提供了群号，则在群里回复
        if (!empty($group_id)) {
            qqpush_send_at_msg_to_qq($group_id, $qq_id, $reply_msg);
        }
        
        // 同时发送私信
        qqpush_send_private_msg_to_qq($qq_id, $reply_msg);
        
        return array('status' => 'error', 'message' => '未找到该邮箱对应的用户');
    }
    
    // 绑定QQ号到用户元数据
    $meta_key = 'qqpush_bound_qq_id';
    update_user_meta($user->ID, $meta_key, $qq_id);
    error_log('QQ群推送: 成功绑定用户，用户ID=' . $user->ID);
    
    // 获取用户显示名称
    $username = $user->display_name ?: $user->user_login;
    
    // 构建私信消息
    $reply_msg = "绑定成功！您的账号 {$username} 已成功与QQ号关联";
    
    // 如果提供了群号，则在群里回复
    if (!empty($group_id)) {
        // 构建群消息
        $group_msg = "绑定成功！您的账号 {$username} 已成功与QQ号关联";
        qqpush_send_at_msg_to_qq($group_id, $qq_id, $group_msg);
    }
    
    // 同时发送私信
    qqpush_send_private_msg_to_qq($qq_id, $reply_msg);
    
    return array(
        'status' => 'success',
        'message' => '绑定成功',
        'user_id' => $user->ID,
        'qq_id' => $qq_id
    );
}

/**
 * 通过QQ号执行用户签到
 * 
 * @author 星野爱
 * @param string $qq_id QQ号
 * @param string $group_id 群号（可选）
 * @param string $sender_name 发送者昵称（可选）
 * @return array 处理结果
 */
function qqpush_user_checkin($qq_id, $group_id = '', $sender_name = '') {
    global $wpdb;
    error_log('QQ群推送: 开始执行签到，QQ号=' . $qq_id);
    
    // 查找绑定了该QQ号的用户
    $user_id = $wpdb->get_var(
        $wpdb->prepare(
            "SELECT user_id FROM {$wpdb->usermeta} WHERE meta_key = 'qqpush_bound_qq_id' AND meta_value = %s LIMIT 1",
            $qq_id
        )
    );
    
    if (!$user_id) {
        error_log('QQ群推送: 未找到绑定的用户');
        // 发送未绑定消息
        $reply_msg = "签到失败：您的QQ号尚未绑定网站账号，请先发送「+ 论坛绑定 您的邮箱」进行绑定";
        
        // 如果提供了群号，则在群里回复
        if (!empty($group_id)) {
            qqpush_send_at_msg_to_qq($group_id, $qq_id, $reply_msg);
        }
        
        // 同时发送私信
        qqpush_send_private_msg_to_qq($qq_id, $reply_msg);
        
        return array('status' => 'error', 'message' => 'QQ号未绑定网站账号');
    }
    
    error_log('QQ群推送: 找到绑定的用户ID=' . $user_id);
    
    // 检查是否已经签到
    if (function_exists('zib_user_is_checkined') && zib_user_is_checkined($user_id)) {
        error_log('QQ群推送: 用户今日已签到');
        $user = get_user_by('id', $user_id);
        $username = $user->display_name ?: $user->user_login;
        
        // 构建私信消息
        $reply_msg = "{$username}，您今天已经签到过了哦！";
        
        // 如果提供了群号，则在群里回复
        if (!empty($group_id)) {
            // 构建不包含用户名的群消息
            $group_msg = "您今天已经签到过了哦！";
            qqpush_send_at_msg_to_qq($group_id, $qq_id, $group_msg);
        }
        
        // 同时发送私信
        qqpush_send_private_msg_to_qq($qq_id, $reply_msg);
        
        return array('status' => 'error', 'message' => '今日已签到');
    }
    
    // 执行签到
    error_log('QQ群推送: 准备获取签到奖励');
    if (!function_exists('zib_get_user_checkin_should_reward')) {
        error_log('QQ群推送: 函数zib_get_user_checkin_should_reward不存在');
        
        $reply_msg = "签到失败：系统错误，请稍后再试或到网站手动签到";
        
        // 如果提供了群号，则在群里回复
        if (!empty($group_id)) {
            qqpush_send_at_msg_to_qq($group_id, $qq_id, $reply_msg);
        }
        
        // 同时发送私信
        qqpush_send_private_msg_to_qq($qq_id, $reply_msg);
        
        return array('status' => 'error', 'message' => '签到函数不存在');
    }
    
    $reward = zib_get_user_checkin_should_reward($user_id);
    error_log('QQ群推送: 获取到奖励: ' . json_encode($reward));
    
    if (!function_exists('zib_user_checkin')) {
        error_log('QQ群推送: 函数zib_user_checkin不存在');
        
        $reply_msg = "签到失败：系统错误，请稍后再试或到网站手动签到";
        
        // 如果提供了群号，则在群里回复
        if (!empty($group_id)) {
            qqpush_send_at_msg_to_qq($group_id, $qq_id, $reply_msg);
        }
        
        // 同时发送私信
        qqpush_send_private_msg_to_qq($qq_id, $reply_msg);
        
        return array('status' => 'error', 'message' => '签到函数不存在');
    }
    
    $checkined = zib_user_checkin($user_id);
    error_log('QQ群推送: 签到结果: ' . json_encode($checkined));
    
    if (!$checkined) {
        error_log('QQ群推送: 签到失败');
        
        $reply_msg = "签到失败：系统错误，请稍后再试或到网站手动签到";
        
        // 如果提供了群号，则在群里回复
        if (!empty($group_id)) {
            qqpush_send_at_msg_to_qq($group_id, $qq_id, $reply_msg);
        }
        
        // 同时发送私信
        qqpush_send_private_msg_to_qq($qq_id, $reply_msg);
        
        return array('status' => 'error', 'message' => '签到失败');
    }
    
    // 获取用户信息
    $user = get_user_by('id', $user_id);
    $username = $user->display_name ?: $user->user_login;
    
    // 构建签到成功消息（用于私信）
    $msg = "{$username}，";
    $msg .= $reward['continuous_day'] > 1 ? "您已连续签到{$reward['continuous_day']}天！" : "签到成功！";
    $msg .= $checkined['points'] ? " 积分+{$checkined['points']}" : "";
    $msg .= $checkined['integral'] ? " 经验值+{$checkined['integral']}" : "";
    
    error_log('QQ群推送: 签到成功，发送消息: ' . $msg);
    
    // 如果提供了群号，则在群里回复
    if (!empty($group_id)) {
        // 构建不包含用户名的群消息（因为@已经显示用户名）
        $group_msg = $reward['continuous_day'] > 1 ? "您已连续签到{$reward['continuous_day']}天！" : "签到成功！";
        $group_msg .= $checkined['points'] ? " 积分+{$checkined['points']}" : "";
        $group_msg .= $checkined['integral'] ? " 经验值+{$checkined['integral']}" : "";
        qqpush_send_at_msg_to_qq($group_id, $qq_id, $group_msg);
    }
    
    // 同时发送私信
    qqpush_send_private_msg_to_qq($qq_id, $msg);
    
    return array(
        'status' => 'success',
        'message' => '签到成功',
        'user_id' => $user_id,
        'qq_id' => $qq_id,
        'reward' => $checkined
    );
}

/**
 * 解除QQ用户与网站用户的绑定
 * 
 * @author 星野爱
 * @param string $qq_id QQ号
 * @param string $group_id 群号（可选）
 * @param string $sender_name 发送者昵称（可选）
 * @return array 处理结果
 */
function qqpush_unbind_user($qq_id, $group_id = '', $sender_name = '') {
    global $wpdb;
    error_log('QQ群推送: 开始解绑用户，QQ号=' . $qq_id);
    
    // 查找绑定了该QQ号的用户
    $user_id = $wpdb->get_var(
        $wpdb->prepare(
            "SELECT user_id FROM {$wpdb->usermeta} WHERE meta_key = 'qqpush_bound_qq_id' AND meta_value = %s LIMIT 1",
            $qq_id
        )
    );
    
    if (!$user_id) {
        error_log('QQ群推送: 未找到绑定的用户');
        // 发送未绑定消息
        $reply_msg = "解绑失败：您的QQ号尚未绑定任何网站账号";
        
        // 如果提供了群号，则在群里回复
        if (!empty($group_id)) {
            qqpush_send_at_msg_to_qq($group_id, $qq_id, $reply_msg);
        }
        
        // 同时发送私信
        qqpush_send_private_msg_to_qq($qq_id, $reply_msg);
        
        return array('status' => 'error', 'message' => 'QQ号未绑定网站账号');
    }
    
    error_log('QQ群推送: 找到绑定的用户ID=' . $user_id);
    
    // 获取用户信息
    $user = get_user_by('id', $user_id);
    $username = $user->display_name ?: $user->user_login;
    
    // 删除绑定关系
    $meta_key = 'qqpush_bound_qq_id';
    $deleted = delete_user_meta($user_id, $meta_key);
    
    if (!$deleted) {
        error_log('QQ群推送: 解绑失败，无法删除元数据');
        $reply_msg = "解绑失败：系统错误，请联系管理员";
        
        // 如果提供了群号，则在群里回复
        if (!empty($group_id)) {
            qqpush_send_at_msg_to_qq($group_id, $qq_id, $reply_msg);
        }
        
        // 同时发送私信
        qqpush_send_private_msg_to_qq($qq_id, $reply_msg);
        
        return array('status' => 'error', 'message' => '解绑失败');
    }
    
    error_log('QQ群推送: 成功解绑用户，用户ID=' . $user_id);
    
    // 构建私信消息
    $reply_msg = "解绑成功！您的QQ号已与账号 {$username} 解除关联";
    
    // 如果提供了群号，则在群里回复
    if (!empty($group_id)) {
        // 构建群消息
        $group_msg = "解绑成功！您的QQ号已与账号 {$username} 解除关联";
        qqpush_send_at_msg_to_qq($group_id, $qq_id, $group_msg);
    }
    
    // 同时发送私信
    qqpush_send_private_msg_to_qq($qq_id, $reply_msg);
    
    return array(
        'status' => 'success',
        'message' => '解绑成功',
        'user_id' => $user_id,
        'qq_id' => $qq_id
    );
}
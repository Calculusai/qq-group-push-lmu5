<?php
/**
 * QQ群推送插件 - 扩展功能
 * 
 * @package QQGroupPush
 * @author 星野爱 (https://hoshinoai.xin)
 * @version 0.0.4
 * 
 * 扩展功能列表:
 * - 最新帖子查询功能
 * - 积分转账功能
 */

// 防止直接访问
if (!defined('ABSPATH')) {
    exit;
}

/**
 * 注册扩展功能的钩子
 */
function qqpush_extend_init() {
    // 添加最新帖子命令处理到消息处理流程中
    add_filter('qqpush_handle_message_command', 'qqpush_extend_handle_latest_posts_command', 10, 4);
    
    // 添加积分转账命令处理到消息处理流程中
    add_filter('qqpush_handle_message_command', 'qqpush_extend_handle_points_transfer_command', 10, 4);
}
add_action('plugins_loaded', 'qqpush_extend_init');

/**
 * 处理最新帖子命令
 * 
 * @param bool|array $handled 之前是否已处理
 * @param string $message 消息内容
 * @param string $qq_id QQ号
 * @param string $group_id 群号
 * @return bool|array 处理结果
 */
function qqpush_extend_handle_latest_posts_command($handled, $message, $qq_id, $group_id) {
    // 如果消息已被处理，直接返回结果
    if ($handled !== false) {
        return $handled;
    }
    
    // 处理最新帖子命令
    if (preg_match('/^\s*\+\s*最新帖子\s*$/i', $message)) {
        error_log('QQ群推送扩展: 检测到最新帖子命令');
        
        // 检查是否启用了最新帖子功能
        if (!qqpush_get_option('qq_group_latest_posts_enable')) {
            error_log('QQ群推送扩展: 最新帖子功能未启用');
            $reply_msg = "最新帖子功能未启用，请联系管理员";
            qqpush_send_at_msg_to_qq($group_id, $qq_id, $reply_msg);
            return array('status' => 'error', 'reason' => 'latest_posts_feature_disabled');
        }
        
        return qqpush_get_latest_posts($qq_id, $group_id);
    }
    
    // 未处理则返回false
    return false;
}

/**
 * 处理积分转账命令
 * 
 * @param bool|array $handled 之前是否已处理
 * @param string $message 消息内容
 * @param string $qq_id 发送者QQ号
 * @param string $group_id 群号
 * @param array $raw_msg 原始消息数据（包含CQ码）
 * @return bool|array 处理结果
 */
function qqpush_extend_handle_points_transfer_command($handled, $message, $qq_id, $group_id, $raw_msg = null) {
    // 如果消息已被处理，直接返回结果
    if ($handled !== false) {
        return $handled;
    }
    
    // 处理积分转账命令
    // 格式：+ 积分转账 @用户 100
    if (preg_match('/^\s*\+\s*积分转账\s+/i', $message)) {
        error_log('QQ群推送扩展: 检测到积分转账命令: ' . $message);
        
        // 检查是否启用了积分转账功能
        if (!qqpush_get_option('qq_group_points_transfer_enable')) {
            error_log('QQ群推送扩展: 积分转账功能未启用');
            $reply_msg = "积分转账功能未启用，请联系管理员";
            qqpush_send_at_msg_to_qq($group_id, $qq_id, $reply_msg);
            return array('status' => 'error', 'reason' => 'points_transfer_feature_disabled');
        }
        
        // 检查子比主题积分功能是否可用
        if (!function_exists('zibpay_update_user_points') || !function_exists('zibpay_get_user_points')) {
            error_log('QQ群推送扩展: 子比主题积分函数未找到');
            $reply_msg = "积分系统不可用，请联系管理员";
            qqpush_send_at_msg_to_qq($group_id, $qq_id, $reply_msg);
            return array('status' => 'error', 'reason' => 'points_system_unavailable');
        }
        
        // 解析@用户和积分数量
        // 提取CQ码中的QQ号和积分数量
        // 先检查消息中是否有CQ码
        if (strpos($message, '[CQ:at,qq=') !== false) {
            // 解析@的QQ号
            preg_match('/\[CQ:at,qq=(\d+)\]/i', $message, $at_matches);
            if (empty($at_matches[1])) {
                error_log('QQ群推送扩展: 未找到@用户的QQ号');
                $reply_msg = "格式错误，正确格式：+ 积分转账 @用户 积分数量";
                qqpush_send_at_msg_to_qq($group_id, $qq_id, $reply_msg);
                return array('status' => 'error', 'reason' => 'invalid_format');
            }
            
            $target_qq_id = $at_matches[1];
            
            // 解析积分数量，应该在@用户之后
            $message_parts = explode(']', $message);
            if (count($message_parts) < 2) {
                error_log('QQ群推送扩展: 未找到积分数量');
                $reply_msg = "格式错误，正确格式：+ 积分转账 @用户 积分数量";
                qqpush_send_at_msg_to_qq($group_id, $qq_id, $reply_msg);
                return array('status' => 'error', 'reason' => 'invalid_format');
            }
            
            // 获取最后一部分，应该包含积分数量
            $last_part = end($message_parts);
            preg_match('/\s*(\d+)\s*$/i', $last_part, $points_matches);
            
            if (empty($points_matches[1])) {
                error_log('QQ群推送扩展: 未找到有效的积分数量');
                $reply_msg = "格式错误，正确格式：+ 积分转账 @用户 积分数量";
                qqpush_send_at_msg_to_qq($group_id, $qq_id, $reply_msg);
                return array('status' => 'error', 'reason' => 'invalid_format');
            }
            
            $points = intval($points_matches[1]);
            
            // 执行积分转账
            return qqpush_transfer_points($qq_id, $target_qq_id, $points, $group_id);
        } else {
            error_log('QQ群推送扩展: 未找到@用户');
            $reply_msg = "格式错误，正确格式：+ 积分转账 @用户 积分数量";
            qqpush_send_at_msg_to_qq($group_id, $qq_id, $reply_msg);
            return array('status' => 'error', 'reason' => 'invalid_format');
        }
    }
    
    // 未处理则返回false
    return false;
}

/**
 * 获取最新帖子并发送到QQ群
 * 
 * @param string $qq_id QQ号
 * @param string $group_id 群号
 * @return array 处理结果
 */
function qqpush_get_latest_posts($qq_id, $group_id) {
    error_log('QQ群推送扩展: 开始查询最新帖子');
    
    // 查询参数
    $args = array(
        'post_type'      => 'forum_post',  // 子比主题论坛帖子类型
        'post_status'    => 'publish',
        'posts_per_page' => 5,             // 获取5篇最新帖子
        'orderby'        => 'date',
        'order'          => 'DESC'
    );
    
    // 执行查询
    $query = new WP_Query($args);
    
    if (!$query->have_posts()) {
        error_log('QQ群推送扩展: 未找到帖子');
        // 构建回复消息
        $reply_msg = "抱歉，暂时没有找到任何论坛帖子";
        
        // 发送回复
        qqpush_send_at_msg_to_qq($group_id, $qq_id, $reply_msg);
        
        return array(
            'status' => 'success', 
            'message' => '没有找到帖子'
        );
    }
    
    // 构建帖子列表消息
    $posts_message = "【最新论坛帖子】\n\n";
    $count = 1;
    
    while ($query->have_posts()) {
        $query->the_post();
        
        // 获取帖子信息
        $post_id = get_the_ID();
        $post_title = get_the_title();
        $post_url = get_permalink();
        $post_date = get_the_date('Y-m-d H:i');
        $author_id = get_post_field('post_author', $post_id);
        $author_name = get_the_author_meta('display_name', $author_id);
        
        // 获取帖子所属板块
        $plate_name = '未分类';
        $plate_id = get_post_meta($post_id, 'plate_id', true);
        if ($plate_id) {
            $plate = get_post($plate_id);
            if ($plate) {
                $plate_name = $plate->post_title;
            }
        }
        
        // 获取回复数量（子比主题特有）
        $reply_count = get_post_meta($post_id, 'comment_count', true) ?: '0';
        
        // 添加到消息中
        $posts_message .= "{$count}. 【{$plate_name}】{$post_title}\n";
        $posts_message .= "   作者：{$author_name} | 时间：{$post_date} | 回复：{$reply_count}\n";
        $posts_message .= "   {$post_url}\n\n";
        
        $count++;
    }
    
    // 恢复全局帖子数据
    wp_reset_postdata();
    
    // 添加查看更多链接
    $forum_url = home_url('/plate'); // 子比主题论坛首页
    $posts_message .= "查看更多：{$forum_url}";
    
    error_log('QQ群推送扩展: 发送最新帖子列表');
    
    // 发送到QQ群
    qqpush_send_group_msg_to_qq($group_id, $posts_message);
    
    return array(
        'status' => 'success',
        'message' => '已发送最新帖子列表',
        'count'   => $count - 1
    );
}

/**
 * 执行积分转账
 * 
 * @param string $from_qq_id 转出方QQ号
 * @param string $to_qq_id 接收方QQ号
 * @param int $points 积分数量
 * @param string $group_id 群号
 * @return array 处理结果
 */
function qqpush_transfer_points($from_qq_id, $to_qq_id, $points, $group_id) {
    global $wpdb;
    error_log('QQ群推送扩展: 开始执行积分转账，从 ' . $from_qq_id . ' 到 ' . $to_qq_id . '，金额：' . $points);
    
    // 检查积分数量是否合法
    if ($points <= 0 || $points > 10000) {
        error_log('QQ群推送扩展: 积分数量不合法: ' . $points);
        $reply_msg = "积分数量必须为正数且不能超过10000";
        qqpush_send_at_msg_to_qq($group_id, $from_qq_id, $reply_msg);
        return array('status' => 'error', 'reason' => 'invalid_points_amount');
    }
    
    // 不能给自己转账
    if ($from_qq_id == $to_qq_id) {
        error_log('QQ群推送扩展: 不能给自己转账');
        $reply_msg = "不能给自己转账";
        qqpush_send_at_msg_to_qq($group_id, $from_qq_id, $reply_msg);
        return array('status' => 'error', 'reason' => 'self_transfer');
    }
    
    // 查找转出方绑定的用户
    $from_user_id = $wpdb->get_var(
        $wpdb->prepare(
            "SELECT user_id FROM {$wpdb->usermeta} WHERE meta_key = 'qqpush_bound_qq_id' AND meta_value = %s LIMIT 1",
            $from_qq_id
        )
    );
    
    if (!$from_user_id) {
        error_log('QQ群推送扩展: 转出方未绑定账号');
        $reply_msg = "您尚未绑定网站账号，请先发送「+ 论坛绑定 您的邮箱」进行绑定";
        qqpush_send_at_msg_to_qq($group_id, $from_qq_id, $reply_msg);
        return array('status' => 'error', 'reason' => 'sender_not_bound');
    }
    
    // 查找接收方绑定的用户
    $to_user_id = $wpdb->get_var(
        $wpdb->prepare(
            "SELECT user_id FROM {$wpdb->usermeta} WHERE meta_key = 'qqpush_bound_qq_id' AND meta_value = %s LIMIT 1",
            $to_qq_id
        )
    );
    
    if (!$to_user_id) {
        error_log('QQ群推送扩展: 接收方未绑定账号');
        $reply_msg = "对方尚未绑定网站账号，无法转账";
        qqpush_send_at_msg_to_qq($group_id, $from_qq_id, $reply_msg);
        return array('status' => 'error', 'reason' => 'receiver_not_bound');
    }
    
    // 获取转出方的积分余额
    $from_points = zibpay_get_user_points($from_user_id);
    error_log('QQ群推送扩展: 转出方积分余额: ' . $from_points);
    
    // 检查积分余额是否足够
    if ($from_points < $points) {
        error_log('QQ群推送扩展: 积分余额不足');
        $reply_msg = "您的积分余额不足，当前余额：{$from_points}";
        qqpush_send_at_msg_to_qq($group_id, $from_qq_id, $reply_msg);
        return array('status' => 'error', 'reason' => 'insufficient_points');
    }
    
    // 获取用户信息
    $from_user = get_user_by('id', $from_user_id);
    $to_user = get_user_by('id', $to_user_id);
    $from_username = $from_user->display_name ?: $from_user->user_login;
    $to_username = $to_user->display_name ?: $to_user->user_login;
    
    // 执行积分扣除
    $transfer_order = 'transfer_' . time() . rand(100, 999);
    $data_from = array(
        'order_num' => $transfer_order, // 订单号
        'value'     => -$points, // 负值表示减少积分
        'type'      => '积分转账', // 类型
        'desc'      => '转账给用户 ' . $to_username // 说明
    );
    $result_from = zibpay_update_user_points($from_user_id, $data_from);
    
    if (!$result_from) {
        error_log('QQ群推送扩展: 扣除积分失败');
        $reply_msg = "转账失败，扣除积分时出错";
        qqpush_send_at_msg_to_qq($group_id, $from_qq_id, $reply_msg);
        return array('status' => 'error', 'reason' => 'deduction_failed');
    }
    
    // 执行积分增加
    $data_to = array(
        'order_num' => $transfer_order, // 订单号
        'value'     => $points, // 正值表示增加积分
        'type'      => '积分转账', // 类型
        'desc'      => '收到用户 ' . $from_username . ' 的转账' // 说明
    );
    $result_to = zibpay_update_user_points($to_user_id, $data_to);
    
    if (!$result_to) {
        error_log('QQ群推送扩展: 增加积分失败，尝试回滚');
        // 尝试回滚
        $rollback_data = array(
            'order_num' => $transfer_order . '_rollback', // 订单号
            'value'     => $points, // 正值表示增加积分
            'type'      => '积分转账', // 类型
            'desc'      => '转账失败回滚' // 说明
        );
        zibpay_update_user_points($from_user_id, $rollback_data);
        
        $reply_msg = "转账失败，增加对方积分时出错";
        qqpush_send_at_msg_to_qq($group_id, $from_qq_id, $reply_msg);
        return array('status' => 'error', 'reason' => 'addition_failed');
    }
    
    // 转账成功，向群内发送消息
    $success_msg = "转账成功！\n{$from_username} 向 {$to_username} 转账 {$points} 积分";
    qqpush_send_group_msg_to_qq($group_id, $success_msg);
    
    // 向接收方发送私信通知
    $to_msg = "您收到一笔积分转账！\n发送者：{$from_username}\n金额：{$points} 积分\n时间：" . date('Y-m-d H:i:s');
    qqpush_send_private_msg_to_qq($to_qq_id, $to_msg);
    
    return array(
        'status' => 'success',
        'message' => '转账成功',
        'from_user_id' => $from_user_id,
        'to_user_id' => $to_user_id,
        'points' => $points
    );
} 
<?php
/**
 * QQ群推送插件 - 后台管理界面
 * 
 * @package QQGroupPush
 * @author 落幕
 * @contributor 星野爱 (子比主题适配、论坛绑定、签到、解绑功能)
 * @version 0.0.4
 * 
 * 原作者：落幕（基础推送功能）
 * 子比主题适配及扩展功能：星野爱
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
 * 创建基于CSF框架的后台设置页面
 */
function qqpush_create_csf_options() {
    // 设置选项前缀
    $prefix = 'qqpush_options';
    
    // 创建主设置页面
    CSF::createOptions($prefix, array(
        'menu_title'      => '子比主题-Q群/QQ推送',
        'menu_slug'       => 'qqpush-settings',
        'menu_icon'       => 'dashicons-format-chat',
        'menu_position'   => 30,
        'framework_title' => '子比主题-Q群/QQ推送设置 <small>v0.0.4</small>',
        'footer_text'     => '感谢使用Q群/QQ推送插件',
        'footer_after'    => '<p>插件详细说明请访问：<a href="https://hoshinoai.xin" target="_blank">星野爱</a></p>',
        'theme'           => 'light',
        'class'           => 'qqpush-admin-options',
    ));
    
    // 创建插件介绍区域
    CSF::createSection($prefix, array(
        'title'  => '插件介绍',
        'icon'   => 'fas fa-info-circle',
        'fields' => array(
            array(
                'type'    => 'submessage',
                'style'   => 'info',
                'content' => '<h3>🎉 欢迎使用Q群/QQ推送插件</h3>
                             <p>本插件可以在WordPress文章发布、更新或收到评论时，自动向指定的QQ群发送通知消息。</p>
                             <p><strong>主要功能：</strong></p>
                             <ul>
                                 <li>✅ 文章发布时自动推送到QQ群</li>
                                 <li>✅ 文章更新时推送通知（可选）</li>
                                 <li>✅ 新评论时发送私信提醒</li>
                                 <li>✅ 支持多个QQ群同时推送</li>
                                 <li>✅ 支持@全体成员功能</li>
                                 <li>✅ 自动提取文章特色图片</li>
                                 <li>✅ 支持napcat/ntqq协议</li>
                                 <li>✅ 支持QQ群消息触发论坛签到</li>
                                 <li>✅ 支持QQ号与网站账号绑定</li>
                             </ul>',
            ),
        )
    ));
    
    // 创建基本设置区域
    CSF::createSection($prefix, array(
        'title'  => '基本设置',
        'icon'   => 'fas fa-cog',
        'fields' => array(
            array(
                'id'          => 'qq_group_push_url',
                'type'        => 'text',
                'title'       => 'napcat/ntqq HTTP地址',
                'placeholder' => 'http://www.example.com:8080',
                'desc'        => '填写napcat/ntqq的HTTP(http服务器端) API地址，例如：http://127.0.0.1:8080',
                'validate'    => 'qqpush_validate_url',
            ),
            array(
                'id'          => 'qq_group_numbers',
                'type'        => 'text',
                'title'       => 'QQ群号',
                'placeholder' => '123456789,987654321',
                'desc'        => '填写要推送的QQ群号，多个群号用英文逗号(,)分隔',
            ),
            array(
                'id'          => 'qq_group_push_access_token',
                'type'        => 'text',
                'title'       => 'Access Token',
                'placeholder' => '请输入访问令牌',
                'desc'        => '如果napcat或ntqq设置了Access Token，请在此输入（可选）',
            ),
        )
    ));
    
    // 创建推送设置区域
    CSF::createSection($prefix, array(
        'title'  => '推送设置',
        'icon'   => 'fas fa-paper-plane',
        'fields' => array(
            array(
                'id'      => 'qq_group_push_enable_new_post_notice',
                'type'    => 'switcher',
                'title'   => '新文章推送',
                'desc'    => '开启后，发布新文章时会发送推送通知',
                'default' => true,
            ),
            array(
                'id'      => 'qq_group_push_enable_update_notice',
                'type'    => 'switcher',
                'title'   => '文章更新推送',
                'desc'    => '开启后，文章更新时也会发送推送通知',
                'default' => false,
            ),
            array(
                'id'      => 'qq_group_push_enable_article_comment_update_notice',
                'type'    => 'switcher',
                'title'   => '文章评论推送',
                'desc'    => '开启后，有新评论时会发送私信通知',
                'default' => false,
            ),
            array(
                'id'      => 'qq_group_push_enable_at_all',
                'type'    => 'switcher',
                'title'   => '@全体成员',
                'desc'    => '开启后，推送消息时会@全体成员',
                'default' => false,
            ),
            array(
                'id'          => 'qq_group_push_comment_notice_qq',
                'type'        => 'text',
                'title'       => '评论通知QQ号',
                'placeholder' => '请输入QQ号码',
                'desc'        => '填写接收新评论私信通知的QQ号码',
                'dependency'  => array('qq_group_push_enable_article_comment_update_notice', '==', true),
            ),
        )
    ));
    
    // 创建QQ群互动设置区域
    CSF::createSection($prefix, array(
        'title'  => 'QQ群互动',
        'icon'   => 'fas fa-exchange-alt',
        'fields' => array(
            array(
                'type'    => 'submessage',
                'style'   => 'success',
                'content' => '<p><strong>QQ群互动功能由<a href="https://hoshinoai.xin" target="_blank">星野爱</a>开发</strong></p>
                             <p>此功能支持通过QQ群消息触发网站操作，如论坛签到、账号绑定等</p>',
            ),
            array(
                'id'      => 'qq_group_interaction_enable',
                'type'    => 'switcher',
                'title'   => '启用QQ群互动',
                'desc'    => '开启后，可以通过QQ群消息触发网站操作，如论坛签到',
                'default' => false,
            ),
            array(
                'id'      => 'qq_group_checkin_enable',
                'type'    => 'switcher',
                'title'   => '启用群签到功能',
                'desc'    => '开启后，用户可以通过在QQ群中发送"+ 论坛签到"来完成网站签到',
                'default' => false,
                'dependency' => array('qq_group_interaction_enable', '==', true),
            ),
            array(
                'id'      => 'qq_group_bind_enable',
                'type'    => 'switcher',
                'title'   => '启用账号绑定功能',
                'desc'    => '开启后，用户可以通过在QQ群中发送"+ 论坛绑定 邮箱"来绑定QQ号与网站账号，同时可以发送"+ 论坛解绑"来解绑',
                'default' => false,
                'dependency' => array('qq_group_interaction_enable', '==', true),
            ),
            array(
                'id'      => 'qq_group_latest_posts_enable',
                'type'    => 'switcher',
                'title'   => '启用最新帖子功能',
                'desc'    => '开启后，用户可以通过在QQ群中发送"+ 最新帖子"来查看网站最新的5个论坛帖子',
                'default' => false,
                'dependency' => array('qq_group_interaction_enable', '==', true),
            ),
            array(
                'id'      => 'qq_group_points_transfer_enable',
                'type'    => 'switcher',
                'title'   => '启用积分转账功能',
                'desc'    => '开启后，用户可以通过在QQ群中发送"+ 积分转账 @用户 数量"来向其他用户转账积分',
                'default' => false,
                'dependency' => array('qq_group_interaction_enable', '==', true),
            ),
            array(
                'type'    => 'submessage',
                'style'   => 'info',
                'content' => '<p><strong>使用说明：</strong></p>
                             <p>1. 用户需要先绑定账号：在QQ群中发送 "+ 论坛绑定 用户邮箱"</p>
                             <p>2. 解绑账号：在QQ群中发送 "+ 论坛解绑"</p>
                             <p>3. 绑定成功后，用户可以发送 "+ 论坛签到" 进行签到</p>
                             <p>4. 查看最新帖子：在QQ群中发送 "+ 最新帖子"</p>
                             <p>5. 积分转账：在QQ群中发送 "+ 积分转账 @用户 100"</p>
                             <p>6. 确保您的QQ机器人已正确配置，能接收并转发群消息到本插件</p>
                             <p>7. 接收消息的API地址为：<code>' . home_url('/wp-json/qqpush/v1/receive') . '</code></p>',
                'dependency' => array('qq_group_interaction_enable', '==', true),
            ),
        )
    ));
    
    // 创建高级设置区域
    CSF::createSection($prefix, array(
        'title'  => '高级设置',
        'icon'   => 'fas fa-tools',
        'fields' => array(
            array(
                'id'      => 'qq_group_push_timeout',
                'type'    => 'number',
                'title'   => '请求超时时间',
                'desc'    => '设置HTTP请求的超时时间（秒）',
                'default' => 15,
                'min'     => 5,
                'max'     => 60,
                'unit'    => '秒',
            ),
            array(
                'id'      => 'qq_group_push_debug_mode',
                'type'    => 'switcher',
                'title'   => '调试模式',
                'desc'    => '开启后会在WordPress日志中记录推送详情',
                'default' => false,
            ),
        )
    ));
    
    // 创建测试功能区域
    CSF::createSection($prefix, array(
        'title'  => '测试功能',
        'icon'   => 'fas fa-vial',
        'fields' => array(
            array(
                'type'    => 'submessage',
                'style'   => 'warning',
                'content' => '<h4>🧪 测试推送功能</h4>
                             <p>在这里可以测试推送功能是否正常工作。</p>
                             <button type="button" class="button button-primary" onclick="qqpushTestMessage()">发送测试消息</button>
                             <div id="qqpush-test-result" style="margin-top: 10px;"></div>
                             <script>
                             function qqpushTestMessage() {
                                 document.getElementById("qqpush-test-result").innerHTML = "<p style=\"color: #0073aa;\">测试功能开发中...</p>";
                             }
                             </script>',
            ),
            array(
                'type'    => 'submessage',
                'style'   => 'info',
                'content' => '<h4>🔍 API调试工具</h4>
                             <p>使用以下命令可以测试API是否正常工作：</p>
                             <pre style="background:#f5f5f5;padding:10px;overflow:auto;">curl -X POST -H "Content-Type: application/json" -d \'{"message_type":"group","group_id":"您的群号","user_id":"您的QQ号","message":"+ 论坛签到"}\' ' . home_url('/wp-json/qqpush/v1/receive') . '</pre>
                             <p>请将"您的群号"和"您的QQ号"替换为实际值。</p>
                             <p>如果您启用了日志记录，可以在WordPress错误日志中查看详细信息。</p>',
            ),
        )
    ));
    
    // 创建关于插件区域
    CSF::createSection($prefix, array(
        'title'  => '关于插件',
        'icon'   => 'fas fa-heart',
        'fields' => array(
            array(
                'type'    => 'content',
                'content' => '<div style="text-align: center; padding: 20px;">
                                 <h3>📱 Q群/QQ推送插件</h3>
                                 <p><strong>版本：</strong>v0.0.4</p>
                                 <p><strong>作者：</strong>落幕</p>
                                 <p><strong>贡献者：</strong>星野爱 (<a href="https://hoshinoai.xin" target="_blank">https://hoshinoai.xin</a>)</p>
                                 <p><strong>功能贡献：</strong>子比主题适配、论坛绑定、签到、解绑功能</p>
                                 <p><strong>官网：</strong><a href="https://lmu5.com" target="_blank">https://lmu5.com</a></p>
                                 <p><strong>插件详情：</strong><a href="https://lmu5.com/qqpush.html" target="_blank">查看详细说明</a></p>
                                 <p><strong>技术支持：</strong>QQ群 420035660</p>
                                 <p style="color: #666;">感谢您使用本插件！如果觉得好用，请给个好评支持一下 ❤️</p>
                             </div>',
            ),
        )
    ));
}
qqpush_create_csf_options();
/**
 * URL验证函数
 */
function qqpush_validate_url($value) {
    if (!empty($value) && !filter_var($value, FILTER_VALIDATE_URL)) {
        return '请输入有效的URL地址';
    }
    return '';
}
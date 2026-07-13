<?php
defined('ABSPATH') || exit;

final class BTL_Ticket_Admin
{
    public static function boot(): void
    {
        add_action('add_meta_boxes', [self::class, 'register_meta_box']);
        add_action('wp_ajax_btl_ticket_reply', [self::class, 'ajax_reply']);
        add_action('admin_footer-post.php', [self::class, 'inline_admin_script']);
    }

    public static function register_meta_box(): void
    {
        add_meta_box('btl_ticket_thread', 'گفتگوی تیکت', [self::class, 'render'], 'support_ticket', 'normal', 'high');
    }

    public static function render($post): void
    {
        if (!current_user_can('manage_woocommerce')) return;

        $replies = BTL_Ticket_Replies::forTicket($post->ID);
        $nonce = wp_create_nonce('btl_ticket_reply_' . $post->ID);

        echo '<div class="btl-ticket-thread" style="display:flex;flex-direction:column;gap:10px;max-height:400px;overflow-y:auto;margin-bottom:12px;">';
        foreach ($replies as $r) {
            $author = get_userdata((int)$r['author_id']);
            $isStaff = $r['author_role'] === 'staff';
            echo '<div style="padding:10px;border-radius:4px;background:' . ($isStaff ? '#e7f0fd' : '#f6f7f7') . ';border:1px solid #ccd0d4;">';
            echo '<strong>' . esc_html($author ? $author->display_name : 'کاربر') . '</strong> ';
            echo '<span style="color:#777;font-size:11px;">' . esc_html($r['created_at']) . '</span>';
            echo '<p style="margin:6px 0 0;">' . wp_kses_post($r['content']) . '</p>';
            echo '</div>';
        }
        if (!$replies) {
            echo '<p style="color:#777;">هنوز پاسخی ثبت نشده است.</p>';
        }
        echo '</div>';

        echo '<textarea class="btl-ticket-reply-input" rows="4" style="width:100%;" placeholder="پاسخ خود را بنویسید..."></textarea>';
        echo '<p><button type="button" class="button button-primary btl-ticket-reply-save" data-ticket="' . esc_attr($post->ID) . '" data-nonce="' . esc_attr($nonce) . '">ارسال پاسخ</button></p>';
    }

    public static function ajax_reply(): void
    {
        if (!current_user_can('manage_woocommerce')) wp_send_json_error('دسترسی غیرمجاز', 403);

        $ticketId = absint($_POST['ticket_id'] ?? 0);
        $content = wp_kses_post(trim($_POST['content'] ?? ''));

        if (!$ticketId || $content === '') wp_send_json_error('ورودی نامعتبر', 400);
        if (!wp_verify_nonce($_POST['nonce'] ?? '', 'btl_ticket_reply_' . $ticketId)) wp_send_json_error('نشست نامعتبر', 403);

        BTL_Ticket_Replies::add($ticketId, get_current_user_id(), 'staff', $content);
        update_post_meta($ticketId, 'ticket_status', 'answered');

        $ownerId = (int)get_post_meta($ticketId, 'customer_id', true);
        if ($ownerId) {
            BTL_Notifications::push($ownerId, 'پاسخ جدید در تیکت شما', 'تیکت «' . get_the_title($ticketId) . '» پاسخ داده شد.', '/my-account/tickets/' . $ticketId);
        }

        wp_send_json_success(['message' => 'پاسخ ثبت شد.']);
    }

    public static function inline_admin_script(): void
    {
        global $post;
        if (!$post || $post->post_type !== 'support_ticket') return;
        $ajaxUrl = admin_url('admin-ajax.php');
        ?>
        <script>
        jQuery(function ($) {
            $(document).on('click', '.btl-ticket-reply-save', function () {
                var btn = $(this);
                var content = $('.btl-ticket-reply-input').val();
                if (!content.trim()) return;
                $.post('<?php echo esc_js($ajaxUrl); ?>', {
                    action: 'btl_ticket_reply',
                    ticket_id: btn.data('ticket'),
                    nonce: btn.data('nonce'),
                    content: content
                }, function (res) {
                    if (res.success) location.reload();
                    else alert(res.data);
                });
            });
        });
        </script>
        <?php
    }
}
<?php

defined('ABSPATH') || exit;

final class BTL_Email_Gateway
{
    public static function sendPasswordResetCode(string $toEmail, string $displayName, string $code): bool
    {
        $siteName = get_bloginfo('name') ?: 'Arena2Battle';
        $subject = "کد بازیابی رمز عبور {$siteName}";

        $safeName = esc_html($displayName ?: 'کاربر گرامی');
        $safeCode = esc_html($code);
        $safeSite = esc_html($siteName);

        $body = self::renderResetTemplate($safeName, $safeCode, $safeSite);

        $headers = ['Content-Type: text/html; charset=UTF-8'];

        $sent = wp_mail($toEmail, $subject, $body, $headers);

        if (!$sent) {
            BTL_Helpers::logger("EmailGateway: failed to send password reset email to {$toEmail}");
        }

        return (bool) $sent;
    }

    private static function renderResetTemplate(string $name, string $code, string $siteName): string
    {
        return <<<HTML
<!DOCTYPE html>
<html dir="rtl" lang="fa">
<head><meta charset="UTF-8"></head>
<body style="margin:0;padding:0;background-color:#15171e;font-family:Tahoma,Arial,sans-serif;">
  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:#15171e;padding:32px 0;">
    <tr>
      <td align="center">
        <table role="presentation" width="480" cellpadding="0" cellspacing="0" style="background-color:#23252b;border-radius:8px;overflow:hidden;">
          <tr>
            <td style="padding:28px 32px;text-align:center;border-bottom:1px solid #313339;">
              <span style="color:#0074e0;font-size:20px;font-weight:900;">{$siteName}</span>
            </td>
          </tr>
          <tr>
            <td style="padding:32px;text-align:right;color:#c2c2c4;font-size:14px;line-height:1.9;">
              <p style="margin:0 0 16px;">{$name}،</p>
              <p style="margin:0 0 24px;">درخواست بازیابی رمز عبور برای حساب کاربری شما ثبت شد. از کد زیر برای تنظیم رمز عبور جدید استفاده کنید:</p>
              <div style="text-align:center;margin:24px 0;">
                <span style="display:inline-block;background-color:#15171e;color:#ffffff;font-size:28px;font-weight:900;letter-spacing:6px;padding:16px 32px;border-radius:6px;direction:ltr;">{$code}</span>
              </div>
              <p style="margin:24px 0 0;color:#88898c;font-size:12px;">این کد تا ۲ دقیقه دیگر معتبر است. اگر شما این درخواست را ثبت نکرده‌اید، این ایمیل را نادیده بگیرید.</p>
            </td>
          </tr>
        </table>
      </td>
    </tr>
  </table>
</body>
</html>
HTML;
    }
}
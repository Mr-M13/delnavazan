<?php
namespace Delnavazan\Platform\Integrations\Notifications;

/**
 * Reusable Delnavazan email presentation.
 *
 * The shell is deliberately one fixed, table-based, inline-styled layout: ivory page, white card,
 * turquoise primary action, pomegranate accent rule, right-to-left Persian typography and a plain-text
 * alternative. It uses no JavaScript, no remote stylesheet and no external web font, so Gmail, Apple
 * Mail, Outlook and mobile clients render the same structure. Only an escaped, plain-text body is
 * accepted, which means a template edit can never introduce markup into a delivered message.
 */
final class DelnavazanEmailRenderer {
    public const IVORY='#FBF7EE';
    public const CARD='#FFFFFF';
    public const TURQUOISE='#0E8C8C';
    public const POMEGRANATE='#B23A48';
    public const INK='#2B2A28';
    public const MUTED='#6B665E';
    public const BORDER='#E6DFD2';
    public const SUPPORT_EMAIL='support@delnavazan.com';
    public const SITE_URL='https://delnavazan.com';

    /**
     * @param array{locale?:string,preheader?:string,cta?:array{label?:string,url?:string},logo_url?:string,footer_note?:string} $options
     * @return array{subject:string,html:string,text:string}
     */
    public static function render(string $subject,string $body,array $options=array()):array{
        $locale=NotificationCatalogue::normaliseLocale((string)($options['locale']??NotificationCatalogue::PRIMARY_LOCALE));
        $english=$locale==='en';
        $subject=self::oneLine($subject);
        $text=self::text($body);
        $paragraphs=self::paragraphs($text);
        $cta=self::cta($options['cta']??array(),$english);
        $preheader=self::oneLine((string)($options['preheader']??''));
        $logo=self::logoUrl((string)($options['logo_url']??''));
        $note=self::oneLine((string)($options['footer_note']??''));
        $brand=$english?'Delnavazan':'دلنوازان';
        $tagline=$english?'Music academy · Persian and international students':'آکادمی موسیقی · هنرجویان ایران و سراسر جهان';
        $supportLabel=$english?'Support':'پشتیبانی';
        $rights=$english?'This message was sent because of an account or class activity at Delnavazan.':'این پیام به دلیل فعالیت حساب کاربری یا کلاس شما در دلنوازان فرستاده شده است.';
        $html='<!doctype html><html dir="'.($english?'ltr':'rtl').'" lang="'.($english?'en':'fa').'"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="color-scheme" content="light only"><title>'.self::e($subject).'</title>'
            .'<style>@media only screen and (max-width:600px){.dzn-wrap{width:100%!important}.dzn-card{padding:20px!important}.dzn-title{font-size:20px!important}.dzn-cta a{display:block!important;text-align:center!important}}body{margin:0!important;padding:0!important;background:'.self::IVORY.';}</style></head>'
            .'<body style="margin:0;padding:0;background:'.self::IVORY.';">'
            .($preheader!==''?'<div style="display:none;max-height:0;overflow:hidden;opacity:0;color:transparent;">'.self::e($preheader).'</div>':'')
            .'<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background:'.self::IVORY.';padding:24px 12px;"><tr><td align="center">'
            .'<table role="presentation" class="dzn-wrap" width="600" cellpadding="0" cellspacing="0" border="0" style="width:600px;max-width:600px;background:'.self::CARD.';border:1px solid '.self::BORDER.';border-radius:14px;overflow:hidden;font-family:Tahoma,\'Segoe UI\',Arial,sans-serif;color:'.self::INK.';">'
            .'<tr><td style="padding:20px 28px;border-bottom:3px solid '.self::POMEGRANATE.';background:'.self::CARD.';">'
            .'<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"><tr><td align="'.($english?'left':'right').'" style="vertical-align:middle;">'
            .'<a href="'.self::e(self::SITE_URL).'" style="text-decoration:none;color:'.self::INK.';">'
            .($logo!==''?'<img src="'.self::e($logo).'" width="132" height="40" alt="'.self::e($brand).'" style="display:block;border:0;outline:none;max-width:132px;height:auto;">':'<span style="font-size:22px;font-weight:bold;color:'.self::TURQUOISE.';">'.self::e($brand).'</span>')
            .'</a></td><td align="'.($english?'right':'left').'" style="vertical-align:middle;font-size:12px;color:'.self::MUTED.';line-height:1.7;">'.self::e($tagline).'</td></tr></table>'
            .'</td></tr>'
            .'<tr><td class="dzn-card" style="padding:28px;">'
            .'<h1 class="dzn-title" style="margin:0 0 16px;font-size:22px;line-height:1.6;color:'.self::INK.';font-weight:bold;">'.self::e($subject).'</h1>'
            .'<div style="font-size:15px;line-height:2;color:'.self::INK.';">'.$paragraphs.'</div>';
        if($cta!==null){
            $html.='<table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin:24px 0 8px;"><tr><td class="dzn-cta" align="center" bgcolor="'.self::TURQUOISE.'" style="border-radius:8px;">'
                .'<a href="'.self::e($cta['url']).'" style="display:inline-block;padding:13px 26px;font-family:Tahoma,\'Segoe UI\',Arial,sans-serif;font-size:15px;font-weight:bold;color:#FFFFFF;text-decoration:none;border-radius:8px;">'.self::e($cta['label']).'</a></td></tr></table>'
                .'<p style="margin:8px 0 0;font-size:12px;line-height:1.8;color:'.self::MUTED.';">'.($english?'If the button does not work, copy this address into your browser:':'اگر دکمه کار نکرد، این نشانی را در مرورگر خود باز کنید:').'<br><span dir="ltr" style="word-break:break-all;">'.self::e($cta['url']).'</span></p>';
        }
        $html.='</div></td></tr>'
            .'<tr><td style="padding:18px 28px;border-top:1px solid '.self::BORDER.';background:'.self::IVORY.';font-size:12px;line-height:1.9;color:'.self::MUTED.';">'
            .'<p style="margin:0 0 6px;">'.self::e($rights).'</p>'
            .($note!==''?'<p style="margin:0 0 6px;">'.self::e($note).'</p>':'')
            .'<p style="margin:0;">'.self::e($supportLabel).': <a href="mailto:'.self::e(self::SUPPORT_EMAIL).'" style="color:'.self::TURQUOISE.';text-decoration:none;">'.self::e(self::SUPPORT_EMAIL).'</a> · <a href="'.self::e(self::SITE_URL).'" style="color:'.self::TURQUOISE.';text-decoration:none;">'.self::e($brand).'</a></p>'
            .'</td></tr></table></td></tr></table></body></html>';
        return array('subject'=>$subject,'html'=>$html,'text'=>$text);
    }

    /** The plain-text alternative: the authored body, normalised and without any markup. */
    public static function text(string $body):string{
        $body=str_replace(array("\r\n","\r"),"\n",$body);
        $body=preg_replace("/\n{3,}/","\n\n",$body);
        return trim((string)$body);
    }

    public static function oneLine(string $value):string{
        return trim(preg_replace('/\s+/u',' ',strip_tags($value)));
    }

    /** Escaped paragraphs, with single newlines preserved as line breaks inside one block. */
    private static function paragraphs(string $text):string{
        $html='';
        foreach(preg_split("/\n{2,}/",$text) as $block){
            $block=trim($block);
            if($block==='')continue;
            $html.='<p style="margin:0 0 14px;">'.nl2br(self::e($block),false).'</p>';
        }
        return $html;
    }

    /**
     * A CTA is derived from an already-authorised action URL, never from an editable template. The
     * renderer refuses a non-http(s) scheme so a template cannot smuggle a script or data URL.
     *
     * @return array{label:string,url:string}|null
     */
    private static function cta(array $cta,bool $english):?array{
        $url=trim((string)($cta['url']??''));
        if($url==='')return null;
        $scheme=strtolower((string)parse_url($url,PHP_URL_SCHEME));
        if(!in_array($scheme,array('http','https'),true))return null;
        $label=self::oneLine((string)($cta['label']??''));
        if($label==='')$label=$english?'Open Delnavazan':'رفتن به دلنوازان';
        return array('label'=>$label,'url'=>$url);
    }

    private static function logoUrl(string $override):string{
        if($override!=='')return $override;
        if(function_exists('get_theme_file_uri')){
            $uri=(string)get_theme_file_uri('assets/images/delnavazan-logo.png');
            if($uri!=='')return $uri;
        }
        if(function_exists('apply_filters'))return (string)apply_filters('dzn_email_logo_url',self::SITE_URL.'/wp-content/themes/delnavazan/assets/images/delnavazan-logo.png');
        return self::SITE_URL.'/wp-content/themes/delnavazan/assets/images/delnavazan-logo.png';
    }

    private static function e(string $value):string{
        return htmlspecialchars($value,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
    }
}

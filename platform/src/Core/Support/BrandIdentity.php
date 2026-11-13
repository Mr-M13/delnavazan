<?php
namespace Delnavazan\Platform\Core\Support;

/**
 * The single authoritative Delnavazan public brand and contact identity.
 *
 * Every customer-facing surface (website footer, portal support links, transactional email,
 * receipts, statements and onboarding copy) reads these values. The authenticated mail sender is
 * deliberately NOT defined here: provider-approved From/Return-Path configuration stays in the
 * integration settings so this public contact identity never changes mail authentication.
 */
final class BrandIdentity {
    public const PERSIAN_NAME = 'دلنوازان';
    public const ENGLISH_NAME = 'Delnavazan';

    public const PHONE_E164 = '+61413413004';
    public const PHONE_DISPLAY = '+61 413 413 004';
    public const PHONE_HREF = 'tel:+61413413004';

    public const WHATSAPP_E164 = '+61413413004';
    public const WHATSAPP_URL = 'https://wa.me/61413413004';

    public const SUPPORT_EMAIL = 'admin@delnavazan.com';
    public const SUPPORT_MAILTO = 'mailto:admin@delnavazan.com';

    /**
     * Iran-based artistic director contact, shown only on Teacher-facing surfaces and in
     * Teacher email footers. It is deliberately separate from the public support identity.
     */
    public const TEACHER_CONTACT_LABEL = 'مدیر هنری دلنوازان (ایران)';
    public const TEACHER_CONTACT_LABEL_EN = 'Delnavazan artistic director (Iran)';
    public const TEACHER_CONTACT_DISPLAY = '09101401910';
    public const TEACHER_CONTACT_E164 = '+989101401910';
    public const TEACHER_CONTACT_HREF = 'tel:+989101401910';

    public const SITE_URL = 'https://delnavazan.com';
    public const INSTAGRAM_URL = 'https://www.instagram.com/insta.delnavazan/';

    public const GREETING = 'درود';
    public const TAGLINE = 'موسیقی ایرانی؛ برای ایرانیان دور از وطن';
    public const TAGLINE_EN = 'Persian music; for Iranians away from home';

    public static function name(bool $english = false): string {
        return $english ? self::ENGLISH_NAME : self::PERSIAN_NAME;
    }

    public static function tagline(bool $english = false): string {
        return $english ? self::TAGLINE_EN : self::TAGLINE;
    }

    public static function greeting(bool $english = false): string {
        return $english ? 'Hello' : self::GREETING;
    }

    public static function supportLabel(bool $english = false): string {
        return $english ? 'Support' : 'پشتیبانی';
    }

    /** Public contact details, in the order they appear on documents and in email footers. */
    public static function contactLinks(): array {
        return array(
            'phone' => array('label' => 'تلفن', 'label_en' => 'Phone', 'display' => self::PHONE_DISPLAY, 'href' => self::PHONE_HREF),
            'whatsapp' => array('label' => 'واتساپ', 'label_en' => 'WhatsApp', 'display' => self::PHONE_DISPLAY, 'href' => self::WHATSAPP_URL),
            'email' => array('label' => 'ایمیل', 'label_en' => 'Email', 'display' => self::SUPPORT_EMAIL, 'href' => self::SUPPORT_MAILTO),
            'site' => array('label' => 'وب‌سایت', 'label_en' => 'Website', 'display' => 'delnavazan.com', 'href' => self::SITE_URL),
        );
    }

    /** The Iran artistic-director contact for Teacher-facing pages and emails. */
    public static function teacherContactLink(bool $english = false): array {
        return array(
            'label' => $english ? self::TEACHER_CONTACT_LABEL_EN : self::TEACHER_CONTACT_LABEL,
            'display' => self::TEACHER_CONTACT_DISPLAY,
            'href' => self::TEACHER_CONTACT_HREF,
        );
    }

    /** Plain-text Teacher contact line for templates and email alternatives without HTML. */
    public static function teacherContactLine(bool $english = false): string {
        $link = self::teacherContactLink($english);
        return $link['label'] . ': ' . $link['display'];
    }

    /** Plain-text contact line for templates and notifications without HTML. */
    public static function contactLine(bool $english = false): string {
        $parts = array();
        foreach (self::contactLinks() as $link) {
            $parts[] = $english ? $link['label_en'] . ': ' . $link['display'] : $link['label'] . ': ' . $link['display'];
        }
        return implode(' · ', $parts);
    }

    /** A WhatsApp deep link with a pre-filled, escaped message. */
    public static function whatsappLink(string $message = ''): string {
        $message = trim($message);
        return $message === '' ? self::WHATSAPP_URL : self::WHATSAPP_URL . '?text=' . rawurlencode($message);
    }
}

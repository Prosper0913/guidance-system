<?php
class Validator
{
    public static function required($value): bool
    {
        return isset($value) && trim((string)$value) !== '';
    }

    public static function email(string $value): bool
    {
        return filter_var($value, FILTER_VALIDATE_EMAIL) !== false;
    }

    public static function minLength(string $value, int $len): bool
    {
        return mb_strlen($value) >= $len;
    }

    public static function clean(string $value): string
    {
        return htmlspecialchars(trim($value), ENT_QUOTES, 'UTF-8');
    }

    public static function isFutureDate(string $date): bool
    {
        $today = new DateTime('today');
        try {
            $d = new DateTime($date);
        } catch (Exception $e) {
            return false;
        }
        return $d >= $today;
    }
}

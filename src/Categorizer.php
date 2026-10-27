<?php
declare(strict_types=1);

final class Categorizer
{
    public static function categorize(string $sender, string $subject, array $labels): string
    {
        $text = strtolower($sender . ' ' . $subject);

        return match (true) {
            in_array('CATEGORY_PROMOTIONS', $labels, true) => 'Promotions',
            in_array('CATEGORY_SOCIAL', $labels, true)     => 'Social',
            (bool) preg_match('/invoice|receipt|statement|payment/', $text) => 'Finance',
            (bool) preg_match('/newsletter|unsubscribe|marketing/', $text)  => 'Promotions',
            default => 'General',
        };
    }
}

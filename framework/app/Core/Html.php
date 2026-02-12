<?php
// app/Core/Html.php

namespace App\Core;

final class Html
{
    public static function e($value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    }
}

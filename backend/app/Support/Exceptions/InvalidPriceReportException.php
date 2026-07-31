<?php

declare(strict_types=1);

namespace App\Support\Exceptions;

final class InvalidPriceReportException extends DomainException
{
    public static function tooFarFromStation(int $distanceMetres, int $allowed): self
    {
        return new self(
            "You are {$distanceMetres} m from the station; reports must be filed within {$allowed} m.",
            'report_too_far',
            422,
            ['distance_m' => $distanceMetres, 'allowed_m' => $allowed],
        );
    }

    public static function priceOutOfRange(float $price, float $median, float $tolerance): self
    {
        return new self(
            sprintf('₱%.2f deviates more than %d%% from the ₱%.2f local median.', $price, (int) ($tolerance * 100), $median),
            'price_out_of_range',
            422,
            ['price' => $price, 'median' => $median, 'tolerance' => $tolerance],
        );
    }

    public static function duplicate(): self
    {
        return new self('You already reported this station within the last hour.', 'duplicate_report', 429);
    }
}

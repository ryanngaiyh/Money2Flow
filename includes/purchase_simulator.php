<?php
require_once __DIR__ . '/allowance.php';

/** Accept positive MYR amounts with at most two decimal places, without float rounding. */
function purchase_amount_cents(string $input): ?int
{
    $value = trim($input);
    if (!preg_match('/^\d+(?:\.\d{1,2})?$/D', $value)) return null;
    [$whole, $fraction] = array_pad(explode('.', $value, 2), 2, '');
    $whole = ltrim($whole, '0');
    if (strlen($whole) > 10) return null;
    $cents = (int)$whole * 100 + (int)str_pad($fraction, 2, '0');
    return $cents >= 1 && $cents <= 999999999999 ? $cents : null;
}

/** The purchase is hypothetical: this performs arithmetic only, with no writes. */
function purchase_simulate(array $plan, int $priceCents): array
{
    if (($plan['state'] ?? '') !== 'ready' || $priceCents < 1) {
        throw new InvalidArgumentException('A current allowance plan and positive purchase price are required.');
    }
    $after = allowance_calculate(
        $plan['balance_cents'] - $priceCents,
        $plan['commitments_cents'],
        $plan['reserved_cents'],
        $plan['days']
    );
    return [
        'price_cents' => $priceCents,
        'available_before_cents' => $plan['available_cents'],
        'available_after_cents' => $after['available_cents'],
        'daily_before_cents' => $plan['daily_cents'],
        'daily_after_cents' => $after['daily_cents'],
        'daily_reduction_cents' => $plan['daily_cents'] === null ? null : $plan['daily_cents'] - $after['daily_cents'],
        'days' => $plan['days'],
    ];
}

function purchase_guidance(array $result): string
{
    if ($result['available_after_cents'] < 0) {
        return 'This purchase exceeds your currently available discretionary funds by ' . currency(abs($result['available_after_cents']) / 100) . '. Review upcoming payments before deciding.';
    }
    if ($result['days'] === 0) {
        return 'Your allowance is due today, so there is no meaningful per-day comparison. Record the allowance only when it arrives.';
    }
    if ($result['available_after_cents'] === 0) {
        return 'This purchase would use all currently available discretionary funds before the next allowance.';
    }
    if ($result['daily_reduction_cents'] === 0) {
        return 'This purchase leaves less money available, although the rounded daily estimate stays the same.';
    }
    return 'This purchase would reduce your suggested daily spending from '
        . currency($result['daily_before_cents'] / 100) . ' to '
        . currency($result['daily_after_cents'] / 100) . '.';
}

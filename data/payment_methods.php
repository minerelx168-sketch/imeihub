<?php
declare(strict_types=1);

/**
 * Single source of truth for top-up payment method metadata.
 *
 * Rendered by topup.php's "All Payment Methods" panel and consumed by
 * api/topup/create.php to dispatch to the right provider.
 *
 * Field reference:
 *   id          internal identifier used in the POST body
 *   label       what the user sees
 *   provider    what backend handles the order
 *                 - "stripe"     -> Stripe Checkout (card)
 *                 - "paypal"     -> PayPal Orders v2 (native)
 *                 - "binancepay" -> Binance Pay v3 (native)
 *   currency    display currency for fee math (settle currency is USD)
 *   fee_pct     processing fee charged ON TOP of the requested amount.
 *               The user pays amount + fee% at the processor; we credit
 *               the base amount to their wallet (so the fee covers the
 *               processor's cut). 0 = no fee (e.g. Binance Pay / USDT).
 *   min_usd     minimum top-up amount
 *   max_usd     maximum top-up amount
 *   bonus_pct   optional bonus credited as a separate BONUS ledger row
 *               after a successful top-up (e.g. 0.05 = +5%)
 *   badges      short string tags shown next to the label
 *   icon        inline SVG path data for the icon column (24x24 viewBox)
 *
 * The fee is added to the amount billed by the processor (see fee_pct
 * above), not deducted from the credit. Bonus is a separate ledger row
 * credited on successful top-up (so it shows up in the dashboard as its
 * own line).
 *
 * To disable a method without removing it (provider down for
 * maintenance etc.), set 'enabled' => false.
 */

return [
    [
        'id'        => 'card',
        'label'     => 'Card',
        'provider'  => 'lemonsqueezy',
        'currency'  => 'USD',
        'fee_pct'   => 5,
        'min_usd'   => 5,
        'max_usd'   => 500,
        'bonus_pct' => 0,
        'badges'    => [],
        'enabled'   => true,
        'icon'      => 'M3 8a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2zM3 10h18M7 15h3',
    ],
    [
        'id'        => 'paypal',
        'label'     => 'PayPal',
        'provider'  => 'lemonsqueezy',
        'currency'  => 'USD',
        'fee_pct'   => 5,
        'min_usd'   => 2,
        'max_usd'   => 500,
        'bonus_pct' => 0,
        'badges'    => [],
        'enabled'   => true,
        'icon'      => 'M7 4h6.5a4.5 4.5 0 0 1 0 9H10l-1 7H5zM10 13l-1 7',
    ],
    [
        'id'        => 'binancepay',
        'label'     => 'Binance Pay (USDT)',
        'provider'  => 'binancepay',
        'currency'  => 'USDT',
        'fee_pct'   => 0,
        'min_usd'   => 1,
        'max_usd'   => 3000,
        'bonus_pct' => 0.05, // +5% credited as BONUS row on success
        'badges'    => ['+5% Bonus'],
        'enabled'   => true,
        'icon'      => 'M12 2 4 10l8 8 8-8zM4 10l8 8 8-8M12 2v16',
    ],
    [
        // Direct on-chain USDT (TRC20 / BEP-20). The button does NOT POST to
        // create.php; it routes to /topup/crypto.php, which shows the wallet
        // addresses + a TxID verify form (the amount actually sent on-chain
        // becomes the credit, so the topup.php amount picker is ignored).
        'id'        => 'crypto',
        'label'     => 'Crypto (USDT / USDC)',
        'provider'  => 'crypto',
        'currency'  => 'USDT',
        'fee_pct'   => 0,
        'min_usd'   => 1,
        'max_usd'   => 10000,
        'bonus_pct' => 0,
        'badges'    => ['TRC20 · BEP-20', 'No fee'],
        'enabled'   => true,
        'route'     => '/topup/crypto.php',
        'icon'      => 'M12 2 4 8v8l8 6 8-6V8zM12 2v20M4 8l8 6 8-6',
    ],
];

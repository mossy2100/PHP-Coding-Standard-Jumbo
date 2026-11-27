<?php

// phpcs:set PERCS31.TypeHints.CompoundType joinSplitTypes true
// phpcs:set PERCS31.TypeHints.CompoundType maxLineLength 40
class Order
{
    // Valid: on one line, too long to join, or with a comment.
    public Customer|Guest $buyer;

    public CustomerAccount
        |GuestCheckoutSession $currentBuyer;

    public Customer // The usual one.
        |Guest $seller;

    // Invalid: a split type that fits on one line, a parameter's too.
    public Customer
        |Guest $payer;

    public function pay(
        Card
        |Cash $method,
    ): void {
    }
}
// phpcs:set PERCS31.TypeHints.CompoundType joinSplitTypes false
// phpcs:set PERCS31.TypeHints.CompoundType maxLineLength 120

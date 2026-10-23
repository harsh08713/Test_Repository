<?php

namespace App\Modules\Sales\Services;

use App\Models\User;
use App\Modules\CRM\Exceptions\FieldRuleException;
use App\Modules\CRM\Models\Deal;
use App\Modules\Sales\Enums\QuotationState;
use App\Modules\Sales\Models\Quotation;

/**
 * Whether a quotation may become a sales order (Phase 12 asks before it makes one). A sales order is **not** required to come from a
 * quotation — an approved deal can be ordered without one — so this guard only applies when the order *is* made from a quotation.
 *
 * Two kinds of "no":
 *  - **Hard** — never waived: it is not the latest revision, it is draft, declined, cancelled or superseded, it is not approved, or its
 *    deal is no longer open. These mean the document is not a standing offer at all.
 *  - **Soft** — what a salesperson sometimes has to override: the customer has not formally accepted it (it is only Sent), or it has run
 *    past its valid-until date. Someone holding `quotation.convert_exception` may go ahead with a **reason**; the exception is audited
 *    on the quotation and the order carries the note.
 */
final class QuotationConversionGuard
{
    /**
     * @return array{hard: list<string>, soft: list<string>}
     */
    public function blocks(Quotation $quotation): array
    {
        $hard = [];
        $soft = [];

        if (! $quotation->isLatestRevision()) {
            $hard[] = 'A newer revision has replaced this one: order from the latest revision.';
        }

        match ($quotation->state) {
            QuotationState::Draft => $hard[] = 'The quotation has not been sent to the customer.',
            QuotationState::Declined => $hard[] = 'The customer declined this quotation.',
            QuotationState::Cancelled => $hard[] = 'This quotation was withdrawn.',
            QuotationState::Superseded => $hard[] = 'This revision has been superseded.',
            QuotationState::Sent => $soft[] = 'The customer has not accepted this quotation yet.',
            QuotationState::Expired => $soft[] = 'This quotation expired on '.$quotation->valid_until->format('d-m-Y').'.',
            QuotationState::Accepted => null,
        };

        if (! $quotation->isApproved()) {
            $hard[] = 'The quotation is not approved.';
        }

        if (in_array($quotation->state, [QuotationState::Sent, QuotationState::Accepted], true) && $quotation->isPastValidity()) {
            $soft[] = 'This quotation was valid only until '.$quotation->valid_until->format('d-m-Y').'.';
        }

        if ($quotation->deal_id !== null) {
            $deal = Deal::query()->find($quotation->deal_id);

            if ($deal === null || ! $deal->isOpen()) {
                $hard[] = 'The deal is '.($deal === null ? 'not available' : strtolower($deal->stage->label())).'.';
            }
        }

        return ['hard' => array_values(array_unique($hard)), 'soft' => array_values(array_unique($soft))];
    }

    /**
     * Clear a quotation for conversion, or say why not.
     *
     * @return string|null the note an order carries when an exception was used (the reason), or null for an ordinary conversion
     *
     * @throws FieldRuleException
     */
    public function assertConvertible(Quotation $quotation, User $actor, ?string $exceptionReason = null): ?string
    {
        $blocks = $this->blocks($quotation);

        if ($blocks['hard'] !== []) {
            throw new FieldRuleException(implode(' ', $blocks['hard']));
        }

        if ($blocks['soft'] === []) {
            return null;
        }

        $reason = trim((string) $exceptionReason);

        if (! $actor->can('quotation.convert_exception')) {
            throw new FieldRuleException(implode(' ', $blocks['soft']).' You are not allowed to make an order without that.');
        }

        if (mb_strlen($reason) < 3) {
            throw new FieldRuleException(implode(' ', $blocks['soft']).' Give the reason for ordering anyway.');
        }

        if (mb_strlen($reason) > 500) {
            throw new FieldRuleException('Keep the reason to 500 characters.');
        }

        activity('quotations')->causedBy($actor)->performedOn($quotation)->event('conversion_exception')
            ->withProperties(['reason' => $reason, 'waived' => $blocks['soft']])->log('conversion_exception');

        return $reason;
    }
}

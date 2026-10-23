<?php

namespace App\Modules\Masters\Enums;

/** Why a rate could not be found. A caller can branch on it; people read the exception's message. */
enum RateFailure: string
{
    case ItemNotAvailable = 'item_not_available';
    case LocationNotAvailable = 'location_not_available';
    case UnitNotAllowed = 'unit_not_allowed';
    case PriceListNotAvailable = 'price_list_not_available';
    case NoRate = 'no_rate';
    case NotYetEffective = 'not_yet_effective';
    case Expired = 'expired';
    case NotApproved = 'not_approved';
    case Inactive = 'inactive';
}

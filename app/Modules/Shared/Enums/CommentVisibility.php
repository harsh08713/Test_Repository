<?php

namespace App\Modules\Shared\Enums;

enum CommentVisibility: string
{
    /** Staff only. The default, and the only value the staff UI writes. */
    case Internal = 'internal';

    /** Visible to the customer on the portal (Phase 19). */
    case Customer = 'customer';
}

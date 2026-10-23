<?php

namespace App\Modules\Masters\Policies;

use App\Modules\Shared\Policies\BaseDocumentPolicy;

/**
 * One policy for State, District, Block and PinCode: they are managed together under the geography permission form.
 */
class GeographyPolicy extends BaseDocumentPolicy
{
    protected function form(): string
    {
        return 'geography';
    }
}

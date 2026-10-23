<?php

namespace Tests\Support;

use App\Modules\Shared\Contracts\ApprovableDocument;
use App\Modules\Shared\Enums\DocumentType;
use App\Modules\Shared\Exceptions\ApprovalStateException;
use App\Modules\Shared\Models\Concerns\Approvable;
use App\Modules\Shared\Models\Concerns\BelongsToLocation;
use App\Modules\Shared\Models\TenantModel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;

/**
 * Minimal approvable, branch-owned, commentable record used only by the test suite.
 * It borrows the `item` permission form, so the real seeded roles (Operations, Accounts…) apply to it.
 */
#[Fillable(['code', 'name', 'status', 'remarks'])]
#[UsePolicy(TestDocumentPolicy::class)]
class TestDocument extends TenantModel implements ApprovableDocument
{
    use Approvable, BelongsToLocation;

    protected $table = 'test_documents';

    public function approvalDocumentType(): DocumentType
    {
        return DocumentType::Item;
    }

    public function beforeSubmitForApproval(): void
    {
        if ($this->getAttribute('remarks') === 'incomplete') {
            throw ApprovalStateException::notReady('The document is not complete yet.');
        }
    }

    public function approvalUrl(): ?string
    {
        return '/test-documents/'.$this->getKey();
    }
}

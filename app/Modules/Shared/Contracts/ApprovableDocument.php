<?php

namespace App\Modules\Shared\Contracts;

use App\Modules\Shared\Enums\ApprovalStatus;
use App\Modules\Shared\Enums\DocumentType;

/**
 * A record that goes through the approval workflow. Implement it on the model and use
 * the `Approvable` trait, which supplies everything except approvalDocumentType()
 * (and the `approvals()` relation, for when a model wants to eager load its history).
 */
interface ApprovableDocument
{
    public function approvalDocumentType(): DocumentType;

    /**
     * The role that decides this document when the company has not configured approval levels for its type, or null
     * for "anyone holding `{form}.approve`". Lets a type be routed to a department by default (items → Accounts).
     */
    public function defaultApproverRole(): ?string;

    /** Throw ApprovalStateException::notReady() when prerequisites for submission are missing. */
    public function beforeSubmitForApproval(): void;

    public function approvalTitle(): string;

    public function approvalUrl(): ?string;

    /** True while the document may still be changed (draft or rejected). */
    public function isEditableByWorkflow(): bool;

    public function isPendingApproval(): bool;

    public function isApproved(): bool;

    public function approvalStatus(): ApprovalStatus;

    /** The company that owns the document (approvals must be decided by someone with access to it). */
    public function approvalCompanyId(): int;

    /**
     * Stable hash of everything an approver is signing off on. Captured at submission and re-checked at
     * approval: if it differs, the document was edited in between and must be resubmitted.
     */
    public function approvalFingerprint(): string;
}

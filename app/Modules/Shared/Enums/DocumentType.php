<?php

namespace App\Modules\Shared\Enums;

/**
 * Every record type that can go through the approval workflow and/or carry a
 * document number. The value doubles as the permission "form" key
 * (e.g. `quotation` => `quotation.approve`).
 */
enum DocumentType: string
{
    case Ledger = 'ledger';
    case Item = 'item';
    case Rate = 'rate';
    case Lead = 'lead';
    case Deal = 'deal';
    case Quotation = 'quotation';
    case SalesOrder = 'sales_order';
    case SalesInvoice = 'sales_invoice';
    case SalesReturn = 'sales_return';
    case PurchaseOrder = 'purchase_order';
    case Grn = 'grn';
    case LossIncident = 'loss_incident';
    case CorrectiveAction = 'corrective_action';
    case Budget = 'budget';
    case Campaign = 'campaign';
    case MessageTemplate = 'message_template';
    case Bom = 'bom';
    case ProductionOrder = 'production_order';
    case InspectionPlan = 'inspection_plan';
    case Inspection = 'inspection';
    case NonConformance = 'non_conformance';
    case MaintenanceOrder = 'maintenance_order';

    public function label(): string
    {
        return match ($this) {
            self::Ledger => 'Ledger',
            self::Item => 'Item',
            self::Rate => 'Rate Master',
            self::Lead => 'Lead',
            self::Deal => 'Deal',
            self::Quotation => 'Quotation',
            self::SalesOrder => 'Sales Order',
            self::SalesInvoice => 'Sales Invoice',
            self::SalesReturn => 'Sales Return / Credit Note',
            self::PurchaseOrder => 'Purchase Order',
            self::Grn => 'Goods Receipt',
            self::LossIncident => 'Loss Incident',
            self::CorrectiveAction => 'Corrective / Preventive Action',
            self::Budget => 'Budget',
            self::Campaign => 'Campaign',
            self::MessageTemplate => 'Message Template',
            self::Bom => 'Bill of Materials',
            self::ProductionOrder => 'Production Order',
            self::InspectionPlan => 'Inspection Plan',
            self::Inspection => 'Inspection',
            self::NonConformance => 'Non-conformance Report',
            self::MaintenanceOrder => 'Maintenance Work Order',
        };
    }

    /** Permission form key used for `{form}.approve`, `{form}.comment`, ... */
    public function permissionKey(): string
    {
        return $this->value;
    }

    /** Number-series prefix; null for masters that carry a user-assigned code instead of a document number. */
    public function prefix(): ?string
    {
        return match ($this) {
            self::Lead => 'LD',
            self::Deal => 'DL',
            self::Quotation => 'QT',
            self::SalesOrder => 'SO',
            self::SalesInvoice => 'SI',
            self::SalesReturn => 'SR',
            self::PurchaseOrder => 'PO',
            self::Grn => 'GRN',
            self::LossIncident => 'LI',
            self::CorrectiveAction => 'CA',
            self::Campaign => 'CP',
            self::ProductionOrder => 'MO',
            self::Inspection => 'QI',
            self::NonConformance => 'NCR',
            self::MaintenanceOrder => 'MW',
            default => null,
        };
    }

    public function isNumbered(): bool
    {
        return $this->prefix() !== null;
    }

    /** @return list<self> */
    public static function numbered(): array
    {
        return array_values(array_filter(self::cases(), fn (self $t) => $t->isNumbered()));
    }
}

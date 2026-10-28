<?php

namespace App\Modules\Shared\Services;

use App\Modules\Shared\Enums\PermissionAction;

/**
 * Catalogue of every permission "form" (a screen / record type) and the actions that apply to it,
 * plus the default grants of each seeded role. The matrix UI and RolePermissionSeeder both read
 * from here, so a new module only has to add its forms in one place.
 *
 * Permission names are `{form}.{action}`, e.g. `item.edit`, `quotation.approve`.
 *
 * There is no implicit "Super Admin bypasses everything" rule. Super Admin is an ordinary role that the
 * seeder keeps granted with *every* permission, including the explicit, audited override permissions in
 * special() — so what a Super Admin can do is visible and can be reasoned about like any other role.
 */
final class PermissionRegistry
{
    public const SUPER_ADMIN = 'Super Admin';

    /** @return array<string, array{label: string, group: string, actions: list<PermissionAction>}> */
    public static function forms(): array
    {
        $a = fn (string ...$actions) => array_map(fn (string $x) => PermissionAction::from($x), $actions);

        $crud = $a('view', 'create', 'edit', 'delete');
        $crudIo = $a('view', 'create', 'edit', 'delete', 'export', 'import');
        $approvable = $a('view', 'create', 'edit', 'delete', 'approve', 'export', 'import', 'comment', 'view_audit');
        $assignable = $a('view', 'create', 'edit', 'delete', 'approve', 'export', 'import', 'comment', 'assign', 'view_audit');

        return [
            // Administration
            'company' => ['label' => 'Company', 'group' => 'Administration', 'actions' => $a('view', 'create', 'edit', 'delete', 'export', 'view_audit')],
            'location' => ['label' => 'Locations / Branches', 'group' => 'Administration', 'actions' => [...$crudIo, PermissionAction::ViewAudit]],
            'user' => ['label' => 'Users', 'group' => 'Administration', 'actions' => $a('view', 'create', 'edit', 'delete', 'export', 'assign', 'view_audit')],
            'role' => ['label' => 'Roles & permission matrix', 'group' => 'Administration', 'actions' => $a('view', 'edit', 'view_audit')],
            'setting' => ['label' => 'Company settings', 'group' => 'Administration', 'actions' => $a('view', 'edit', 'configure', 'view_audit')],
            'audit_log' => ['label' => 'Audit log', 'group' => 'Administration', 'actions' => $a('view', 'export')],
            'sla_policy' => ['label' => 'SLA policies and holidays', 'group' => 'Administration', 'actions' => $a('view', 'create', 'edit', 'delete')],
            'integration' => ['label' => 'Integrations (IndiaMART, Just Dial)', 'group' => 'Administration', 'actions' => $a('view', 'edit', 'configure', 'view_audit')],

            // Masters
            'department' => ['label' => 'Department', 'group' => 'Masters', 'actions' => $crudIo],
            'designation' => ['label' => 'Designation', 'group' => 'Masters', 'actions' => $crudIo],
            'unit' => ['label' => 'Unit (UOM)', 'group' => 'Masters', 'actions' => $crudIo],
            'payment_method' => ['label' => 'Payment method', 'group' => 'Masters', 'actions' => $a('view', 'create', 'edit', 'delete', 'export', 'view_audit')],
            'account_group' => ['label' => 'Chart of accounts', 'group' => 'Masters', 'actions' => $a('view', 'create', 'edit', 'delete', 'export', 'view_audit')],
            'ledger' => ['label' => 'Ledger (customer / supplier / bank)', 'group' => 'Masters', 'actions' => $approvable],
            'item_group' => ['label' => 'Item group', 'group' => 'Masters', 'actions' => $a('view', 'create', 'edit', 'delete', 'export', 'import', 'view_audit')],
            'item' => ['label' => 'Item master', 'group' => 'Masters', 'actions' => $approvable],
            'price_list' => ['label' => 'Price list', 'group' => 'Masters', 'actions' => $a('view', 'create', 'edit', 'delete', 'export', 'view_audit')],
            'brand' => ['label' => 'Brand', 'group' => 'Masters', 'actions' => $a('view', 'create', 'edit', 'delete', 'export', 'view_audit')],
            'rate' => ['label' => 'Rate master', 'group' => 'Masters', 'actions' => $approvable],
            'geography' => ['label' => 'State / District / Block / Pin code', 'group' => 'Masters', 'actions' => $crudIo],
            'crm_master' => ['label' => 'Lead sources, outcomes, stages', 'group' => 'Masters', 'actions' => $a('view', 'create', 'edit', 'delete', 'configure')],

            // CRM-Marketing
            'sales_team' => ['label' => 'Sales team', 'group' => 'CRM', 'actions' => $a('view', 'create', 'edit', 'delete', 'assign')],
            'target' => ['label' => 'Sales targets', 'group' => 'CRM', 'actions' => $a('view', 'create', 'edit', 'delete', 'export', 'assign')],
            'route_plan' => ['label' => 'Route plan', 'group' => 'CRM', 'actions' => $a('view', 'create', 'edit', 'delete', 'import', 'assign')],
            'territory' => ['label' => 'Territory', 'group' => 'CRM', 'actions' => $a('view', 'create', 'edit', 'delete', 'assign')],
            'attendance' => ['label' => 'Attendance', 'group' => 'CRM', 'actions' => $a('view', 'create', 'edit', 'export')],
            'lead' => ['label' => 'Leads', 'group' => 'CRM', 'actions' => $assignable],
            'followup' => ['label' => 'Follow-ups', 'group' => 'CRM', 'actions' => $a('view', 'create', 'edit', 'delete', 'export', 'assign')],
            'deal' => ['label' => 'Deals', 'group' => 'CRM', 'actions' => $assignable],
            'task' => ['label' => 'Tasks', 'group' => 'CRM', 'actions' => $a('view', 'create', 'edit', 'comment', 'assign', 'export', 'view_audit')],

            // Sales
            'quotation' => ['label' => 'Quotations', 'group' => 'Sales', 'actions' => $assignable],
            'sales_order' => ['label' => 'Sales orders', 'group' => 'Sales', 'actions' => $approvable],
            'sales_invoice' => ['label' => 'Sales invoices', 'group' => 'Sales', 'actions' => $a('view', 'edit', 'approve', 'export', 'comment', 'view_audit')],

            'sales_return' => ['label' => 'Sales returns and credit notes', 'group' => 'Sales', 'actions' => $a('view', 'create', 'edit', 'approve', 'export', 'comment', 'view_audit')],

            // Marketing
            'campaign' => ['label' => 'Campaigns', 'group' => 'Marketing', 'actions' => $approvable],
            'message_template' => ['label' => 'Message templates', 'group' => 'Marketing', 'actions' => $approvable],
            'consent' => ['label' => 'Consent and opt-outs', 'group' => 'Marketing', 'actions' => $a('view', 'create', 'export')],
            'communication' => ['label' => 'Message log', 'group' => 'Marketing', 'actions' => $a('view', 'export')],
            'workflow' => ['label' => 'Workflow automation', 'group' => 'Marketing', 'actions' => $a('view', 'create', 'edit', 'configure', 'view_audit')],

            // Inventory and procurement
            'stock' => ['label' => 'Stock, batches and stock entries', 'group' => 'Inventory', 'actions' => $a('view', 'create', 'export', 'view_audit')],
            'purchase_order' => ['label' => 'Purchase orders', 'group' => 'Procurement', 'actions' => $a('view', 'create', 'edit', 'approve', 'export', 'comment', 'view_audit')],
            'grn' => ['label' => 'Goods receipts', 'group' => 'Procurement', 'actions' => $a('view', 'create', 'edit', 'approve', 'export', 'comment', 'view_audit')],

            // Finance
            'cost_center' => ['label' => 'Cost centres', 'group' => 'Finance', 'actions' => $a('view', 'create', 'edit', 'delete', 'export', 'view_audit')],
            'budget' => ['label' => 'Budgets', 'group' => 'Finance', 'actions' => $a('view', 'create', 'edit', 'approve', 'export', 'comment', 'view_audit')],
            'profitability' => ['label' => 'Profitability by product, brand and site', 'group' => 'Finance', 'actions' => $a('view', 'export')],
            'employee' => ['label' => 'Employees', 'group' => 'HR & workforce', 'actions' => $a('view', 'create', 'edit', 'export', 'view_audit')],
            'hr_dashboard' => ['label' => 'HR dashboard', 'group' => 'HR & workforce', 'actions' => $a('view')],
            'leave_type' => ['label' => 'Leave types', 'group' => 'HR & workforce', 'actions' => $a('view', 'create', 'edit', 'delete')],
            'leave' => ['label' => 'Leave requests (team)', 'group' => 'HR & workforce', 'actions' => $a('view', 'approve', 'export')],
            'staff_attendance' => ['label' => 'Staff attendance (team)', 'group' => 'HR & workforce', 'actions' => $a('view', 'approve', 'export')],
            'shift' => ['label' => 'Shifts', 'group' => 'HR & workforce', 'actions' => $a('view', 'create', 'edit', 'delete')],
            'recruitment' => ['label' => 'Recruitment (openings, candidates, offers, hiring)', 'group' => 'HR & workforce', 'actions' => $a('view', 'create', 'edit', 'export')],
            'review_cycle' => ['label' => 'Performance review cycles', 'group' => 'HR & workforce', 'actions' => $a('view', 'create', 'edit')],
            'performance' => ['label' => 'Performance: goals and reviews (team)', 'group' => 'HR & workforce', 'actions' => $a('view', 'edit', 'export')],
            'training' => ['label' => 'Training programs', 'group' => 'HR & workforce', 'actions' => $a('view', 'create', 'edit')],
            'checklist' => ['label' => 'Onboarding and exit lists', 'group' => 'HR & workforce', 'actions' => $a('view', 'create', 'edit', 'delete')],
            'bom' => ['label' => 'Bills of materials', 'group' => 'Production', 'actions' => $a('view', 'create', 'edit', 'approve', 'export', 'comment', 'view_audit')],
            'production_order' => ['label' => 'Production orders', 'group' => 'Production', 'actions' => $a('view', 'create', 'edit', 'export', 'comment', 'view_audit')],
            'inspection_plan' => ['label' => 'Inspection plans', 'group' => 'Quality', 'actions' => $a('view', 'create', 'edit', 'approve', 'export', 'comment', 'view_audit')],
            'inspection' => ['label' => 'Inspections', 'group' => 'Quality', 'actions' => $a('view', 'create', 'edit', 'export', 'comment', 'view_audit')],
            'non_conformance' => ['label' => 'Non-conformance reports', 'group' => 'Quality', 'actions' => $a('view', 'create', 'edit', 'export', 'comment', 'view_audit')],
            'asset' => ['label' => 'Assets', 'group' => 'Maintenance', 'actions' => $a('view', 'create', 'edit', 'export', 'comment')],
            'maintenance_plan' => ['label' => 'Preventive schedules', 'group' => 'Maintenance', 'actions' => $a('view', 'create', 'edit', 'export')],
            'maintenance_order' => ['label' => 'Maintenance work orders', 'group' => 'Maintenance', 'actions' => $a('view', 'create', 'edit', 'export', 'comment', 'view_audit')],
            'holiday' => ['label' => 'Holiday calendar', 'group' => 'HR & workforce', 'actions' => $a('view', 'create', 'edit', 'delete')],
            'analytics' => ['label' => 'Executive dashboard and analytics', 'group' => 'Finance', 'actions' => $a('view', 'export')],

            // Loss Intelligence
            'industry_template' => ['label' => 'Industry templates', 'group' => 'Loss Intelligence', 'actions' => $a('view', 'configure', 'view_audit')],
            'loss_incident' => ['label' => 'Loss incidents (report, investigate, validate figures)', 'group' => 'Loss Intelligence', 'actions' => $a('view', 'create', 'edit', 'approve', 'assign', 'export', 'comment', 'view_audit')],
            'radar_finding' => ['label' => 'Profit Leakage Radar (review and dismiss findings)', 'group' => 'Loss Intelligence', 'actions' => $a('view', 'edit')],
            'savings_measurement' => ['label' => 'Verified savings (measure; Finance verifies)', 'group' => 'Loss Intelligence', 'actions' => $a('view', 'create', 'edit', 'approve')],
            'corrective_action' => ['label' => 'Corrective and preventive actions (CAPA)', 'group' => 'Loss Intelligence', 'actions' => $a('view', 'create', 'edit', 'approve', 'export', 'comment')],

            // Reports
            'report' => ['label' => 'Reports & dashboards', 'group' => 'Reports', 'actions' => $a('view', 'export')],
        ];
    }

    /**
     * Permissions that do not fit the view/create/edit grid. The first three are the explicit,
     * audited "break-glass" rights that replace an implicit Super Admin bypass.
     *
     * @return array<string, string> permission name => description
     */
    public static function special(): array
    {
        return [
            'tenant.access_all' => 'Enter companies and branches you are not a member of (every switch is audited)',
            'approval.override' => 'Decide an approval level you were not designated for (recorded as an override)',
            'comment.moderate' => 'Delete other people\'s comments',
            'item.manage_purchase_stage' => 'Start items and fill in or change their Purchase stage: name, group, units, PO tolerance, HSN, GST rate',
            'item.manage_inventory_stage' => 'Fill in or change the Inventory stage of an item: code, aliases, stock levels per branch; submit it for approval',
            'sales.scope_all' => 'Plan the days, set targets for and see the attendance of every salesperson of the company — not only the people on the teams you manage',
            'ledger.manage_all_types' => 'Create and edit customer, bank, cash, expense and general ledgers (without it, only suppliers)',
            'ledger.view_sensitive' => 'See full bank account numbers on ledgers (everyone else sees them masked)',
            'deal.reopen' => 'Bring a lost deal (and its lead) back to work: a lost deal was a decision, so re-opening it is a right of its own',
            'sales_order.view_all' => 'See every sales order and invoice of the company, not only the ones credited to you and your team (Accounts and Operations handle them all)',
            'quotation.convert_exception' => 'Make a sales order from a quotation that has not been accepted or has run past its valid-until date (a reason is required and the exception is audited)',
            'rate.override' => 'Edit the auto-filled rate on a Quotation / Sales Order',
            'credit_limit.override' => 'Override a customer credit-limit block',
            'geofence.override' => 'Override a geofence violation',
            'sla.pause' => 'Pause and resume a running SLA clock while the customer is the one being waited for (a reason is required and every pause is audited)',
            'maintenance_order.assign' => 'Assign a work order to a technician, and work on any open work order (maintenance managers and supervisors)',
            'maintenance_order.execute' => 'Do the work on a work order: start it, tick tasks, issue spare parts from stock, log hours, complete it (on an order assigned to you or to nobody)',
            'inspection.decide' => 'Reject an inspection that failed (the lot stays on hold and a non-conformance report is raised)',
            'inspection.deviate' => 'Accept an inspection that failed, with a reason, as a deviation (the lot is released); never by the person who did the inspection',
            'non_conformance.close' => 'Close a non-conformance report once it has been decided (a critical one also needs a corrective action)',
            'production_order.release' => 'Release a production order to the shop floor, complete it (with a note when short), or cancel it before work has started',
            'production_order.execute' => 'Issue material to a released production order and report what was produced and rejected (the shop floor)',
            'employee.view_all' => 'See every employee of the company, not only yourself and the people who report to you (HR and management)',
            'employee.view_sensitive' => 'See other people’s date of birth, personal e-mail, address, emergency contacts and the papers on file (everyone sees their own)',
            'employee.manage_documents' => 'Upload and remove the papers kept on an employee’s file',
            'employee.record_exit' => 'Record an employee’s notice and last working day, and bring someone back (this also switches the person’s login off on the last day)',
            'leave.approve_all' => 'See and decide the leave of every employee, and apply on behalf of anyone (HR)',
            'leave.manage_balances' => 'Give or take away leave days by hand (opening balances, corrections, grants)',
            'staff_attendance.approve_all' => 'See and decide the attendance corrections of every employee (HR)',
            'performance.view_all' => 'See, and set goals and rate, the performance of every employee (HR)',
            'performance.finalise' => 'Make a performance review final once the manager has rated it (HR)',
            'training.enrol_team' => 'Enrol the people who report to you in training programs and record their outcomes',
            'api.access' => 'Sign in to the mobile / REST API with a device token (what each call may then do is still decided by the other permissions)',
        ];
    }

    /** @return list<string> */
    public static function allPermissionNames(): array
    {
        $names = [];

        foreach (self::forms() as $key => $form) {
            foreach ($form['actions'] as $action) {
                $names[] = $action->permission($key);
            }
        }

        return [...$names, ...array_keys(self::special())];
    }

    /** @return array<string, list<string>> form groups, in display order => form keys */
    public static function groups(): array
    {
        $groups = [];

        foreach (self::forms() as $key => $form) {
            $groups[$form['group']][] = $key;
        }

        return $groups;
    }

    /**
     * Default grants for the seeded roles. Super Admin is absent on purpose: RolePermissionSeeder keeps it
     * granted with every permission in allPermissionNames() on each run.
     *
     * @return array<string, list<string>> role name => permission names
     */
    public static function defaultRoles(): array
    {
        $grant = function (string $form, string ...$actions): array {
            return array_map(fn (string $action) => "{$form}.{$action}", $actions);
        };

        $approvableForms = ['ledger', 'item', 'rate', 'lead', 'deal', 'quotation', 'sales_order', 'sales_invoice', 'sales_return', 'purchase_order', 'grn', 'budget', 'campaign', 'message_template', 'bom', 'inspection_plan'];

        // A Company Admin runs one company: it cannot create or delete companies, reach other companies, override approvals or edit the matrix.
        $all = self::allPermissionNames();
        $companyAdmin = array_values(array_filter($all, fn (string $p) => ! str_starts_with($p, 'role.')
            && ! in_array($p, ['company.create', 'company.delete', 'tenant.access_all', 'approval.override'], true)));

        $approver = [];
        foreach ($approvableForms as $form) {
            $approver = [...$approver, ...$grant($form, 'view', 'approve', 'comment')];
        }

        return [
            'Company Admin' => $companyAdmin,

            'Purchase User' => [
                ...$grant('ledger', 'view', 'create', 'edit', 'comment'),
                ...$grant('item_group', 'view', 'create', 'edit'),
                ...$grant('item', 'view', 'create', 'edit', 'comment', 'export'), 'item.manage_purchase_stage',
                ...$grant('unit', 'view'), ...$grant('rate', 'view'), ...$grant('price_list', 'view'), ...$grant('department', 'view'),
                ...$grant('location', 'view'), ...$grant('geography', 'view'), ...$grant('payment_method', 'view'),
                ...$grant('brand', 'view', 'create', 'edit', 'export'),
                ...$grant('purchase_order', 'view', 'create', 'edit', 'export', 'comment'), ...$grant('grn', 'view'), ...$grant('stock', 'view'),
                ...$grant('loss_incident', 'view', 'create'),
            ],

            'Inventory User' => [
                ...$grant('item', 'view', 'edit', 'comment', 'export'), 'item.manage_inventory_stage',
                ...$grant('item_group', 'view'), ...$grant('unit', 'view'), ...$grant('location', 'view'),
                ...$grant('rate', 'view'), ...$grant('price_list', 'view'), ...$grant('brand', 'view'),
                ...$grant('grn', 'view', 'create', 'edit', 'export', 'comment'), ...$grant('stock', 'view', 'create', 'export'), ...$grant('purchase_order', 'view'),
                ...$grant('loss_incident', 'view', 'create', 'comment'),
            ],

            'Accounts User' => [
                ...$grant('account_group', 'view', 'create', 'edit', 'export'),
                ...$grant('ledger', 'view', 'create', 'edit', 'approve', 'export', 'comment'),
                'ledger.manage_all_types', 'ledger.view_sensitive',
                ...$grant('payment_method', 'view', 'create', 'edit'), ...$grant('geography', 'view'),
                ...$grant('item', 'view', 'approve', 'comment'), ...$grant('brand', 'view', 'create', 'edit', 'export'),
                ...$grant('rate', 'view', 'create', 'edit', 'approve', 'export'), ...$grant('price_list', 'view', 'create', 'edit', 'export'),
                ...$grant('sales_order', 'view', 'export'), 'sales_order.view_all', ...$grant('sales_invoice', 'view', 'edit', 'approve', 'export', 'comment'),
                ...$grant('sales_return', 'view', 'create', 'edit', 'approve', 'export', 'comment'),
                ...$grant('purchase_order', 'view', 'approve', 'export', 'comment'), ...$grant('grn', 'view', 'approve', 'export', 'comment'), ...$grant('stock', 'view', 'export'),
                ...$grant('loss_incident', 'view', 'export'), ...$grant('corrective_action', 'view'),
                ...$grant('analytics', 'view', 'export'), ...$grant('cost_center', 'view', 'create', 'edit', 'export'), ...$grant('budget', 'view', 'create', 'edit', 'export', 'comment'), ...$grant('profitability', 'view', 'export'),
                ...$grant('report', 'view', 'export'), ...$grant('task', 'view', 'create', 'edit', 'comment'),
                ...$grant('industry_template', 'view'),
            ],

            // Operations can look at items and annotate them, but never change them.
            'Operations User' => [
                ...$grant('leave', 'view', 'approve'), ...$grant('staff_attendance', 'view', 'approve'),
                ...$grant('performance', 'view', 'edit'), ...$grant('training', 'view'), 'training.enrol_team',
                ...$grant('item', 'view', 'comment'), ...$grant('bom', 'view'), ...$grant('production_order', 'view'), ...$grant('asset', 'view'), ...$grant('maintenance_order', 'view'),
                ...$grant('item_group', 'view'), ...$grant('unit', 'view'), ...$grant('ledger', 'view'),
                ...$grant('rate', 'view'), ...$grant('price_list', 'view'), ...$grant('sales_order', 'view'), 'sales_order.view_all', ...$grant('sales_invoice', 'view'), ...$grant('sales_return', 'view'), ...$grant('report', 'view'),
                ...$grant('task', 'view', 'create', 'edit', 'comment'),
                ...$grant('industry_template', 'view'), ...$grant('purchase_order', 'view'), ...$grant('grn', 'view'), ...$grant('stock', 'view'),
                ...$grant('loss_incident', 'view', 'create', 'comment'),
            ],

            'Sales Manager' => [
                ...$grant('employee', 'view'),
                ...$grant('leave', 'view', 'approve'), ...$grant('staff_attendance', 'view', 'approve'),
                ...$grant('performance', 'view', 'edit'), ...$grant('training', 'view'), 'training.enrol_team',
                ...$grant('lead', 'view', 'create', 'edit', 'delete', 'approve', 'export', 'import', 'comment', 'assign'),
                ...$grant('deal', 'view', 'create', 'edit', 'delete', 'approve', 'export', 'comment', 'assign'),
                ...$grant('quotation', 'view', 'create', 'edit', 'delete', 'approve', 'export', 'comment', 'assign'),
                ...$grant('sales_order', 'view', 'create', 'edit', 'delete', 'approve', 'export', 'comment'),
                ...$grant('followup', 'view', 'create', 'edit', 'delete', 'export', 'assign'),
                ...$grant('sales_team', 'view', 'create', 'edit', 'assign'), ...$grant('target', 'view', 'create', 'edit', 'export', 'assign'),
                ...$grant('route_plan', 'view', 'create', 'edit', 'import', 'assign'), ...$grant('attendance', 'view', 'export'),
                ...$grant('territory', 'view', 'create', 'edit', 'assign'),
                ...$grant('item', 'view'), ...$grant('rate', 'view'), ...$grant('price_list', 'view'), ...$grant('ledger', 'view'),
                ...$grant('sales_invoice', 'view', 'export'), ...$grant('sales_return', 'view', 'create', 'edit', 'comment'), ...$grant('report', 'view', 'export'),
                ...$grant('task', 'view', 'create', 'edit', 'comment', 'assign', 'export'), ...$grant('sla_policy', 'view'), 'sla.pause',
                ...$grant('campaign', 'view', 'create', 'edit', 'export', 'comment'), ...$grant('message_template', 'view', 'create', 'edit', 'comment'),
                ...$grant('consent', 'view', 'create', 'export'), ...$grant('communication', 'view', 'export'), ...$grant('workflow', 'view', 'create', 'edit'),
                'rate.override', 'geofence.override', 'deal.reopen', 'quotation.convert_exception', 'credit_limit.override', 'api.access',
            ],

            'BDE / Field Salesperson' => [
                ...$grant('lead', 'view', 'create', 'edit', 'comment'),
                ...$grant('followup', 'view', 'create', 'edit'),
                ...$grant('deal', 'view', 'create', 'edit'),
                ...$grant('quotation', 'view', 'create', 'edit'),
                ...$grant('sales_order', 'view', 'create', 'edit'), ...$grant('sales_invoice', 'view'), ...$grant('sales_return', 'view'), ...$grant('report', 'view'),
                ...$grant('task', 'view', 'create', 'edit', 'comment'),
                ...$grant('campaign', 'view'), ...$grant('consent', 'view', 'create'), ...$grant('communication', 'view'),
                ...$grant('route_plan', 'view'), ...$grant('attendance', 'view', 'create'), ...$grant('target', 'view'), ...$grant('territory', 'view'),
                ...$grant('item', 'view'), ...$grant('rate', 'view'), ...$grant('price_list', 'view'), ...$grant('ledger', 'view'),
                'api.access',
            ],

            'Tele-caller' => [
                ...$grant('lead', 'view', 'create', 'edit'),
                ...$grant('task', 'view', 'create', 'edit', 'comment'),
                ...$grant('followup', 'view', 'create', 'edit'),
                ...$grant('item', 'view'),
                'api.access',
            ],

            'Approver' => [...$approver, ...$grant('report', 'view')],

            // Loss management (brief section 4). The manager decides: validates figures, approves workflow moves, verifies actions.
            'Quality/Loss Manager' => [
                ...$grant('asset', 'view'), ...$grant('maintenance_order', 'view', 'create', 'comment'),
                ...$grant('inspection_plan', 'view', 'create', 'edit', 'approve', 'export', 'comment', 'view_audit'), ...$grant('inspection', 'view', 'create', 'edit', 'export', 'comment', 'view_audit'),
                ...$grant('non_conformance', 'view', 'create', 'edit', 'export', 'comment', 'view_audit'), 'inspection.decide', 'inspection.deviate', 'non_conformance.close',
                ...$grant('bom', 'view'), ...$grant('production_order', 'view', 'comment'),
                ...$grant('leave', 'view', 'approve'), ...$grant('staff_attendance', 'view', 'approve'),
                ...$grant('performance', 'view', 'edit'), ...$grant('training', 'view'), 'training.enrol_team',
                ...$grant('loss_incident', 'view', 'create', 'edit', 'approve', 'assign', 'export', 'comment', 'view_audit'), ...$grant('radar_finding', 'view', 'edit'), ...$grant('savings_measurement', 'view', 'create', 'edit'),
                ...$grant('corrective_action', 'view', 'create', 'edit', 'approve', 'export', 'comment'),
                ...$grant('analytics', 'view'), ...$grant('industry_template', 'view'), ...$grant('stock', 'view'), ...$grant('item', 'view'), ...$grant('report', 'view', 'export'),
            ],
            'Investigator' => [
                ...$grant('loss_incident', 'view', 'create', 'edit', 'comment'), ...$grant('corrective_action', 'view', 'create', 'edit', 'comment'),
                ...$grant('industry_template', 'view'), ...$grant('stock', 'view'), ...$grant('item', 'view'),
            ],
            // Finance (brief section 4): the controller approves budgets and reads everything money; Management reads the dashboards.
            'Finance Controller' => [
                ...$grant('leave', 'view', 'approve'), ...$grant('staff_attendance', 'view', 'approve'),
                ...$grant('performance', 'view', 'edit'), ...$grant('training', 'view'), 'training.enrol_team',
                ...$grant('cost_center', 'view', 'create', 'edit', 'export'), ...$grant('budget', 'view', 'create', 'edit', 'approve', 'export', 'comment', 'view_audit'),
                ...$grant('analytics', 'view', 'export'), ...$grant('profitability', 'view', 'export'), ...$grant('loss_incident', 'view', 'export'), ...$grant('corrective_action', 'view'), ...$grant('savings_measurement', 'view', 'approve'), ...$grant('report', 'view', 'export'),
                ...$grant('sales_order', 'view', 'export'), 'sales_order.view_all', ...$grant('sales_invoice', 'view', 'export'), ...$grant('sales_return', 'view', 'approve', 'export'),
                ...$grant('purchase_order', 'view', 'approve', 'export'), ...$grant('grn', 'view', 'export'), ...$grant('stock', 'view', 'export'), ...$grant('item', 'view'),
            ],
            'Management' => [
                ...$grant('asset', 'view'), ...$grant('maintenance_plan', 'view'), ...$grant('maintenance_order', 'view'),
                ...$grant('inspection_plan', 'view'), ...$grant('inspection', 'view'), ...$grant('non_conformance', 'view'),
                ...$grant('bom', 'view'), ...$grant('production_order', 'view'),
                ...$grant('leave', 'view', 'approve'), ...$grant('staff_attendance', 'view', 'approve'), ...$grant('holiday', 'view'),
                ...$grant('performance', 'view', 'edit'), ...$grant('training', 'view'), 'training.enrol_team',
                ...$grant('employee', 'view'), 'employee.view_all', ...$grant('hr_dashboard', 'view'),
                ...$grant('analytics', 'view'), ...$grant('profitability', 'view'), ...$grant('budget', 'view'), ...$grant('cost_center', 'view'), ...$grant('loss_incident', 'view'), ...$grant('corrective_action', 'view'),
                ...$grant('report', 'view'), ...$grant('industry_template', 'view'), ...$grant('stock', 'view'), 'sales_order.view_all', ...$grant('sales_order', 'view'),
            ],
            // HR (H1): runs the employee file. Holds the sensitive fields and the exit; does not approve other departments' documents.
            'HR Manager' => [
                ...$grant('employee', 'view', 'create', 'edit', 'export', 'view_audit'), 'employee.view_all', 'employee.view_sensitive', 'employee.manage_documents', 'employee.record_exit',
                ...$grant('hr_dashboard', 'view'), ...$grant('department', 'view', 'create', 'edit'), ...$grant('designation', 'view', 'create', 'edit'), ...$grant('location', 'view'),
                ...$grant('task', 'view', 'create', 'edit', 'comment'), ...$grant('report', 'view', 'export'),
                ...$grant('leave_type', 'view', 'create', 'edit', 'delete'), ...$grant('leave', 'view', 'approve', 'export'), 'leave.approve_all', 'leave.manage_balances',
                ...$grant('staff_attendance', 'view', 'approve', 'export'), 'staff_attendance.approve_all',
                ...$grant('shift', 'view', 'create', 'edit', 'delete'), ...$grant('holiday', 'view', 'create', 'edit', 'delete'), ...$grant('checklist', 'view', 'create', 'edit', 'delete'),
                ...$grant('recruitment', 'view', 'create', 'edit', 'export'), ...$grant('review_cycle', 'view', 'create', 'edit'), ...$grant('performance', 'view', 'edit', 'export'),
                'performance.view_all', 'performance.finalise', ...$grant('training', 'view', 'create', 'edit'),
            ],
            // Maintenance (M1): plans the schedules and the work, assigns it, and does it.
            'Maintenance Manager' => [
                ...$grant('asset', 'view', 'create', 'edit', 'export', 'comment'), ...$grant('maintenance_plan', 'view', 'create', 'edit', 'export'), ...$grant('maintenance_order', 'view', 'create', 'edit', 'export', 'comment', 'view_audit'),
                'maintenance_order.assign', 'maintenance_order.execute', ...$grant('item', 'view'), ...$grant('stock', 'view', 'export'), ...$grant('location', 'view'),
                ...$grant('task', 'view', 'create', 'edit', 'comment'), ...$grant('report', 'view', 'export'), ...$grant('loss_incident', 'view', 'comment'), ...$grant('cost_center', 'view'),
            ],
            // The workshop: sees the assets and schedules, reports and works on work orders assigned to them (or to nobody), takes parts from stock.
            'Maintenance Technician' => [
                ...$grant('asset', 'view'), ...$grant('maintenance_plan', 'view'), ...$grant('maintenance_order', 'view', 'create', 'comment'), 'maintenance_order.execute',
                ...$grant('item', 'view'), ...$grant('stock', 'view'), ...$grant('location', 'view'),
            ],
            // Quality (Q1): checks lots against approved plans, holds and releases batches, raises and closes non-conformances. Plans are approved by another person (maker–checker).
            'Quality Inspector' => [
                ...$grant('inspection', 'view', 'create', 'edit', 'comment'), ...$grant('inspection_plan', 'view'), ...$grant('non_conformance', 'view', 'create', 'comment'),
                ...$grant('item', 'view'), ...$grant('stock', 'view'), ...$grant('location', 'view'),
            ],
            // Production (P1): plans and runs the orders, makes the bills of materials (another person approves them: maker–checker), and sees stock.
            'Production Manager' => [
                ...$grant('asset', 'view'), ...$grant('maintenance_order', 'view', 'create', 'comment'),
                ...$grant('inspection', 'view'), ...$grant('inspection_plan', 'view'), ...$grant('non_conformance', 'view', 'create', 'comment'),
                ...$grant('bom', 'view', 'create', 'edit', 'approve', 'export', 'comment', 'view_audit'), ...$grant('production_order', 'view', 'create', 'edit', 'export', 'comment', 'view_audit'),
                'production_order.release', 'production_order.execute', ...$grant('item', 'view'), ...$grant('stock', 'view', 'export'), ...$grant('location', 'view'),
                ...$grant('task', 'view', 'create', 'edit', 'comment'), ...$grant('report', 'view', 'export'), ...$grant('loss_incident', 'view', 'create', 'comment'),
            ],
            // The shop floor: sees the orders and the bills, issues material and reports output.
            'Production Operator' => [...$grant('asset', 'view'), ...$grant('maintenance_order', 'view', 'create'), ...$grant('inspection', 'view'), ...$grant('production_order', 'view', 'comment'), 'production_order.execute', ...$grant('bom', 'view'), ...$grant('item', 'view'), ...$grant('stock', 'view')],
            // Works the actions they own (the policy lets an owner work their own); sees incidents and actions, decides nothing.
            'CAPA Owner' => [...$grant('loss_incident', 'view', 'comment'), ...$grant('corrective_action', 'view', 'comment')],

            // Operations and Accounts read the industry templates; only administrators switch them on.
            // (Company Admin already holds every non-role permission above.)
        ];
    }
}

# Data Dictionary — FormEzy + Loss Intelligence ERP

> Starting schema. Claude Code must keep this file in sync with the migrations: every new or changed table or column is updated here in the same phase.

## Conventions

| Symbol | Meaning |
|---|---|
| **[T]** | Tenant columns: `company_id` FK → companies (indexed). Add `location_id` FK → locations where noted. |
| **[M]** | Master/document columns: `code` (string 30, unique per company), `status` (enum active/inactive), `approval_status` (enum draft/pending/approved/rejected, indexed), `approved_by` FK users, `approved_at`, `remarks` (text) |
| **[A]** | Audit columns: `created_by`, `updated_by` FK users, `created_at`, `updated_at`, `deleted_at` (soft delete) |
| **[D]** | Document header: `number` (unique per company), `date`, `location_id`, `currency` (char 3), `exchange_rate` decimal(18,6), `sub_total`, `discount_total`, `tax_total`, `grand_total` decimal(18,2) |
| **[L]** | Document line: `line_no`, `item_id`, `uom_id`, `qty` decimal(18,3), `base_qty` decimal(18,3), `rate` decimal(18,4), `discount_pct` decimal(5,2), `discount_amount`, `taxable_amount`, `gst_rate` decimal(5,2), `cgst`, `sgst`, `igst`, `line_total` decimal(18,2) |

Money = decimal(18,2); unit cost/rate = decimal(18,4); quantity = decimal(18,3). Every FK is indexed. JSON columns validated in Form Requests.

---

## 1. Shared / Platform

| Table | Columns | Notes |
|---|---|---|
| `companies` | id, code, name, legal_name, gstin, pan, cin, address, state_id, pin_code, phone, email, logo_path, fy_start_month (default 4), base_currency (INR), timezone (Asia/Kolkata), costing_method (enum moving_avg/standard), settings JSON, [A] | Tenant root |
| `locations` | [T], [M], name, type (enum plant/branch/warehouse/store/site/office), address, state_id, district_id, pin_code, latitude decimal(10,7), longitude decimal(10,7), gstin, cost_per_downtime_hour, [A] | Sites/branches |
| `users` | id, name, email (unique), mobile, password, employee_code, department_id, designation_id, reports_to FK users, is_active, last_login_at, timestamps | |
| `company_user` | user_id, company_id, is_default | Company access |
| `location_user` | user_id, location_id | Site access |
| `roles`, `permissions`, `model_has_roles`, `model_has_permissions`, `role_has_permissions` | spatie/laravel-permission (teams = company_id) | |
| `number_series` | [T], location_id (nullable), document_type, fy_label (e.g. 26-27), prefix, pattern (e.g. `{PREFIX}/{LOC}/{FY}/{SEQ:5}`), next_number, [A] | Locked with `lockForUpdate` |
| `approval_rules` | [T], document_type, industry_template_id (nullable), level, approver_role, approver_user_id, min_amount, escalate_after_hours | Configurable levels |
| `approvals` | id, company_id, approvable_type, approvable_id, level, approver_role, approver_user_id, status (pending/approved/rejected/escalated), comment, acted_by, acted_at, due_at, timestamps | Polymorphic |
| `workflow_events` | id, company_id, subject_type, subject_id, from_state, to_state, action, reason, actor_id, metadata JSON, created_at | State history |
| `comments` | id, company_id, commentable_type, commentable_id, user_id, body, parent_id, timestamps | Ops comments on items |
| `attachments` | id, company_id, attachable_type, attachable_id, disk, path, original_name, mime, size, checksum, uploaded_by, timestamps | Private disk, signed URLs |
| `activity_log` | spatie/laravel-activitylog (+ company_id) | Before/after values |
| `notifications` | Laravel standard | In-app |
| `report_jobs` | [T], user_id, report_key, filters JSON, format (xlsx/pdf/csv), status (queued/running/done/failed), file_path, error, started_at, finished_at | Async exports |
| `settings` | company_id, key, value JSON (encrypted where secret) | Per-company settings |

---

## 2. Masters

| Table | Columns | Notes |
|---|---|---|
| `account_groups` | [T], code, name, parent_id, nature (asset/liability/income/expense), is_system, sort_order, [A] | Chart of Accounts tree |
| `ledgers` | [T], [M], name, ledger_type (enum customer/supplier/bank/cash/expense/general, indexed), account_group_id, gstin, pan, customer_type (enum b2b/b2c, nullable), credit_days, credit_limit, payment_mode (enum cash/cheque/neft/rtgs/upi/card), lead_time_days, bank_account_no, ifsc, bank_branch, opening_balance, opening_balance_type (dr/cr), customer_category, [A] | GSTIN required if b2b |
| `ledger_addresses` | id, ledger_id, type (billing/shipping/office), line1, line2, city, state_id, district_id, pin_code, is_default | |
| `ledger_contacts` | id, ledger_id, name, designation, mobile, email, is_primary | |
| `item_groups` | [T], [M], name, parent_id, [A] | Product categories |
| `brands` | [T], [M], name, description, logo_path, [A] | Approval required |
| `departments` | [T], [M], name, [A] | |
| `designations` | [T], [M], name, level, [A] | |
| `units` | [T], code, name, allow_decimal, [A] | UOM |
| `items` | [T], [M], name, item_group_id, brand_id, base_uom_id, hsn_code, gst_rate, po_tolerance_pct decimal(5,2), standard_cost decimal(18,4), moving_avg_cost decimal(18,4), selling_price decimal(18,4), tracking (enum none/batch/lot/serial), has_expiry, shelf_life_days, workflow_stage (enum purchase_draft/inventory_pending/approval_pending/approved/rejected, indexed), approver_department (accounts/operations), [A] | `code` assigned at Inventory stage |
| `item_uom_conversions` | id, item_id, uom_id, factor decimal(18,6) (1 uom = factor × base), is_purchase_default, is_sales_default | |
| `item_aliases` | id, item_id, alias | Indexed for search |
| `item_location_params` | id, item_id, location_id, min_stock, reorder_level, max_stock decimal(18,3) | Unique (item, location); min ≤ reorder ≤ max |
| `price_lists` | [T], [M], name, valid_from, valid_to | Optional |
| `rates` | [T], [M], item_id, location_id, uom_id, price_list_id, rate decimal(18,4), effective_from, effective_to (nullable), [A] | Index (item_id, location_id, uom_id, effective_from, approval_status) |
| `states` | id, name, code, gst_state_code | |
| `districts` | id, state_id, name | |
| `blocks` | id, district_id, name | Tent Studio import |
| `pin_codes` | id, pin_code (indexed), block_id, district_id, state_id, area_name | Tent Studio import |
| `lead_sources` | [T], code, name, is_system | Reference, Website, Social, Tele-caller, IndiaMART, Just Dial, Field, Excel, Other |
| `follow_up_outcomes` | [T], code, name, applies_to (lead/deal/quotation/any), color, is_terminal | |
| `lead_stages` | [T], code, name, sort_order, is_won, is_lost | |
| `lost_reasons` | [T], code, name | |

---

## 3. CRM

| Table | Columns | Notes |
|---|---|---|
| `sales_teams` | [T], [M], name, manager_id FK users, location_id, [A] | |
| `sales_team_members` | sales_team_id, user_id, role (manager/bde/telecaller) | |
| `sales_targets` | [T], user_id (nullable), sales_team_id (nullable), period_type (month/quarter), period_start, period_end, item_group_id, location_id, target_value, target_qty, [A] | |
| `route_plans` | [T], user_id, plan_date, status (draft/approved), geofence_radius_m, [A] | Unique (user_id, plan_date) |
| `route_plan_stops` | id, route_plan_id, sequence, stop_type (area/block/pin/customer), ref_id, name, latitude, longitude, radius_m, visited_at | |
| `attendances` | [T], user_id, date, route_plan_id (required), check_in_at, check_in_lat, check_in_lng, check_out_at, check_out_lat, check_out_lng, device_id, timestamps | Blocked without route plan |
| `geofence_violations` | [T], user_id, route_plan_id, action (lead_save/attendance), latitude, longitude, nearest_stop_id, distance_m, payload JSON, created_at | |
| `leads` | [T], [D-lite: number, date, location_id], lead_type (new/existing), ledger_id (existing customer), lead_source_id, company_name, contact_person, mobile (indexed), alt_mobile, email, address, state_id, district_id, block_id, pin_code, requirement text, expected_value, assigned_to, lead_stage_id, lost_reason_id, next_follow_up_at, latitude, longitude, route_plan_id, external_source (indiamart/justdial), external_id, is_incomplete, qualified_at, approval_status, [A] | Unique (company_id, external_source, external_id) |
| `lead_items` | id, lead_id, item_id, uom_id, qty, expected_rate, remarks | Existing-lead extra items |
| `lead_transfers` | id, lead_id, from_user_id, to_user_id, reason, transferred_by, created_at | History |
| `lead_api_logs` | [T], provider, external_id, payload JSON, status (created/duplicate/error), lead_id, error, fetched_at | |
| `lead_imports` | [T], file_path, mapping JSON, total_rows, success_rows, error_rows, error_file_path, status, user_id, timestamps | |
| `follow_ups` | [T], followable_type, followable_id (lead/deal/quotation), type (call/visit/email/whatsapp/meeting), scheduled_at, done_at, assigned_to, follow_up_outcome_id, notes, next_follow_up_at, latitude, longitude, reminder_sent_at, [A] | Calendar; index (assigned_to, scheduled_at) |
| `deals` | [T], [D], lead_id, ledger_id, prospect_name, owner_id, expected_close_date, probability_pct, stage, approval_status, [A] | |
| `deal_items` | deal_id + [L] (rate = expected rate) | |

---

## 4. Sales

| Table | Columns | Notes |
|---|---|---|
| `quotations` | [T], [D], deal_id, ledger_id, billing_address_id, shipping_address_id, place_of_supply_state_id, valid_until, revision_no, parent_quotation_id, terms text, status (draft/sent/accepted/rejected/expired), approval_status, [A] | Revisions linked by parent |
| `quotation_items` | quotation_id + [L], rate_source (rate_master/manual), rate_id, overridden_by | Override logged |
| `sales_orders` | [T], [D], source_type (deal/quotation/direct), source_id, ledger_id, billing_address_id, shipping_address_id, place_of_supply_state_id, customer_po_no, customer_po_date, delivery_date, terms, credit_check_result (ok/warned/blocked/overridden), invoice_status (none/partial/full), approval_status, [A] | |
| `sales_order_items` | sales_order_id + [L], rate_id, invoiced_qty | Enables partial invoicing |
| `sales_invoices` | [T], [D], sales_order_id, ledger_id, place_of_supply_state_id, due_date, status (posted/cancelled), approval_status, [A] | One per approved SO (v1) |
| `sales_invoice_items` | sales_invoice_id + [L], sales_order_item_id, unit_cost decimal(18,4), cost_amount decimal(18,2) | **Cost snapshot** for gross profit |
| `sales_returns` | [T], [D], sales_invoice_id, ledger_id, return_type (return/credit_note), reason, approval_status, [A] | Reduces net revenue |
| `sales_return_items` | sales_return_id + [L], sales_invoice_item_id, unit_cost, cost_amount, batch_id | |

---

## 5. Inventory & Procurement

| Table | Columns | Notes |
|---|---|---|
| `batches` | [T], item_id, batch_no, lot_no, mfg_date, expiry_date (indexed), status (active/quarantine/expired/consumed) | Unique (item, batch_no) |
| `serials` | [T], item_id, serial_no (unique per item), batch_id, location_id, status | |
| `stock_movements` | [T], location_id, item_id, batch_id, serial_id, movement_type (receipt/issue/transfer_in/transfer_out/adjustment/scrap/sale/sales_return/purchase_return), qty (signed, base UOM), unit_cost, value, source_type, source_id, moved_at, [A] | Stock ledger; index (item_id, location_id, moved_at) |
| `stock_balances` | company_id, location_id, item_id, batch_id, qty, value, avg_cost, updated_at | Derived, rebuildable |
| `purchase_orders` | [T], [D], ledger_id (supplier), expected_date, approval_status, [A] | |
| `purchase_order_items` | purchase_order_id + [L], received_qty | |
| `grns` | [T], [D], purchase_order_id, ledger_id, supplier_invoice_no, approval_status, [A] | |
| `grn_items` | grn_id + [L], purchase_order_item_id, accepted_qty, rejected_qty, batch_id | qty ≤ PO × (1 + tolerance) |

---

## 6. Industry templates

| Table | Columns | Notes |
|---|---|---|
| `industry_templates` | id, code (e.g. `automotive`), name, version, description, icon, status, is_system, timestamps | 15 seeded |
| `company_industry_templates` | company_id, industry_template_id, version_pinned, enabled_at | Enabled per company |
| `industry_settings` | company_id, industry_template_id, key, value JSON | Company overrides |
| `kpi_definitions` | id, industry_template_id (nullable = global), code, name, formula_key, numerator_desc, denominator_desc, unit, multiplier, direction (higher_better/lower_better), threshold_green, threshold_amber, threshold_red, version, is_active | See FORMULAS §5 |
| `kpi_snapshots` | company_id, location_id, industry_template_id, kpi_definition_id, kpi_version, period_type (day/week/month), period_start, dimension_type, dimension_id, value decimal(18,4), numerator, denominator, source_freshness_at, computed_at | Index (company_id, kpi_definition_id, period_start) |
| `loss_categories` | id, industry_template_id, parent_id, code, name, default_formula_key, default_severity, is_active | |
| `template_fields` | id, industry_template_id, version, key, label, type (text/number/decimal/date/datetime/select/multiselect/boolean/item/asset/batch/order), unit, options JSON, validation_rules JSON, is_required, sort_order, group | Dynamic incident fields |
| `workflow_states` | id, industry_template_id (nullable = default), code, name, is_initial, is_final, color, sort_order | |
| `workflow_transitions` | id, industry_template_id, from_state, to_state, action, required_permission, requires_comment, requires_approval | |
| `template_reports` | id, industry_template_id, report_key, name, config JSON | |

---

## 7. Loss management

| Table | Columns | Notes |
|---|---|---|
| `loss_incidents` | [T], location_id, number, industry_template_id, template_version, department_id, process, loss_category_id, item_id, asset_ref, batch_id, order_type, order_id, source_type, source_id, primary_incident_id (dup link), occurred_at, reported_at, qty, uom_id, currency, severity (low/medium/high/critical), is_safety, is_quality, is_compliance, title, description, custom_fields JSON, reporter_id, investigator_id, owner_id, state (indexed), contained_at, containment_notes, closed_at, [A] | |
| `loss_impact_lines` | id, company_id, loss_incident_id, impact_type (actual/exposure/opportunity), formula_key, formula_version, qty, unit_cost decimal(18,4), currency, amount decimal(18,2), confidence_pct, target_period, assumptions text, validation_state (pending/validated/rejected), validated_by, validated_at, superseded_at, created_by, timestamps | Never mix impact types |
| `root_cause_analyses` | id, company_id, loss_incident_id, method (five_whys/fishbone/category), cause_category (man/machine/method/material/measurement/environment/other), root_cause text, findings text, whys JSON, contributing_factors JSON, analysed_by, analysed_at, timestamps | Evidence via attachments |
| `corrective_actions` | [T], loss_incident_id, root_cause_analysis_id, number, action_type (corrective/preventive), title, description, owner_id, approver_id, due_date (indexed), expected_benefit_amount, baseline_value, status (open/in_progress/done/verified/rejected/overdue), completed_at, verified_by, verified_at, verification_notes, verified_result_amount, escalated_at, [A] | Realised savings source |

---

## 8. Finance & costing

| Table | Columns | Notes |
|---|---|---|
| `cost_centers` | [T], [M], name, parent_id, location_id, department_id, [A] | |
| `budgets` | [T], cost_center_id, account_group_id, loss_category_id, period_start, period_end, amount, version, approval_status, [A] | |
| `item_cost_history` | company_id, item_id, location_id, method, unit_cost, effective_at, source_type, source_id | Audit of cost changes |

---

## 9. Key relationships

```
companies 1─* locations 1─* (stock, docs, incidents)
items *─1 item_groups, *─1 brands, 1─* item_uom_conversions, 1─* item_location_params, 1─* rates
leads 1─* follow_ups (morph) ─► deals 1─* deal_items ─► quotations 1─* quotation_items
      ─► sales_orders 1─* sales_order_items ─► sales_invoices 1─* sales_invoice_items ─► sales_returns
industry_templates 1─* kpi_definitions, loss_categories, template_fields, workflow_states/transitions
loss_incidents 1─* loss_impact_lines, 1─* root_cause_analyses, 1─* corrective_actions, morph attachments/comments/workflow_events
```

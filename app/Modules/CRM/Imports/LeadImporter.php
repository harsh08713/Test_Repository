<?php

namespace App\Modules\CRM\Imports;

use App\Modules\Shared\Imports\CommitsInBatches;
use App\Modules\Shared\Imports\ImportColumn;
use App\Modules\Shared\Imports\Importer;
use App\Modules\Shared\Imports\ImportSession;

/**
 * Leads from a spreadsheet: one row per lead. Only Name and Mobile are required; the branch, the source and the owner
 * come from the row when it names them and otherwise from the choices made on the upload screen.
 *
 * The headings do not have to be the template's: common alternatives ("Company", "Mobile No", "Pincode"…) are
 * recognised, and anything else can be mapped on the screen before importing.
 */
final class LeadImporter implements CommitsInBatches, Importer
{
    public function key(): string
    {
        return 'leads';
    }

    /**
     * Each imported lead takes its branch's number series. Fifty leads take a second or so, which is as long as anyone
     * typing a lead into that branch at the same moment should be kept waiting.
     */
    public function commitEvery(): int
    {
        return 50;
    }

    public function label(): string
    {
        return 'Leads from a spreadsheet';
    }

    public function description(): string
    {
        return 'One row per lead. Valid rows become new leads; rows with a problem are listed with the reason and skipped, and a mobile number that is already an open lead is not imported twice.';
    }

    public function permission(): string
    {
        return 'lead.import';
    }

    public function columns(): array
    {
        return [
            new ImportColumn('lead_date', 'Lead Date', false, 'When the inquiry came in: DD-MM-YYYY or YYYY-MM-DD. Not in the future. Blank means today.', '13-10-2026', text: true, aliases: ['Date', 'Enquiry Date', 'Inquiry Date']),
            new ImportColumn('name', 'Name', true, 'The company or the person.', 'Shree Ganesh Traders', aliases: ['Company', 'Company Name', 'Customer', 'Customer Name', 'Party', 'Party Name', 'Lead Name', 'Firm']),
            new ImportColumn('contact_person', 'Contact Person', false, 'Who to speak to.', 'Meera Shah', aliases: ['Contact', 'Contact Name', 'Person', 'Person Name']),
            new ImportColumn('mobile', 'Mobile', true, 'Ten digits (a leading +91 or 0 is fine). It is how duplicates are spotted.', '9876543210', text: true, aliases: ['Mobile No', 'Mobile Number', 'Phone', 'Phone No', 'Phone Number', 'Contact No', 'Contact Number', 'Cell']),
            new ImportColumn('email', 'Email', false, 'Optional e-mail address.', 'meera@example.com', aliases: ['E-mail', 'Email ID', 'Email Address', 'Mail']),
            new ImportColumn('address', 'Address', false, 'Street address.', '12 Ashram Road', aliases: ['Address Line 1', 'Street', 'Street Address']),
            new ImportColumn('city', 'City', false, 'City or town.', 'Ahmedabad', aliases: ['Town']),
            new ImportColumn('state', 'State', false, 'Name as under Masters → Geography → States (or its 2-letter or GST code). Filled from the PIN code when left blank.', 'Gujarat'),
            new ImportColumn('district', 'District', false, 'Must exist under that state. Filled from the PIN code when left blank.', 'Ahmedabad'),
            new ImportColumn('pin_code', 'PIN Code', false, '6 digits. If it is in the geography master it fills the state, district and block.', '380009', text: true, aliases: ['Pincode', 'Pin', 'Zip', 'Zip Code', 'Postal Code']),
            new ImportColumn('source', 'Source', false, 'A lead source by name or code (Reference, Website, …). Blank uses the source chosen on the upload screen.', 'Reference', aliases: ['Lead Source']),
            new ImportColumn('requirement', 'Requirement', false, 'What they are looking for.', 'M10 hex bolts, 5,000 a month', aliases: ['Product', 'Products', 'Interested In', 'Enquiry', 'Inquiry', 'Need']),
            new ImportColumn('expected_value', 'Expected Value', false, 'Rupees, numbers only.', '250000', aliases: ['Value', 'Budget', 'Amount', 'Deal Value']),
            new ImportColumn('assigned_to', 'Assigned To', false, 'E-mail address of the salesperson who gets the lead. Blank uses the owner chosen on the upload screen.', 'bde@formezy.test', aliases: ['Owner', 'Salesperson', 'Sales Person', 'Assigned', 'Assignee']),
            new ImportColumn('branch', 'Branch', false, 'Branch code. Blank uses the branch chosen on the upload screen.', 'AHD', text: true, aliases: ['Branch Code', 'Location']),
            new ImportColumn('next_followup', 'Next Follow-up', false, 'DD-MM-YYYY, optionally with a time (HH:MM). Plans a call for the owner. Must be in the future.', '15-10-2026 11:00', text: true, aliases: ['Follow Up', 'Followup', 'Followup Date', 'Follow Up Date', 'Next Follow Up', 'Next Followup']),
            new ImportColumn('remarks', 'Remarks', false, 'Anything else worth knowing.', 'Met at the Ahmedabad trade fair', aliases: ['Notes', 'Note', 'Comment', 'Comments']),
        ];
    }

    public function rules(): array
    {
        return [
            'Row 1 holds the column headings. They need not be exactly these: common alternatives are recognised, extra columns are ignored, and on the screen you can say which of your columns feeds which field.',
            'Every row needs a Name and a Mobile number. Everything else is optional.',
            'A mobile number that is already on an open lead (any branch) is not imported again: the row is shown as “already a lead” and skipped. So uploading the same file twice adds nothing the second time.',
            'The same mobile number twice in one file is a mistake in the file: the second row is rejected.',
            'Branch, Source and Assigned To are taken from the row when filled in, and otherwise from what you chose when uploading. You can assign only to yourself and to people on the teams you manage, and only in branches you work in.',
            'A PIN code that is in the geography master fills the state, district and block. A state or district that contradicts it is rejected.',
            'A Next Follow-up plans a call for the lead’s owner. A date without a time is planned for 10:00.',
            'Rows with problems are skipped and listed in an error report with the reason; every other row is imported. Correct the listed rows and upload them again.',
            'The check shows exactly what would happen. Nothing is saved until you confirm.',
        ];
    }

    public function examples(): array
    {
        return [
            ['lead_date' => '13-10-2026', 'name' => 'Shree Ganesh Traders', 'contact_person' => 'Meera Shah', 'mobile' => '9876543210', 'email' => 'meera@example.com', 'address' => '12 Ashram Road', 'city' => 'Ahmedabad', 'state' => 'Gujarat', 'district' => 'Ahmedabad', 'pin_code' => '380009', 'source' => 'Reference', 'requirement' => 'M10 hex bolts, 5,000 a month', 'expected_value' => '250000', 'assigned_to' => 'bde@formezy.test', 'branch' => 'AHD', 'next_followup' => '15-10-2026 11:00', 'remarks' => 'Met at the trade fair'],
            ['lead_date' => '', 'name' => 'Rajesh Patel', 'contact_person' => '', 'mobile' => '+91 98250 12345', 'email' => '', 'address' => '', 'city' => 'Surat', 'state' => '', 'district' => '', 'pin_code' => '395003', 'source' => '', 'requirement' => 'HDPE granules', 'expected_value' => '', 'assigned_to' => '', 'branch' => '', 'next_followup' => '', 'remarks' => ''],
        ];
    }

    public function counterLabels(): array
    {
        return [
            'already_leads' => 'Already an open lead (skipped)',
            'followups_planned' => 'Follow-ups planned',
            'geography_filled' => 'Addresses completed from the PIN code',
        ];
    }

    public function begin(int $companyId, ?int $userId = null, array $options = []): ImportSession
    {
        return new LeadImportSession($companyId, $userId, $options);
    }
}

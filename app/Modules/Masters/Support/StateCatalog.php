<?php

namespace App\Modules\Masters\Support;

/**
 * The 36 states and union territories of India with their GST state codes. Used to seed each company's
 * State master and to populate state pickers before a company has any master data of its own.
 */
final class StateCatalog
{
    /** @return list<array{gst_code: string, code: string, name: string}> */
    public static function all(): array
    {
        return [
            ['gst_code' => '01', 'code' => 'JK', 'name' => 'Jammu & Kashmir'],
            ['gst_code' => '02', 'code' => 'HP', 'name' => 'Himachal Pradesh'],
            ['gst_code' => '03', 'code' => 'PB', 'name' => 'Punjab'],
            ['gst_code' => '04', 'code' => 'CH', 'name' => 'Chandigarh'],
            ['gst_code' => '05', 'code' => 'UK', 'name' => 'Uttarakhand'],
            ['gst_code' => '06', 'code' => 'HR', 'name' => 'Haryana'],
            ['gst_code' => '07', 'code' => 'DL', 'name' => 'Delhi'],
            ['gst_code' => '08', 'code' => 'RJ', 'name' => 'Rajasthan'],
            ['gst_code' => '09', 'code' => 'UP', 'name' => 'Uttar Pradesh'],
            ['gst_code' => '10', 'code' => 'BR', 'name' => 'Bihar'],
            ['gst_code' => '11', 'code' => 'SK', 'name' => 'Sikkim'],
            ['gst_code' => '12', 'code' => 'AR', 'name' => 'Arunachal Pradesh'],
            ['gst_code' => '13', 'code' => 'NL', 'name' => 'Nagaland'],
            ['gst_code' => '14', 'code' => 'MN', 'name' => 'Manipur'],
            ['gst_code' => '15', 'code' => 'MZ', 'name' => 'Mizoram'],
            ['gst_code' => '16', 'code' => 'TR', 'name' => 'Tripura'],
            ['gst_code' => '17', 'code' => 'ML', 'name' => 'Meghalaya'],
            ['gst_code' => '18', 'code' => 'AS', 'name' => 'Assam'],
            ['gst_code' => '19', 'code' => 'WB', 'name' => 'West Bengal'],
            ['gst_code' => '20', 'code' => 'JH', 'name' => 'Jharkhand'],
            ['gst_code' => '21', 'code' => 'OD', 'name' => 'Odisha'],
            ['gst_code' => '22', 'code' => 'CG', 'name' => 'Chhattisgarh'],
            ['gst_code' => '23', 'code' => 'MP', 'name' => 'Madhya Pradesh'],
            ['gst_code' => '24', 'code' => 'GJ', 'name' => 'Gujarat'],
            ['gst_code' => '26', 'code' => 'DD', 'name' => 'Dadra & Nagar Haveli and Daman & Diu'],
            ['gst_code' => '27', 'code' => 'MH', 'name' => 'Maharashtra'],
            ['gst_code' => '29', 'code' => 'KA', 'name' => 'Karnataka'],
            ['gst_code' => '30', 'code' => 'GA', 'name' => 'Goa'],
            ['gst_code' => '31', 'code' => 'LD', 'name' => 'Lakshadweep'],
            ['gst_code' => '32', 'code' => 'KL', 'name' => 'Kerala'],
            ['gst_code' => '33', 'code' => 'TN', 'name' => 'Tamil Nadu'],
            ['gst_code' => '34', 'code' => 'PY', 'name' => 'Puducherry'],
            ['gst_code' => '35', 'code' => 'AN', 'name' => 'Andaman & Nicobar Islands'],
            ['gst_code' => '36', 'code' => 'TS', 'name' => 'Telangana'],
            ['gst_code' => '37', 'code' => 'AP', 'name' => 'Andhra Pradesh'],
            ['gst_code' => '38', 'code' => 'LA', 'name' => 'Ladakh'],
        ];
    }

    /** @return array{gst_code: string, code: string, name: string}|null */
    public static function findByGstCode(string $gstCode): ?array
    {
        foreach (self::all() as $state) {
            if ($state['gst_code'] === $gstCode) {
                return $state;
            }
        }

        return null;
    }

    /** @return array{gst_code: string, code: string, name: string}|null */
    public static function findByName(string $name): ?array
    {
        foreach (self::all() as $state) {
            if (strcasecmp($state['name'], trim($name)) === 0) {
                return $state;
            }
        }

        return null;
    }
}

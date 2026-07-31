<?php

namespace Database\Seeders;

use App\Models\BusinessUnit;
use App\Models\Department;
use App\Models\Location;
use App\Models\Vendor;
use Illuminate\Database\Seeder;

class MasterDataSeeder extends Seeder
{
    public function run(): void
    {
        $businessUnits = ['GIL', 'GGL', 'GGH'];
        $departments = ['Warehouse', 'Yard', 'Freight forwarding', 'Custom clearance', 'Land transportation', 'Service center'];
        $locations = ['Head Office', 'Dubai', 'Abu Dhabi', 'Sharjah', 'Jebel Ali', 'Warehouse'];

        foreach ($businessUnits as $name) {
            BusinessUnit::firstOrCreate(['name' => $name]);
        }

        foreach ($departments as $name) {
            Department::firstOrCreate(['name' => $name]);
        }

        foreach ($locations as $name) {
            Location::firstOrCreate(['name' => $name]);
        }

        $vendors = [
            'Al Noor Logistics', 'City Power & Water', 'Delta Freight Co',
            'Emirates Office Supplies', 'Falcon Facility Services', 'Gulf Fresh Trading LLC',
            'Horizon Marketing Group', 'Metro Print House', 'Oasis IT Distribution',
            'Prime Legal Consultants', 'Star Maintenance Works', 'TechBridge Solutions FZE',
        ];

        foreach ($vendors as $name) {
            Vendor::firstOrCreate(['name' => $name]);
        }
    }
}
